<?php
/*
 * Begrüßungsansage: eigene Audiodatei hochladen, die Anrufern statt der
 * mitgelieferten Ansage (tx/willkommen-gottesdienst) vorgespielt wird.
 *
 * Dateien:
 *   uploads/audio/<zufall>.<endung>  das hochgeladene Original
 *   uploads/audio/quelle.wav         daraus Mono, 16 kHz (8 kHz, wenn das
 *                                    Original nicht mehr hergibt), sonst
 *                                    unbearbeitet; Grundlage für alles
 *                                    Weitere und Inhalt der Sicherung
 *   sounds/ansage.wav16              16 kHz für HD-Anrufer (G.722)
 *   sounds/ansage.wav                8 kHz für Anrufer mit G.711
 *   sounds/ansage.stand              womit die beiden erzeugt wurden
 *
 * Asterisk spielt "ansage" ab und wählt je Anrufer die Datei mit dem
 * kürzesten Weg: G.722 bekommt 16 kHz, G.711 8 kHz, beide ohne Umrechnung
 * in Asterisk. Beide entstehen mit sox "rate -v" aus der Quelle.
 *
 * Aufbereitung wie beim Stream (Hochpass und Präsenz aus den Einstellungen),
 * dann auf einen festen Sprachpegel (ANNOUNCEMENT_LEVEL_DB, gemessen ohne
 * Pausen) und mit dem Begrenzer auf etwa -3,5 dBFS. So kommen Ansage und
 * Stream etwa gleich laut an. Ist die Tonaufbereitung abgeschaltet, wird
 * nur umgewandelt. Neu erzeugt wird beim Hochladen, beim Speichern der
 * Toneinstellungen und beim Containerstart (lib/settings.php,
 * settings_render). Installationen von vor 0.06-mod mit nur einer
 * 8-kHz-ansage.wav bekommen so beim Start die neuen Dateien aus dem
 * Original; ohne Original bleibt die alte Datei in Gebrauch.
 */
declare(strict_types=1);

require_once __DIR__ . '/upload.php';
require_once __DIR__ . '/asterisk.php';
require_once __DIR__ . '/settings.php';

const AUDIO_MAX_BYTES = 20 * 1024 * 1024;
/* Längere Ansagen werden abgeschnitten. */
const AUDIO_MAX_SECONDS = 600;

/*
 * Sprachpegel der Ansage in dBFS RMS, gemessen ohne Pausen (Blöcke von
 * 400 ms, leiser als 10 dB unter dem Mittel zählen nicht, wie bei der
 * Lautheitsmessung nach EBU R 128, aber ohne Frequenzbewertung). Gewählt
 * nach docs/tonqualitaet.md: Ein echter Gemeinde-Stream lag bei -22 LUFS,
 * das Sprach-Testsignal des SIP-Tests (-26,5 dBFS mit Pausen) auf dieselbe
 * Weise gemessen bei -25,4 dBFS. -23 dBFS liegt dazwischen und in der Mitte
 * des Zielbereichs für Sprache am Telefon (-26 bis -20 dBFS).
 */
const ANNOUNCEMENT_LEVEL_DB = -23.0;
/* Höchstens so viel anheben oder absenken (sehr leise Aufnahmen: Rauschen) */
const ANNOUNCEMENT_MAX_GAIN_DB = 20.0;
/* Wie in asterisk/stream.sh: Begrenzer, Reserve, Präsenzfilter */
const ANNOUNCEMENT_CEILING_DB = -3.5;
const ANNOUNCEMENT_RESERVE_DB = 3;
const ANNOUNCEMENT_PRESENCE = ['2800', '0.9q'];
/* Ändert sich das Verfahren, hier hochzählen: alle Ansagen werden neu erzeugt */
const ANNOUNCEMENT_VERSION = 1;

const AUDIO_ALLOWED = [
   'wav'  => ['audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave'],
   'mp3'  => ['audio/mpeg', 'audio/mp3', 'audio/x-mpeg'],
   'ogg'  => ['audio/ogg', 'application/ogg', 'audio/vorbis', 'audio/x-vorbis+ogg'],
   'flac' => ['audio/flac', 'audio/x-flac'],
];

function audio_upload_dir(): string
{
   return data_path('uploads/audio');
}

/* 8 kHz (G.711); bis 0.05-mod die einzige Datei */
function announcement_file(): string
{
   return data_path('sounds/ansage.wav');
}

/* 16 kHz (G.722) */
function announcement_hd_file(): string
{
   return data_path('sounds/ansage.wav16');
}

function announcement_stamp_file(): string
{
   return data_path('sounds/ansage.stand');
}

function announcement_source_file(): string
{
   return audio_upload_dir() . '/quelle.wav';
}

function announcement_exists(): bool
{
   return is_file(announcement_hd_file()) || is_file(announcement_file());
}

