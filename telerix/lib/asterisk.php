<?php
/*
 * Steuerung von Asterisk ohne sudo und ohne Shell.
 *
 * PHP läuft als Benutzer "telerix" und ist Mitglied der Gruppe "astctl".
 * Asterisk legt seinen Control-Socket mit dieser Gruppe und den Rechten
 * 0660 an (asterisk.conf: astctlgroup/astctlpermissions), dadurch genügt
 * "asterisk -rx". proc_open() bekommt die Argumente als Array, es wird
 * also keine Shell gestartet und nichts muss maskiert werden. Es werden
 * nur feste Befehle aus diesem Modul abgesetzt.
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const ASTERISK_BIN = '/usr/sbin/asterisk';

/*
 * Programm ohne Shell ausführen. Liefert [exitcode, stdout, stderr].
 */
function run_command(array $argv, int $timeout = 15): array
{
   $proc = proc_open(
      $argv,
      [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
      $pipes
   );
   if (!is_resource($proc)) {
      return [-1, '', 'Prozess konnte nicht gestartet werden'];
   }
   /* Keine Eingabe (/dev/null wäre durch open_basedir gesperrt) */
   fclose($pipes[0]);
   stream_set_blocking($pipes[1], false);
   stream_set_blocking($pipes[2], false);
   $out = $err = '';
   $deadline = time() + $timeout;
   while (true) {
      $read = [$pipes[1], $pipes[2]];
      $w = $e = null;
      if (stream_select($read, $w, $e, 1) === false) {
         break;
      }
      $out .= (string)stream_get_contents($pipes[1]);
      $err .= (string)stream_get_contents($pipes[2]);
      if (feof($pipes[1]) && feof($pipes[2])) {
         break;
      }
      if (time() > $deadline) {
         proc_terminate($proc);
         $err .= "\nZeitüberschreitung";
         break;
      }
   }
   fclose($pipes[1]);
   fclose($pipes[2]);
   $code = proc_close($proc);
   return [$code, $out, $err];
}

/* Einen festen CLI-Befehl an den laufenden Asterisk senden. */
function asterisk_cli(string $command): ?string
{
   [$code, $out] = run_command([ASTERISK_BIN, '-rx', $command]);
   return $code === 0 ? $out : null;
}

function asterisk_running(): bool
{
   return asterisk_cli('core show uptime') !== null;
}

function asterisk_version(): string
{
   [$code, $out] = run_command([ASTERISK_BIN, '-V']);
   return $code === 0 ? trim($out) : 'unbekannt';
}

/* PJSIP-Konfiguration (inkl. Registrierung beim Anbieter) neu laden. */
function asterisk_reload_pjsip(): bool
{
   return asterisk_cli('module reload res_pjsip.so') !== null
      && asterisk_cli('module reload res_pjsip_outbound_registration.so') !== null
      && asterisk_cli('module reload res_pjsip_endpoint_identifier_ip.so') !== null;
}

function asterisk_hangup_all(): bool
{
   return asterisk_cli('channel request hangup all') !== null;
}

/* Asterisk startet sich selbst neu (der Prozessmanager bleibt unberührt). */
function asterisk_restart(): bool
{
   return asterisk_cli('core restart now') !== null;
}

/*
 * Aktive Anrufer aus "core show channels concise": Liste von
 * ['caller' => Anrufernummer, 'codec' => ausgehandelter Codec oder ''].
 * Format je Zeile: Kanal!Kontext!Extension!Prio!Status!App!Daten!CallerID!
 * Accountcode!... – den Codec trägt der Dialplan als Accountcode ein.
 */
function asterisk_callers(): array
{
   $out = asterisk_cli('core show channels concise');
   if ($out === null) {
      return [];
   }
   $callers = [];
   foreach (preg_split('/\R/', trim($out)) as $line) {
      $f = explode('!', $line);
      if (count($f) < 8 || !str_starts_with($f[0], 'PJSIP/')) {
         continue;
      }
      $callers[] = ['caller' => $f[7], 'codec' => strtolower(trim($f[8] ?? '', '() '))];
   }
   return $callers;
}

/* Anzeige für einen Codec: [Text, HD ja/nein]. */
function codec_label(string $codec): array
{
   return match ($codec) {
      'g722'         => ['HD (G.722)', true],
      'alaw', 'ulaw' => ['Telefonqualität (G.711)', false],
      'gsm'          => ['GSM', false],
      ''             => ['unbekannt', false],
      default        => [$codec, false],
   };
}
