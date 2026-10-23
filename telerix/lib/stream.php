<?php
/*
 * Internetradio-Stream (Icecast), der Anrufern als Wartemusik vorgespielt wird.
 *
 * Ersetzt set_stream.sh/get_stream.sh (sed auf Zeile 3 von stream.sh) und
 * show_internetstreams.sh. Die URL steht in einer eigenen Datei, die
 * asterisk/stream.sh beim Start jedes Wartemusik-Prozesses liest.
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function stream_url_file(): string
{
   return data_path('asterisk/stream.url');
}

function stream_load(): string
{
   $url = @file_get_contents(stream_url_file());
   return $url === false ? '' : trim($url);
}

/*
 * Prüft und vereinheitlicht eine eingegebene Stream-Adresse.
 * Ohne Schema wird http:// ergänzt (wie die alten Beispiele
 * "www.beispiel.de:8000/stream"). Liefert null bei ungültiger Eingabe.
 */
function stream_normalize(string $in): ?string
{
   $in = trim($in);
   if ($in === '') {
      return '';
   }
   if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $in)) {
      $in = 'http://' . $in;
   }
   if (strlen($in) > 500 || preg_match('/[\s"\'\\\\<>`]/', $in)) {
      return null;
   }
   if (filter_var($in, FILTER_VALIDATE_URL) === false) {
      return null;
   }
   $p = parse_url($in);
   if (!in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host']) || isset($p['user'])) {
      return null;
   }
   return $in;
}

function stream_save(string $url): void
{
   atomic_write(stream_url_file(), $url === '' ? '' : $url . "\n", 0640);
}

/* ---------- Qualität des Streams (Bitrate, Abtastrate, Kanäle) ---------- */

/* So lange gilt eine Messung; jede öffnet den Stream kurz (ein Zuhörer mehr) */
const STREAM_INFO_CACHE_SECONDS = 900;
/* Darunter ein Hinweis, dass eine höhere Bitrate am Telefon besser klingt */
const STREAM_INFO_MIN_KBPS = 128;

function stream_info_cache_file(): string
{
   return data_path('state/stream_info.json');
}

/*
 * Format des Streams laut mpg123 (dekodiert einige Frames, ohne Ausgabe):
 *   ['bitrate' => kbit/s oder null (VBR), 'mode' => 'cbr'|'vbr'|'abr',
 *    'rate' => Hz, 'channels' => Text, 'layer' => 'I'|'II'|'III']
 * oder ['error' => Meldung]. STREAM_INFO_CACHE_SECONDS zwischengespeichert.
 */
function stream_info(string $url): array
{
   $cache = json_decode((string)@file_get_contents(stream_info_cache_file()), true);
   if (is_array($cache) && ($cache['url'] ?? null) === $url && is_array($cache['info'] ?? null)
         && time() - (int)($cache['checked'] ?? 0) < STREAM_INFO_CACHE_SECONDS) {
      return $cache['info'];
   }
   require_once __DIR__ . '/asterisk.php';
   [, , $err] = run_command(['mpg123', '-t', '-n', '20', '-v', '--', $url], 10);
   if (preg_match('/^MPEG (?:[0-9.]+) L (I{1,3}) (cbr|vbr|abr)([0-9]*) ([0-9]+) (\S+)/m', $err, $m)) {
      $info = [
         'bitrate' => $m[3] === '' ? null : (int)$m[3],
         'mode' => $m[2],
         'rate' => (int)$m[4],
         'channels' => match ($m[5]) {
            'mono' => 'Mono',
            'stereo' => 'Stereo',
            'j-s' => 'Stereo (Joint Stereo)',
            'dual' => 'Zweikanal',
            default => $m[5],
         },
         'layer' => $m[1],
      ];
   } else {
      $info = ['error' => 'Stream nicht abrufbar oder kein MP3.'];
   }
   atomic_write(stream_info_cache_file(), json_encode(['url' => $url, 'checked' => time(), 'info' => $info]), 0600);
   return $info;
}

/* Kurzbeschreibung, z. B. "MP3 128 kbit/s, 44,1 kHz, Stereo" */
function stream_info_text(array $info): string
{
   if (isset($info['error'])) {
      return $info['error'];
   }
   $codec = $info['layer'] === 'III' ? 'MP3' : 'MPEG Layer ' . $info['layer'];
   $rate = $info['mode'] === 'cbr' ? $info['bitrate'] . ' kbit/s'
      : ($info['bitrate'] !== null ? 'etwa ' . $info['bitrate'] . ' kbit/s' : 'variable Bitrate');
   $khz = str_replace('.', ',', rtrim(rtrim(sprintf('%.1f', $info['rate'] / 1000), '0'), '.'));
   return "$codec $rate, $khz kHz, {$info['channels']}";
}

/* Bitrate bekannt und unter STREAM_INFO_MIN_KBPS */
function stream_info_low(array $info): bool
{
   return !isset($info['error']) && $info['bitrate'] !== null && $info['bitrate'] < STREAM_INFO_MIN_KBPS;
}

/* ---------- Icecast-Status für die Startseite ---------- */

/* Anrufer und Icecast-Status auf der Startseite so oft neu laden (Sekunden) */
const LIVE_REFRESH_SECONDS = 60;

/* Ergebnis zwischenspeichern, damit die Startseite nicht bei jedem Aufruf
   bis zu 3 s auf einen langsamen oder fehlenden Server wartet. */
const ICECAST_CACHE_SECONDS = 30;
const ICECAST_TIMEOUT = 3;

function icecast_cache_file(): string
{
   return data_path('state/icecast_status.json');
}