/* Zuletzt hochgeladenes Original oder null */
function announcement_original(): ?string
{
   $files = array_filter(glob(audio_upload_dir() . '/*') ?: [],
      fn($f) => $f !== announcement_source_file() && preg_match('/\/[0-9a-f]{32}\.[a-z0-9]+$/', $f) === 1);
   usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
   return $files[0] ?? null;
}

/* Abtastrate einer Audiodatei oder 0 */
function audio_rate(string $file): int
{
   [$code, $out] = run_command(['sox', '--i', '-r', $file], 30);
   return $code === 0 ? (int)trim($out) : 0;
}

/* sox mit Argument-Array; false bei Fehler (Meldung im Protokoll) */
function audio_sox(array $args, string $what): bool
{
   [$code, , $err] = run_command(array_merge(['sox', '--no-show-progress'], $args), 120);
   if ($code !== 0) {
      error_log("telerix: $what, sox fehlgeschlagen: " . trim($err));
      return false;
   }
   return true;
}

/*
 * Erzeugt die Quelle aus einer Audiodatei: Mono, 16 kHz (8 kHz, wenn die
 * Datei weniger als 16 kHz hat; nichts wird künstlich hochgerechnet),
 * höchstens AUDIO_MAX_SECONDS. -G verhindert Übersteuern beim Umrechnen.
 */
function announcement_make_source(string $file): bool
{
   $rate = audio_rate($file) >= 16000 ? 16000 : 8000;
   $tmp = audio_upload_dir() . '/.quelle-' . bin2hex(random_bytes(8)) . '.wav';
   $ok = audio_sox(['-G', $file, '-b', '16', '-e', 'signed-integer', '-t', 'wav', $tmp,
      'remix', '-', 'trim', '0', (string)AUDIO_MAX_SECONDS, 'rate', '-v', (string)$rate], 'Ansage umwandeln')
      && is_file($tmp) && filesize($tmp) > 44 && chmod($tmp, 0600) && rename($tmp, announcement_source_file());
   @unlink($tmp);
   return $ok;
}

/*
 * Sprachpegel in dBFS RMS ohne Pausen (siehe ANNOUNCEMENT_LEVEL_DB) nach
 * den angegebenen sox-Effekten, oder null bei Stille. Die Effekte sollten
 * eine Reserve enthalten, damit nichts abgeschnitten wird.
 */
function audio_speech_level(string $file, int $rate, array $effects): ?float
{
   $raw = data_path('state/.pegel-' . bin2hex(random_bytes(8)) . '.f32');
   try {
      if (!audio_sox(array_merge([$file, '-t', 'f32', $raw], $effects), 'Pegel messen')) {
         return null;
      }
      $fh = fopen($raw, 'rb');
      $block = (int)(0.4 * $rate);
      $energies = [];
      while (($data = fread($fh, $block * 4)) !== false && strlen($data) === $block * 4) {
         $sum = 0.0;
         foreach (unpack('g*', $data) as $x) {
            $sum += $x * $x;
         }
         $energies[] = $sum / $block;
      }
      fclose($fh);
   } finally {
      @unlink($raw);
   }
   /* Absolut: unter -70 dBFS ist Stille; relativ: 10 dB unter dem Mittel */
   $gated = array_filter($energies, fn($e) => $e > 1e-7);
   if (!$gated) {
      return null;
   }
   $limit = array_sum($gated) / count($gated) / 10;
   $gated = array_filter($gated, fn($e) => $e > $limit);
   return 10 * log10(array_sum($gated) / count($gated));
}

/* Begrenzer wie in asterisk/stream.sh: Verstärkung $g, Obergrenze */
function audio_limiter(float $g): array
{
   $o = ANNOUNCEMENT_CEILING_DB;
   $curve = fn(float $g) => sprintf('-80,%g,%g,%g,0,%g', -80 + $g, $o - $g - 3, $o - 3, $o);
   return $g > 0
      ? ['compand', '0,0.10', $curve($g), '0', '-90', '0.002']
      : array_merge($g < 0 ? ['gain', sprintf('%.2f', $g)] : [], ['compand', '0,0.10', $curve(0), '0', '-90', '0.002']);
}

/*
 * Erzeugt ansage.wav16 und ansage.wav aus der Quelle mit den geltenden
 * Toneinstellungen. Ohne Quelle wird sie aus dem Original erzeugt; fehlt
 * auch das, bleibt alles, wie es ist. Unverändert gebliebene Ansagen werden
 * nicht neu erzeugt, außer mit $force. Liefert false bei einem Fehler.
 */
