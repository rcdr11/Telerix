<?php
/*
 * Erzeugt beim Containerstart die von Asterisk gelesenen Dateien neu:
 * pjsip_trunk.conf aus config/sip.json und die Dateien der Einstellungen
 * (lib/settings.php) aus config/settings.json und den Umgebungsvariablen.
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

$s = sip_load();
if (sip_validate($s['user'], $s['password'], $s['registrar']) !== null) {
   fwrite(STDERR, "telerix: Gespeicherte SIP-Daten ungültig, pjsip_trunk.conf bleibt unverändert.\n");
} else {
   atomic_write(sip_trunk_conf_file(), sip_render_trunk($s['user'], $s['password'], $s['registrar']), 0640);
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
