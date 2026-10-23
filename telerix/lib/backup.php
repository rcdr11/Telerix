<?php
/*
 * Sicherung und Wiederherstellung der Konfiguration.
 *
 * Inhalt: Admin-Zugang (Benutzer + Passwort-Hash), SIP-Zugang, Stream-
 * Adresse, Nummernliste, die eigene Ansage und ab Format 2 die
 * Einstellungen aus dem Webinterface (lib/settings.php: Ton, Codec, Ansage
 * an/aus, öffentliche Adresse, Testtelefon).
 * Die Ansage ist ab 0.06-mod die unbearbeitete Quelle (WAV, Mono, 16 kHz,
 * lib/audio.php), aus der beim Einspielen mit den Toneinstellungen neu
 * erzeugt wird; bis 0.05-mod war es die fertige 8-kHz-Ansage. Beides ist
 * WAV, deshalb bleibt das Format gleich. Nicht enthalten: das hochgeladene
 * Original (MP3 usw.), Login-Sperren und alles aus .env – gesichert werden
 * die im Webinterface gespeicherten Werte, auch wenn gerade eine
 * Umgebungsvariable Vorrang hat.
 *
 * Die Daten werden als JSON zusammengestellt und mit einem eigenen
 * Backup-Passwort verschlüsselt (libsodium: Argon2id zur Schlüssel-
 * ableitung, XChaCha20-Poly1305). Beim Einspielen werden keine Dateien
 * aus dem Backup übernommen, sondern alle Werte einzeln geprüft und die
 * Dateien neu erzeugt – wie bei einer Eingabe im Webinterface.
 *
 * Dateiformat: MAGIC | Salt (16) | Nonce (24) | Chiffretext
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/sip.php';
require_once __DIR__ . '/stream.php';
require_once __DIR__ . '/numbers.php';
require_once __DIR__ . '/audio.php';
require_once __DIR__ . '/settings.php';

const BACKUP_MAGIC = "TELERIX-BACKUP-1\n";
const BACKUP_FORMAT_VERSION = 2;
/* Format 1 (bis 0.05-mod) hat noch keine Einstellungen. */
const BACKUP_FORMAT_READABLE = [1, 2];
/* Ansage bis 10 min in 16 kHz (19,2 MB, als Base64 25,6 MB) plus Rest;
   passt zu client_max_body_size (nginx) und post_max_size (PHP-FPM). */
const BACKUP_MAX_BYTES = 32 * 1024 * 1024;
const BACKUP_PASSWORD_MIN_LENGTH = 10;

/* Fehler mit einer Meldung, die dem Benutzer angezeigt werden darf. */
final class BackupError extends RuntimeException {}

function backup_key(string $password, string $salt): string
{
   return sodium_crypto_pwhash(
      SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
      $password,
      $salt,
      SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
      SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
      SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
   );
}

/* Alle zu sichernden Daten einsammeln. */
function backup_collect(): array
{
   $admin = admin_load();
   $wav = announcement_backup_data();
   return [
      'format'          => 'telerix-backup',
      'version'         => BACKUP_FORMAT_VERSION,
      'created'         => gmdate('c'),
      'telerix_version' => TELERIX_VERSION,
      'admin'           => $admin,
      'sip'             => sip_load(),
      'stream'          => stream_load(),
      'numbers'         => numbers_all(),
      'announcement'    => $wav === null ? null : base64_encode($wav),
      'settings'        => settings_stored(),
   ];
}

/* Verschlüsseltes Backup als Binärstring. */
function backup_create(string $password): string
{
   if (strlen($password) < BACKUP_PASSWORD_MIN_LENGTH) {
      throw new BackupError('Das Backup-Passwort muss mindestens ' . BACKUP_PASSWORD_MIN_LENGTH . ' Zeichen lang sein.');
   }
   $plain = json_encode(backup_collect(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
   $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
   $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
   $key = backup_key($password, $salt);
   $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain, BACKUP_MAGIC, $nonce, $key);
   sodium_memzero($key);
   sodium_memzero($plain);
   return BACKUP_MAGIC . $salt . $nonce . $cipher;
}

/* Entschlüsseln und Grundstruktur prüfen. Liefert das Daten-Array. */
function backup_open(string $blob, string $password): array
{
   $hl = strlen(BACKUP_MAGIC);
   $sl = SODIUM_CRYPTO_PWHASH_SALTBYTES;
   $nl = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
   if (strlen($blob) <= $hl + $sl + $nl || !str_starts_with($blob, BACKUP_MAGIC)) {
      throw new BackupError('Das ist keine Telerix-Sicherungsdatei.');
   }
   $salt = substr($blob, $hl, $sl);
   $nonce = substr($blob, $hl + $sl, $nl);
   $key = backup_key($password, $salt);
   $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($blob, $hl + $sl + $nl), BACKUP_MAGIC, $nonce, $key);
   sodium_memzero($key);
   if ($plain === false) {
      throw new BackupError('Backup-Passwort falsch oder Datei beschädigt.');
   }
   $data = json_decode($plain, true);
   if (!is_array($data) || ($data['format'] ?? '') !== 'telerix-backup') {
      throw new BackupError('Die Sicherungsdatei hat ein unbekanntes Format.');
   }
   if (!in_array($data['version'] ?? 0, BACKUP_FORMAT_READABLE, true)) {
      throw new BackupError('Die Sicherung stammt aus einer nicht unterstützten Telerix-Version.');
   }
   return $data;
}