/*
 * Mögliche Adressen der Statusseite (status-json.xsl, ab Icecast 2.4).
 * Eingetragen: nur diese. Sonst aus der Stream-Adresse: zuerst im
 * Verzeichnis des Streams (AzuraCast: https://server/listen/<station>/),
 * dann in der Wurzel des Servers (Icecast direkt, z. B. http://server:8000/).
 */
function icecast_status_candidates(string $streamUrl, string $custom): array
{
   if ($custom !== '') {
      return [$custom];
   }
   $p = parse_url($streamUrl);
   if (!$p || empty($p['host']) || empty($p['scheme'])) {
      return [];
   }
   $base = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
   $dir = rtrim(str_replace('\\', '/', dirname($p['path'] ?? '/')), '/.');
   $c = $dir !== '' ? [$base . $dir . '/status-json.xsl'] : [];
   $c[] = $base . '/status-json.xsl';
   return $c;
}

/* Eine Statusseite abrufen. Liefert [icestats-Array oder null, Fehlermeldung oder null]. */
function icecast_fetch(string $url): array
{
   $ctx = stream_context_create(['http' => [
      'timeout' => ICECAST_TIMEOUT,
      'follow_location' => 0,
      'ignore_errors' => true,
      'header' => "User-Agent: Telerix\r\n",
   ]]);
   $raw = @file_get_contents($url, false, $ctx, 0, 1024 * 1024);
   $status = 0;
   $location = '';
   foreach ($http_response_header ?? [] as $h) {
      if (preg_match('#^HTTP/\S+ (\d{3})#', $h, $m)) {
         $status = (int)$m[1];
      } elseif (preg_match('#^Location:\s*(\S+)#i', $h, $m)) {
         $location = $m[1];
      }
   }
   if ($status === 0) {
      return [null, 'Server nicht erreichbar (keine Antwort innerhalb von ' . ICECAST_TIMEOUT . ' s oder Verbindung abgelehnt).'];
   }
   if ($status >= 300 && $status < 400) {
      return [null, 'Der Server leitet weiter' . ($location !== '' ? ' nach ' . $location : '')
         . '. Diese Adresse als Icecast-Statusadresse eintragen.'];
   }
   if ($status !== 200) {
      return [null, "Keine Icecast-Statusseite (HTTP $status)."];
   }
   $data = json_decode((string)$raw, true);
   if (!is_array($data['icestats'] ?? null)) {
      return [null, 'Die Antwort ist kein Icecast-Status (status-json.xsl gibt es ab Icecast 2.4).'];
   }
   return [$data['icestats'], null];
}

/*
 * Status für die Startseite, ICECAST_CACHE_SECONDS zwischengespeichert:
 *   url    abgefragte Adresse(n)
 *   error  Meldung oder null
 *   rows   Liste von ['mount', 'listeners', 'peak', 'url']
 *   own    true, wenn nur der eigene Mountpoint gezeigt wird (gefunden über
 *          den Pfad der Stream-Adresse); sonst alle Mountpoints des Servers
 */
function icecast_status(string $streamUrl, string $custom): array
{
   $key = $streamUrl . '|' . $custom;
   $cache = json_decode((string)@file_get_contents(icecast_cache_file()), true);
   if (is_array($cache) && ($cache['key'] ?? null) === $key && is_array($cache['status'] ?? null)
         && time() - (int)($cache['checked'] ?? 0) < ICECAST_CACHE_SECONDS) {
      return $cache['status'];
   }
   $status = icecast_status_fetch($streamUrl, $custom);
   atomic_write(icecast_cache_file(), json_encode(['key' => $key, 'checked' => time(), 'status' => $status]), 0600);
   return $status;
}

function icecast_status_fetch(string $streamUrl, string $custom): array
{
   $tried = icecast_status_candidates($streamUrl, $custom);
   $status = ['url' => implode(', ', $tried), 'error' => 'Keine Adresse für den Icecast-Status.', 'rows' => [], 'own' => false];
   foreach ($tried as $url) {
      [$stats, $error] = icecast_fetch($url);
      if ($stats !== null) {
         $status = ['url' => $url, 'error' => null] + icecast_rows($stats, $streamUrl);
         break;
      }
      $status['error'] = $error;
   }
   return $status;
}

/* Mountpoints aus icestats; nur der eigene, wenn er sich finden lässt. */
function icecast_rows(array $stats, string $streamUrl): array
{
   $sources = $stats['source'] ?? [];
   if (!is_array($sources)) {
      $sources = [];
   }
   /* Bei nur einer Quelle liefert Icecast ein Objekt statt einer Liste. */
   if (isset($sources['listenurl'])) {
      $sources = [$sources];
   }
   $streamPath = (string)parse_url($streamUrl, PHP_URL_PATH);
   $rows = [];
   $own = [];
   foreach ($sources as $s) {
      if (!is_array($s) || !is_string($s['listenurl'] ?? null)) {
         continue;
      }
      $url = $s['listenurl'];
      $path = (string)parse_url($url, PHP_URL_PATH);
      $row = [
         'mount' => basename($path),
         'listeners' => (int)($s['listeners'] ?? 0),
         'peak' => (int)($s['listener_peak'] ?? 0),
         'url' => preg_match('#^https?://#i', $url) ? $url : '',
      ];
      $rows[] = $row;
      /* Eigener Mountpoint: Pfad der Stream-Adresse endet auf seinen Pfad
         (AzuraCast reicht z. B. /listen/<station>/radio.mp3 an /radio.mp3 durch) */
      if ($path !== '' && $path !== '/' && str_ends_with($streamPath, $path)) {
         $own[] = $row;
      }
   }
   return $own ? ['rows' => $own, 'own' => true] : ['rows' => $rows, 'own' => false];
}
