<?php
/*
 * Einstellungen, die früher nur über Umgebungsvariablen (.env) gingen und
 * jetzt im Webinterface gesetzt werden: Tonaufbereitung (dazu neu
 * Klangprofil und Hochpass), Begrüßungsansage an/aus, öffentliche Adresse
 * für SIP/RTP und Testtelefon; außerdem die Anzeige des Icecast-Status auf
 * der Startseite.
 *
 * Gespeichert in TELERIX_DATA/config/settings.json (nur für "telerix"
 * lesbar). settings_render() erzeugt daraus die Dateien, die Asterisk und
 * telerix-stream lesen – beim Speichern und bei jedem Containerstart
 * (bin/render-config.php).
 *
 * Ist die zugehörige Umgebungsvariable gesetzt, hat sie Vorrang: Das Feld
 * im Webinterface ist dann gesperrt und zeigt den Wert aus .env. So ändert
 * ein Update nichts an bestehenden Installationen.
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/address.php';
require_once __DIR__ . '/stream.php';

const AUDIO_GAIN_MIN = 0;
const AUDIO_GAIN_MAX = 15;
const AUDIO_BUFFER_MIN = 0;
const AUDIO_BUFFER_MAX = 10;
const TESTPHONE_PASSWORD_MIN_LENGTH = 12;
/* Muss zu asterisk/stream.sh passen (gueltiger_hochpass, gueltiges_profil). */
const AUDIO_HIGHPASS_CHOICES = [0, 50, 80, 120, 180, 250];
const AUDIO_PROFILES = [
   'sprache' => 'Sprache',
   'musik'   => 'Sprache und Musik',
   'aus'     => 'Ohne Leveler',
];

/* Einstellung => Umgebungsvariable (null = nur im Webinterface) und Standardwert */
const SETTINGS = [
   'audio_gain_db'      => ['env' => 'TELERIX_AUDIO_ANHEBUNG_DB',     'default' => 5],
   'audio_buffer_s'     => ['env' => 'TELERIX_AUDIO_PUFFER_SEKUNDEN', 'default' => 4],
   'audio_profile'      => ['env' => null,                            'default' => 'sprache'],
   'audio_highpass_hz'  => ['env' => null,                            'default' => 180],
   'announcement'       => ['env' => null,                            'default' => true],
   'external_address'   => ['env' => 'TELERIX_EXTERNAL_ADDRESS',      'default' => ''],
   'testphone_password' => ['env' => 'TELERIX_TESTPHONE_PASSWORD',    'default' => ''],
   /* Leer = aus der Stream-Adresse ermitteln (lib/stream.php) */
   'icecast_status_url' => ['env' => null,                            'default' => ''],
   'icecast_panel'      => ['env' => null,                            'default' => true],
];

/* Fehler mit einer Meldung, die dem Benutzer angezeigt werden darf. */
final class SettingsError extends RuntimeException {}

function settings_file(): string
{
   return data_path('config/settings.json');
}

function settings_int(mixed $v, int $min, int $max, string $label): int
{
   if (is_string($v) && preg_match('/^-?[0-9]{1,3}$/', trim($v)) === 1) {
      $v = (int)trim($v);
   }
   if (!is_int($v) || $v < $min || $v > $max) {
      throw new SettingsError("$label: erlaubt sind ganze Zahlen von $min bis $max.");
   }
   return $v;
}

