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

/*
 * Fragt die Icecast-Statusseite (status-json.xsl) auf dem Server der
 * Stream-URL ab. Liefert eine Liste von
 * ['mount' => ..., 'listeners' => ..., 'peak' => ..., 'url' => ...]
 * oder null, wenn der Server nicht erreichbar ist.
 */
function icecast_status(string $streamUrl): ?array
{
   $p = parse_url($streamUrl);
   if (!$p || empty($p['host'])) {
      return null;
   }
   $base = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
   $ctx = stream_context_create(['http' => [
      'timeout' => 3,
      'follow_location' => 0,
      'ignore_errors' => false,
      'header' => "User-Agent: Telerix\r\n",
   ]]);
   $raw = @file_get_contents($base . '/status-json.xsl', false, $ctx, 0, 1024 * 1024);
   if ($raw === false) {
      return null;
   }
   $data = json_decode($raw, true);
   $sources = $data['icestats']['source'] ?? [];
   if (!is_array($sources)) {
      return [];
   }
   /* Bei nur einer Quelle liefert Icecast ein Objekt statt einer Liste. */
   if (isset($sources['listenurl'])) {
      $sources = [$sources];
   }
   $rows = [];
   foreach ($sources as $s) {
      if (!is_array($s) || !is_string($s['listenurl'] ?? null)) {
         continue;
      }
      $url = $s['listenurl'];
      $rows[] = [
         'mount' => basename((string)parse_url($url, PHP_URL_PATH)),
         'listeners' => (int)($s['listeners'] ?? 0),
         'peak' => (int)($s['listener_peak'] ?? 0),
         'url' => preg_match('#^https?://#i', $url) ? $url : '',
      ];
   }
   return $rows;
}
