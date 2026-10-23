<?php
/*
 * Hörprobe: ein kurzer Ausschnitt des aktuellen Streams, aufbereitet wie für
 * Anrufer (asterisk/stream.sh --hoerprobe), zum Anhören im Browser. So lassen
 * sich alle Toneinstellungen beurteilen, ohne anzurufen und ohne die
 * laufende Übertragung zu ändern.
 *
 * Dazu Pegel (RMS und Spitze in dBFS, sox stats) des Streams vor der
 * Aufbereitung und der aufbereiteten Fassung. Der Stream wird hinter dem
 * Decoder gemessen, ein eingestellter Eingangspegel ist herausgerechnet:
 * So zeigt sich, ob der Stream über 0 dBFS geht.
 *
 * Zwei Fassungen:
 *   telefon  wie im Telefonnetz: 300 bis 3400 Hz, 8 kHz, G.711 (A-law)
 *            kodiert und wieder dekodiert
 *   hd       wie bei HD-Telefonie (G.722): 50 bis 7000 Hz, 16 kHz
 * Sie liegen in TELERIX_DATA/state und werden bei jeder Probe ersetzt.
 */
declare(strict_types=1);

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/asterisk.php';

const HOERPROBE_SEKUNDEN = 20;
const HOERPROBE_FASSUNGEN = ['telefon', 'hd'];
/* Ausgabe von telerix-stream: 16 kHz, 16 Bit, mono */
const HOERPROBE_RATE = 16000;

function hoerprobe_file(string $fassung): string
{
   return data_path('state/hoerprobe-' . $fassung . '.wav');
}

function hoerprobe_info_file(): string
{
   return data_path('state/hoerprobe.json');
}

/* Werte aus dem Formular, die die Hörprobe verwendet */
const HOERPROBE_KEYS = ['audio_processing', 'audio_input_db', 'audio_gain_db', 'audio_highpass_hz',
   'audio_presence_db', 'audio_profile'];

/* ['created' => Zeitstempel, 'values' => [...], 'levels' => [...] oder null] oder null */
function hoerprobe_info(): ?array
{
   $i = json_decode((string)@file_get_contents(hoerprobe_info_file()), true);
   foreach (HOERPROBE_FASSUNGEN as $f) {
      if (!is_file(hoerprobe_file($f))) {
         return null;
      }
   }
   if (!is_array($i) || !is_int($i['created'] ?? null) || !is_array($i['values'] ?? null)) {
      return null;
   }
   /* Hörproben von vor 0.06-mod: fehlende Werte wie damals (Aufbereitung an,
      ohne Eingangspegel und Präsenz), keine Pegel */
   $i['values'] += ['audio_processing' => true, 'audio_input_db' => 0, 'audio_presence_db' => 0];
   $i['levels'] = is_array($i['levels'] ?? null) ? $i['levels'] : null;
   return $i;
}

/*
 * RMS und Spitze in dBFS (erste Spalte von sox stats = alle Kanäle) oder
 * null. $args: Eingabe und Effekte für sox.
 */
function hoerprobe_stats(array $args): ?array
{
   [$code, , $err] = run_command(array_merge(['sox', '-q'], $args, ['stats']), 30);
   if ($code !== 0 || !preg_match('/^RMS lev dB\s+(-?[0-9.]+|-inf)/m', $err, $r)
         || !preg_match('/^Pk lev dB\s+(-?[0-9.]+|-inf)/m', $err, $p)) {
      return null;
   }
   $db = fn(string $v) => $v === '-inf' ? -120.0 : (float)$v;
   return ['rms' => $db($r[1]), 'peak' => $db($p[1])];
}

/*
 * Pegel des Streams (Rohdatei hinter mpg123, AU) und der aufbereiteten
 * Fassung (raw, 16 kHz). RMS des Streams nach dem Mischen auf Mono wie in
 * stream.sh, die Spitze über alle Kanäle, denn abgeschnitten wird je Kanal.
 */
