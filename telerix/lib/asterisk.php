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
         /* mpg123 übergeht SIGTERM, solange es auf Daten eines stehenden
            Streams wartet; proc_close() würde dann ewig warten und den
            PHP-Prozess blockieren. Nach 2 s daher SIGKILL. */
         for ($i = 0; $i < 20 && proc_get_status($proc)['running']; $i++) {
            usleep(100000);
         }
         if (proc_get_status($proc)['running']) {
            proc_terminate($proc, 9);
         }
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

/* Nur die Endpunkte neu laden (z. B. geänderte Codecs); Registrierung und
   laufende Anrufe bleiben unberührt. */
function asterisk_reload_endpoints(): bool
{
   return asterisk_cli('module reload res_pjsip.so') !== null;
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

/* ---------- Anrufstatistik (ohne Rufnummern) ---------- */

/* Unter so vielen Sekunden gilt ein Anruf als sehr kurz; viele davon sind
   ein Hinweis, dass Anrufer nichts hören (Stream stumm) */
const CALL_SHORT_SECONDS = 30;
/* Stundenwerte, die älter sind, werden gelöscht */
const CALL_STATS_KEEP_HOURS = 8 * 24;

/*
 * Anrufe der letzten $hours Stunden aus der AstDB. Der Dialplan zählt je
 * Stunde (UTC) unter telerix_anrufe/<JJJJMMTTHH>/ die angenommenen Anrufe
 * (anzahl), ihre Gesamtdauer (sekunden) und die sehr kurzen (kurz), siehe
 * asterisk/extensions.conf. Nach einem Update (neuer Container) beginnt die
 * Zählung von vorn. Liefert null, wenn Asterisk nicht erreichbar ist, sonst
 * ['calls' => n, 'seconds' => s, 'short' => n].
 */
function call_stats(int $hours = 24): ?array
{
   $out = asterisk_cli('database show telerix_anrufe');
   if ($out === null) {
      return null;
   }
   $now = time();
   $from = gmdate('YmdH', $now - ($hours - 1) * 3600);
   $old = gmdate('YmdH', $now - CALL_STATS_KEEP_HOURS * 3600);
   $sum = ['calls' => 0, 'seconds' => 0, 'short' => 0];
   $expired = [];
   $map = ['anzahl' => 'calls', 'sekunden' => 'seconds', 'kurz' => 'short'];
   foreach (preg_split('/\R/', $out) as $line) {
      if (!preg_match('#^/telerix_anrufe/(\d{10})/(anzahl|sekunden|kurz)\s*:\s*(\d+)\s*$#', $line, $m)) {
         continue;
      }
      if ($m[1] < $old) {
         $expired[$m[1]] = true;
      } elseif ($m[1] >= $from) {
         $sum[$map[$m[2]]] += (int)$m[3];
      }
   }
   foreach (array_keys($expired) as $hour) {
      asterisk_cli('database deltree telerix_anrufe/' . $hour);
   }
   return $sum;
}
