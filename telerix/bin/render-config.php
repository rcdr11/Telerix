<?php
/*
 * Erzeugt beim Containerstart die von Asterisk gelesenen Dateien neu:
 * pjsip_trunk.conf aus config/sip.json und dem bevorzugten Codec, die
 * Dateien der Einstellungen (lib/settings.php) aus config/settings.json und
 * den Umgebungsvariablen, und die eigene Begrüßungsansage, falls sich
 * Toneinstellungen oder das Verfahren geändert haben (lib/audio.php).
 *
 * Die Dateien liegen im Datenvolume und werden sonst nur beim Speichern im
 * Webinterface geschrieben. Ohne diesen Schritt kämen Änderungen an den
 * Vorlagen oder an .env bei einem Update bzw. Neustart nie an.
 *
 * Ungültige Werte für TELERIX_EXTERNAL_ADDRESS und
 * TELERIX_TESTPHONE_PASSWORD verhindern den Start (Exitcode 1), ungültige
 * Tonwerte werden wie bisher ignoriert.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/sip.php';
require_once __DIR__ . '/../lib/settings.php';

/* pjsip_trunk.conf schreibt settings_render() (sip_write_trunk) */
$s = sip_load();
if (sip_validate($s['user'], $s['password'], $s['registrar']) !== null) {
   fwrite(STDERR, "telerix: Gespeicherte SIP-Daten ungültig, pjsip_trunk.conf bleibt unverändert.\n");
}

$fatal = false;
foreach (SETTINGS as $key => $def) {
   $raw = settings_env_raw($key);
   if ($raw === null) {
      continue;
   }
   try {
      setting_check($key, $raw);
      echo "telerix: {$def['env']} aus .env gilt, im Webinterface gesperrt.\n";
   } catch (SettingsError $e) {
      $fatal = $fatal || !str_starts_with($key, 'audio_');
      fwrite(STDERR, "telerix: {$def['env']} ungültig ({$e->getMessage()})"
         . (str_starts_with($key, 'audio_') ? ", wird ignoriert.\n" : "\n"));
   }
}
if ($fatal) {
   exit(1);
}

settings_render();
$addr = settings_effective()['external_address'];
if ($addr !== '') {
   echo "telerix: Öffentliche Adresse für SIP/RTP: $addr\n";
}