function hoerprobe_levels(string $roh, string $src, array $v): ?array
{
   $sek = (string)HOERPROBE_SEKUNDEN;
   $mono = hoerprobe_stats(['-t', 'au', $roh, '-n', 'remix', '-', 'trim', '0', $sek]);
   $all = hoerprobe_stats(['-t', 'au', $roh, '-n', 'trim', '0', $sek]);
   $out = hoerprobe_stats(['-t', 'raw', '-r', (string)HOERPROBE_RATE, '-e', 'signed-integer', '-b', '16', '-c', '1', $src, '-n']);
   if ($mono === null || $all === null || $out === null) {
      return null;
   }
   /* Eingangspegel herausrechnen (wirkt im Decoder, nur bei Aufbereitung an) */
   $in = $v['audio_processing'] ? (int)$v['audio_input_db'] : 0;
   return [
      'stream' => ['rms' => $mono['rms'] - $in, 'peak' => $all['peak'] - $in,
         /* Am Decoder-Ausgang 0 dBFS erreicht: dort wurde abgeschnitten */
         'clipped' => $all['peak'] >= -0.05],
      'telefon' => $out,
      'input_db' => $in,
   ];
}

/*
 * Erzeugt die Hörprobe mit geprüften Werten (HOERPROBE_KEYS). Dauert etwa
 * HOERPROBE_SEKUNDEN, weil der Stream in Echtzeit ankommt.
 */
function hoerprobe_create(array $v): void
{
   /* Name muss zu stream.sh passen: state/.hoerprobe-<hex>.au */
   $roh = data_path('state/.hoerprobe-' . bin2hex(random_bytes(8)) . '.au');
   [, $raw, $err] = run_command([
      '/usr/local/bin/telerix-stream', '--hoerprobe', (string)HOERPROBE_SEKUNDEN,
      $v['audio_processing'] ? 'an' : 'aus', (string)$v['audio_input_db'], (string)$v['audio_highpass_hz'],
      (string)$v['audio_presence_db'], $v['audio_profile'], (string)$v['audio_gain_db'], $roh,
   ], HOERPROBE_SEKUNDEN + 25);
   /* Mindestens 2 s Ton, sonst war der Stream nicht erreichbar */
   if (strlen($raw) < HOERPROBE_RATE * 2 * 2) {
      @unlink($roh);
      error_log('telerix: Hörprobe ohne Ton: ' . trim($err));
      throw new SettingsError(trim($err) === 'Keine Stream-Adresse eingerichtet'
         ? 'Für eine Hörprobe zuerst eine Stream-Adresse eintragen.'
         : 'Der Stream liefert gerade keinen Ton. Läuft er?');
   }

   $src = data_path('state/.hoerprobe-' . bin2hex(random_bytes(8)) . '.raw');
   $alaw = data_path('state/.hoerprobe-' . bin2hex(random_bytes(8)) . '.al');
   $in = ['-t', 'raw', '-r', (string)HOERPROBE_RATE, '-e', 'signed-integer', '-b', '16', '-c', '1'];
   try {
      atomic_write($src, $raw, 0600);
      $steps = [
         array_merge(['sox', '-q'], $in, [$src, '-t', 'raw', '-r', '8000', '-e', 'a-law', '-b', '8', $alaw,
            'sinc', '300-3400', 'rate', '-v', '8000']),
         ['sox', '-q', '-t', 'raw', '-r', '8000', '-e', 'a-law', '-b', '8', '-c', '1', $alaw,
            '-e', 'signed-integer', '-b', '16', hoerprobe_file('telefon') . '.tmp.wav'],
         array_merge(['sox', '-q'], $in, [$src, hoerprobe_file('hd') . '.tmp.wav', 'sinc', '50-7000']),
      ];
      foreach ($steps as $argv) {
         [$code, , $err] = run_command($argv, 30);
         if ($code !== 0) {
            error_log('telerix: Hörprobe, sox fehlgeschlagen: ' . trim($err));
            throw new SettingsError('Die Hörprobe konnte nicht erstellt werden.');
         }
      }
      foreach (HOERPROBE_FASSUNGEN as $f) {
         chmod(hoerprobe_file($f) . '.tmp.wav', 0600);
         rename(hoerprobe_file($f) . '.tmp.wav', hoerprobe_file($f));
      }
      $levels = is_file($roh) ? hoerprobe_levels($roh, $src, $v) : null;
      atomic_write(hoerprobe_info_file(), json_encode(['created' => time(), 'values' => $v, 'levels' => $levels]) . "\n", 0600);
   } finally {
      @unlink($src);
      @unlink($alaw);
      @unlink($roh);
      foreach (HOERPROBE_FASSUNGEN as $f) {
         @unlink(hoerprobe_file($f) . '.tmp.wav');
      }
   }
}