function announcement_render(bool $force = false): bool
{
   $src = announcement_source_file();
   if (!is_file($src)) {
      $orig = announcement_original();
      if ($orig === null) {
         return true;
      }
      if (!announcement_make_source($orig)) {
         return false;
      }
   }
   $rate = audio_rate($src);
   if ($rate !== 16000 && $rate !== 8000) {
      error_log('telerix: Quelle der Ansage hat eine unerwartete Abtastrate: ' . $rate);
      return false;
   }
   $e = settings_effective();
   $stamp = json_encode([ANNOUNCEMENT_VERSION, md5_file($src), $e['audio_processing'],
      $e['audio_highpass_hz'], $e['audio_presence_db']]);
   if (!$force && @file_get_contents(announcement_stamp_file()) === $stamp
         && is_file(announcement_file()) && ($rate === 8000 || is_file(announcement_hd_file()))) {
      return true;
   }

   $effects = [];
   if ($e['audio_processing']) {
      $reserve = ANNOUNCEMENT_RESERVE_DB + $e['audio_presence_db'];
      $filters = ['gain', (string)-$reserve];
      if ($e['audio_highpass_hz'] > 0) {
         array_push($filters, 'highpass', (string)$e['audio_highpass_hz']);
      }
      if ($e['audio_presence_db'] > 0) {
         array_push($filters, 'equalizer', ANNOUNCEMENT_PRESENCE[0], ANNOUNCEMENT_PRESENCE[1], '+' . $e['audio_presence_db']);
      }
      $level = audio_speech_level($src, $rate, $filters);
      $gain = $level === null ? 0.0
         : max(-ANNOUNCEMENT_MAX_GAIN_DB, min(ANNOUNCEMENT_MAX_GAIN_DB, ANNOUNCEMENT_LEVEL_DB - ($level + $reserve)));
      $effects = array_merge($filters, audio_limiter($gain + $reserve));
   }

   $out = ['-b', '16', '-e', 'signed-integer', '-t', 'wav'];
   $hd = data_path('sounds/.ansage-' . bin2hex(random_bytes(8)) . '.wav');
   $nb = data_path('sounds/.ansage-' . bin2hex(random_bytes(8)) . '.wav');
   try {
      if ($rate === 16000) {
         /* Erst 16 kHz aufbereiten, daraus 8 kHz (nur noch umrechnen) */
         $ok = audio_sox(array_merge([$src], $out, [$hd], $effects), 'Ansage aufbereiten')
            && audio_sox(array_merge(['-G', $hd], $out, [$nb, 'rate', '-v', '8000']), 'Ansage 8 kHz');
      } else {
         $ok = audio_sox(array_merge([$src], $out, [$nb], $effects), 'Ansage aufbereiten');
      }
      if (!$ok) {
         return false;
      }
      /* 0640, Gruppe "asterisk" kommt vom setgid-Verzeichnis */
      chmod($nb, 0640);
      rename($nb, announcement_file());
      if ($rate === 16000) {
         chmod($hd, 0640);
         rename($hd, announcement_hd_file());
      } else {
         @unlink(announcement_hd_file());
      }
      atomic_write(announcement_stamp_file(), $stamp, 0640);
      return true;
   } finally {
      @unlink($hd);
      @unlink($nb);
   }
}

/* Alle anderen Originale löschen (nur das zuletzt hochgeladene bleibt). */
function announcement_cleanup_originals(?string $keep): void
{
   foreach (glob(audio_upload_dir() . '/*') ?: [] as $f) {
      if ($f !== $keep && $f !== announcement_source_file()) {
         unlink($f);
      }
   }
}

/* Prüft, speichert und wandelt die hochgeladene Ansage um. */
function announcement_upload(string $field): void
{
   $u = upload_check($field, AUDIO_ALLOWED, AUDIO_MAX_BYTES);
   $src = upload_store($u['tmp'], audio_upload_dir(), $u['ext']);
   if (!announcement_make_source($src)) {
      @unlink($src);
      throw new UploadError('Die Audiodatei konnte nicht gelesen werden.');
   }
   if (!announcement_render(true)) {
      throw new UploadError('Die Ansage konnte nicht gespeichert werden.');
   }
   announcement_cleanup_originals($src);
}

/*
 * Ansage aus einer Sicherung: WAV-Daten werden zur Quelle (bis 0.05-mod
 * enthielt die Sicherung die fertige 8-kHz-Ansage, die dann so bleibt).
 */
function announcement_restore(string $wav): bool
{
   $tmp = data_path('state/.restore-' . bin2hex(random_bytes(8)) . '.wav');
   try {
      atomic_write($tmp, $wav, 0600);
      if (!announcement_make_source($tmp)) {
         return false;
      }
   } finally {
      @unlink($tmp);
   }
   announcement_cleanup_originals(null);
   return announcement_render(true);
}

/* Inhalt für die Sicherung: die Quelle, ohne sie die alte 8-kHz-Ansage */
function announcement_backup_data(): ?string
{
   foreach ([announcement_source_file(), announcement_file()] as $f) {
      if (is_file($f)) {
         $d = file_get_contents($f);
         return $d === false ? null : $d;
      }
   }
   return null;
}

/* Zurück zur mitgelieferten Ansage. */
function announcement_delete(): void
{
   @unlink(announcement_file());
   @unlink(announcement_hd_file());
   @unlink(announcement_stamp_file());
   foreach (glob(audio_upload_dir() . '/*') ?: [] as $f) {
      unlink($f);
   }
}
