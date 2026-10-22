<?php
/*
 * Hörprobe: ein kurzer Ausschnitt des aktuellen Streams, aufbereitet wie für
 * Anrufer (asterisk/stream.sh --hoerprobe), zum Anhören im Browser. So lassen
 * sich Lautstärke, Klangprofil und Hochpass beurteilen, ohne anzurufen und
 * ohne die laufende Übertragung zu ändern.
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

/* ['created' => Zeitstempel, 'values' => [...]] oder null */
function hoerprobe_info(): ?array
{
   $i = json_decode((string)@file_get_contents(hoerprobe_info_file()), true);
   foreach (HOERPROBE_FASSUNGEN as $f) {
      if (!is_file(hoerprobe_file($f))) {
         return null;
      }
   }
   return is_array($i) && is_int($i['created'] ?? null) && is_array($i['values'] ?? null) ? $i : null;
}

/*
 * Erzeugt die Hörprobe mit geprüften Werten (audio_gain_db,
 * audio_highpass_hz, audio_profile). Dauert etwa HOERPROBE_SEKUNDEN, weil der
 * Stream in Echtzeit ankommt.
 */
function hoerprobe_create(array $v): void
{
   [, $raw, $err] = run_command([
      '/usr/local/bin/telerix-stream', '--hoerprobe', (string)HOERPROBE_SEKUNDEN,
      (string)$v['audio_gain_db'], (string)$v['audio_highpass_hz'], $v['audio_profile'],
   ], HOERPROBE_SEKUNDEN + 25);
   /* Mindestens 2 s Ton, sonst war der Stream nicht erreichbar */
   if (strlen($raw) < HOERPROBE_RATE * 2 * 2) {
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
      atomic_write(hoerprobe_info_file(), json_encode(['created' => time(), 'values' => $v]) . "\n", 0600);
   } finally {
      @unlink($src);
      @unlink($alaw);
      foreach (HOERPROBE_FASSUNGEN as $f) {
         @unlink(hoerprobe_file($f) . '.tmp.wav');
      }
   }
}