/* Prüft einen Wert und liefert ihn vereinheitlicht oder wirft SettingsError. */
function setting_check(string $key, mixed $v): mixed
{
   switch ($key) {
      case 'audio_gain_db':
         return settings_int($v, AUDIO_GAIN_MIN, AUDIO_GAIN_MAX, 'Lautstärke');
      case 'audio_buffer_s':
         return settings_int($v, AUDIO_BUFFER_MIN, AUDIO_BUFFER_MAX, 'Vorlauf');
      case 'audio_profile':
         if (!is_string($v) || !isset(AUDIO_PROFILES[$v])) {
            throw new SettingsError('Klangprofil: ungültige Auswahl.');
         }
         return $v;
      case 'audio_highpass_hz':
         if (is_string($v) && preg_match('/^[0-9]{1,3}$/', $v) === 1) {
            $v = (int)$v;
         }
         if (!in_array($v, AUDIO_HIGHPASS_CHOICES, true)) {
            throw new SettingsError('Hochpass: erlaubt sind ' . implode(', ', AUDIO_HIGHPASS_CHOICES) . ' Hz.');
         }
         return $v;
      case 'announcement':
      case 'icecast_panel':
         if (!is_bool($v)) {
            throw new SettingsError('Ungültiger Wert für ' . ($key === 'announcement' ? 'die Ansage.' : 'den Icecast-Status.'));
         }
         return $v;
      case 'icecast_status_url':
         /* Wie eine Stream-Adresse geprüft; ohne .xsl am Ende wird
            /status-json.xsl angehängt (z. B. http://server:8000). */
         $u = is_string($v) ? stream_normalize($v) : null;
         if ($u === '') {
            return '';
         }
         if ($u === null || parse_url($u, PHP_URL_QUERY) !== null || parse_url($u, PHP_URL_FRAGMENT) !== null) {
            throw new SettingsError('Icecast-Statusadresse: http:// oder https:// ohne ? und #, z. B. http://radio.example.org:8000');
         }
         return str_ends_with(strtolower((string)parse_url($u, PHP_URL_PATH)), '.xsl') ? $u : rtrim($u, '/') . '/status-json.xsl';
      case 'external_address':
         $v = is_string($v) ? trim($v) : null;
         if ($v === null || ($v !== '' && !valid_external_address($v))) {
            throw new SettingsError('Öffentliche Adresse: IPv4-Adresse oder Hostname, z. B. 203.0.113.10 oder sip.example.org.');
         }
         return $v;
      case 'testphone_password':
         if (!is_string($v) || ($v !== '' && preg_match('/^[A-Za-z0-9._+-]{' . TESTPHONE_PASSWORD_MIN_LENGTH . ',128}$/', $v) !== 1)) {
            throw new SettingsError('Testtelefon-Passwort: ' . TESTPHONE_PASSWORD_MIN_LENGTH . ' bis 128 Zeichen aus A-Z, a-z, 0-9 und . _ + -');
         }
         return $v;
   }
   throw new SettingsError("Unbekannte Einstellung $key.");
}

/* Im Webinterface gespeicherte Werte; fehlende oder ungültige = Standard. */
function settings_stored(): array
{
   $raw = json_decode((string)@file_get_contents(settings_file()), true);
   $s = [];
   foreach (SETTINGS as $key => $def) {
      try {
         $s[$key] = is_array($raw) && array_key_exists($key, $raw) ? setting_check($key, $raw[$key]) : $def['default'];
      } catch (SettingsError) {
         $s[$key] = $def['default'];
      }
   }
   return $s;
}

/* Roher Wert der Umgebungsvariable oder null, wenn sie fehlt oder leer ist. */
function settings_env_raw(string $key): ?string
{
   $env = SETTINGS[$key]['env'];
   $v = $env === null ? false : getenv($env);
   return $v === false || trim($v) === '' ? null : $v;
}

/* Gültiger Wert aus der Umgebungsvariable oder null. Ungültige Werte zählen
   als nicht gesetzt (Tonwerte wurden schon früher so behandelt). */
function settings_env(string $key): mixed
{
   $raw = settings_env_raw($key);
   if ($raw === null) {
      return null;
   }
   try {
      return setting_check($key, $raw);
   } catch (SettingsError) {
      return null;
   }
}

/* Gesperrt im Webinterface, weil die Umgebungsvariable gilt. */
function setting_locked(string $key): bool
{
   return settings_env($key) !== null;
}