/*
 * Alle Werte prüfen, bevor irgendetwas geschrieben wird.
 * Liefert die bereinigten Werte.
 */
function backup_validate(array $d): array
{
   $admin = $d['admin'] ?? null;
   if (!is_array($admin) || !is_string($admin['user'] ?? null) || !is_string($admin['hash'] ?? null)
         || !valid_username($admin['user']) || password_get_info($admin['hash'])['algo'] === null) {
      throw new BackupError('Die Sicherung enthält keinen gültigen Admin-Zugang.');
   }

   $sip = $d['sip'] ?? null;
   if (!is_array($sip)) {
      throw new BackupError('Die SIP-Daten in der Sicherung sind ungültig.');
   }
   $sip = [
      'user' => is_string($sip['user'] ?? null) ? $sip['user'] : '',
      'password' => is_string($sip['password'] ?? null) ? $sip['password'] : '',
      'registrar' => is_string($sip['registrar'] ?? null) ? $sip['registrar'] : '',
   ];
   $err = sip_validate($sip['user'], $sip['password'], $sip['registrar']);
   if ($err !== null) {
      throw new BackupError('SIP-Daten in der Sicherung: ' . $err);
   }

   $stream = stream_normalize(is_string($d['stream'] ?? null) ? $d['stream'] : '');
   if ($stream === null) {
      throw new BackupError('Die Stream-Adresse in der Sicherung ist ungültig.');
   }

   $numbers = [];
   foreach (is_array($d['numbers'] ?? null) ? $d['numbers'] : [] as $e) {
      if (!is_array($e) || !is_string($e['number'] ?? null) || !is_string($e['name'] ?? null)
            || !preg_match('/^[0-9]{3,20}$/', $e['number']) || mb_strlen($e['name']) > 80
            || preg_match('/[\t\r\n]/', $e['name'])) {
         throw new BackupError('Die Nummernliste in der Sicherung ist ungültig.');
      }
      $numbers[] = $e;
   }

   $wav = null;
   if (($d['announcement'] ?? null) !== null) {
      $wav = is_string($d['announcement']) ? base64_decode($d['announcement'], true) : false;
      if ($wav === false || !str_starts_with($wav, 'RIFF') || substr($wav, 8, 4) !== 'WAVE') {
         throw new BackupError('Die Ansage in der Sicherung ist ungültig.');
      }
   }

   /* null = Sicherung ohne Einstellungen (Format 1), die aktuellen bleiben. */
   $settings = null;
   if ($d['version'] >= 2) {
      if (!is_array($d['settings'] ?? null)) {
         throw new BackupError('Die Einstellungen in der Sicherung sind ungültig.');
      }
      $settings = [];
      foreach (SETTINGS as $key => $def) {
         try {
            $settings[$key] = array_key_exists($key, $d['settings']) ? setting_check($key, $d['settings'][$key]) : $def['default'];
         } catch (SettingsError $e) {
            throw new BackupError('Einstellungen in der Sicherung: ' . $e->getMessage());
         }
      }
   }

   return ['admin' => $admin, 'sip' => $sip, 'stream' => $stream, 'numbers' => $numbers, 'announcement' => $wav,
      'settings' => $settings];
}

/* Geprüfte Werte übernehmen. Die Ansage wird wie beim Upload neu erzeugt,
   mit den Toneinstellungen aus der Sicherung (settings_save am Ende). */
function backup_apply(array $v): void
{
   if ($v['announcement'] !== null) {
      if (!announcement_restore($v['announcement'])) {
         throw new BackupError('Die Ansage aus der Sicherung konnte nicht gelesen werden.');
      }
   } else {
      announcement_delete();
   }

   atomic_write(admin_file(), json_encode(
      ['user' => $v['admin']['user'], 'hash' => $v['admin']['hash']],
      JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
   ) . "\n", 0600);
   sip_save($v['sip']['user'], $v['sip']['password'], $v['sip']['registrar']);
   stream_save($v['stream']);

   numbers_delete_all();
   if ($v['numbers']) {
      $lines = array_map(fn($e) => $e['number'] . "\t" . $e['name'], $v['numbers']);
      atomic_write(numbers_dir() . '/' . bin2hex(random_bytes(16)) . '.txt', implode("\n", $lines) . "\n", 0600);
   }

   if ($v['settings'] !== null) {
      settings_save($v['settings']);
   }
}