/* Hinweis für gesperrte Felder im Webinterface. */
function setting_locked_text(string $key): string
{
   return 'Festgelegt in .env (' . SETTINGS[$key]['env'] . '). Um den Wert hier einzustellen, die Zeile aus .env '
      . 'entfernen und im Telerix-Ordner „docker compose up -d“ ausführen.';
}

/* Tatsächlich geltende Werte: Umgebungsvariable vor gespeichertem Wert. */
function settings_effective(): array
{
   $s = settings_stored();
   foreach (array_keys(SETTINGS) as $key) {
      $s[$key] = settings_env($key) ?? $s[$key];
   }
   return $s;
}

/* Geprüfte Werte speichern (übrige bleiben) und die Dateien neu erzeugen. */
function settings_save(array $changes): void
{
   $s = settings_stored();
   foreach ($changes as $key => $v) {
      $s[$key] = setting_check($key, $v);
   }
   atomic_write(settings_file(), json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", 0600);
   settings_render();
}

/* ---------- Von Asterisk und telerix-stream gelesene Dateien ---------- */

function settings_audio_conf_file(): string
{
   return data_path('asterisk/audio.conf');
}

/* Existiert die Datei, überspringt der Dialplan die Begrüßungsansage. */
function settings_announcement_off_file(): string
{
   return data_path('asterisk/ansage_aus');
}

function settings_external_conf_file(): string
{
   return data_path('asterisk/pjsip_external.conf');
}

function settings_testphone_conf_file(): string
{
   return data_path('asterisk/pjsip_testphone.conf');
}

/*
 * Erzeugt alle Dateien aus den geltenden Werten. Danach gilt:
 * Ton und Ansage sofort, Testtelefon nach asterisk_reload_pjsip(),
 * öffentliche Adresse erst nach einem Neustart von Asterisk (Transporte
 * lädt PJSIP nicht neu).
 */
function settings_render(): void
{
   $e = settings_effective();

   /* 0640, Gruppe "asterisk" kommt vom setgid-Verzeichnis. */
   atomic_write(settings_audio_conf_file(),
      "# Automatisch erzeugt vom Telerix-Webinterface – nicht von Hand ändern.\n"
      . "ANHEBUNG_DB={$e['audio_gain_db']}\n"
      . "PUFFER_SEKUNDEN={$e['audio_buffer_s']}\n"
      . "HOCHPASS_HZ={$e['audio_highpass_hz']}\n"
      . "PROFIL={$e['audio_profile']}\n", 0640);

   if ($e['announcement']) {
      @unlink(settings_announcement_off_file());
   } else {
      atomic_write(settings_announcement_off_file(), "Begrüßungsansage im Webinterface abgeschaltet\n", 0640);
   }

   $addr = $e['external_address'];
   atomic_write(settings_external_conf_file(), $addr === ''
      ? "; Keine öffentliche Adresse eingetragen\n"
      : "; Automatisch erzeugt – öffentliche Adresse für SIP/RTP\n"
        . "external_media_address=$addr\n"
        . "external_signaling_address=$addr\n", 0640);

   atomic_write(settings_testphone_conf_file(), testphone_render($e['testphone_password']), 0640);
}

/* Testtelefon "testphone" im Kontext from-internal (Extension 100). */
function testphone_render(string $pw): string
{
   if ($pw === '') {
      return "; Kein Testtelefon eingerichtet\n";
   }
   return <<<CONF
; Automatisch erzeugt – Testtelefon
[testphone]
type=auth
auth_type=userpass
username=testphone
password={$pw}

[testphone]
type=aor
max_contacts=1

[testphone]
type=endpoint
transport=transport-udp
context=from-internal
disallow=all
allow=g722,alaw,ulaw,gsm
auth=testphone
aors=testphone
direct_media=no
rtp_symmetric=yes
force_rport=yes
rewrite_contact=yes

CONF;
}
