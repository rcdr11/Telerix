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

/*
 * SIP nur von bestimmten Netzen annehmen (TELERIX_SIP_ALLOW, Standard leer =
 * von überall wie bisher). Erzeugt eine PJSIP-ACL (res_pjsip_acl), die alles
 * abweist außer den eingetragenen Netzen, localhost und den privaten Netzen
 * (Docker, Testtelefon im Heimnetz). Ungültige Einträge verhindern den
 * Start: Asterisk würde sonst bei einer fehlerhaften ACL jeden SIP-Verkehr
 * sperren, auch den des Anbieters.
 */
$allow = preg_split('/[\s,]+/', trim((string)getenv('TELERIX_SIP_ALLOW')), -1, PREG_SPLIT_NO_EMPTY);
foreach ($allow as $net) {
   if (!valid_ip_or_cidr($net) || preg_match('#/0$#', $net)) {
      fwrite(STDERR, "telerix: TELERIX_SIP_ALLOW: '$net' ist keine gültige IPv4/IPv6-Adresse bzw. kein CIDR-Netz "
         . "(z. B. 217.10.64.0/20; /0 ist nicht erlaubt).\n");
      exit(1);
   }
}
$acl = "; Automatisch erzeugt aus TELERIX_SIP_ALLOW (bin/render-config.php)\n";
if ($allow) {
   $acl .= "[telerix-acl]\ntype=acl\ndeny=0.0.0.0/0.0.0.0\ndeny=::/0\n"
      . "; localhost und private Netze (Docker, Testtelefon im Heimnetz)\n"
      . "permit=127.0.0.0/8\npermit=10.0.0.0/8\npermit=172.16.0.0/12\npermit=192.168.0.0/16\n"
      . "; Netze des SIP-Anbieters\n";
   foreach ($allow as $net) {
      $acl .= "permit=$net\n";
   }
   echo 'telerix: SIP nur von ' . implode(', ', $allow) . " und privaten Netzen (TELERIX_SIP_ALLOW)\n";
} else {
   $acl .= "; Keine ACL: SIP wird von allen Adressen angenommen (TELERIX_SIP_ALLOW leer)\n";
}
atomic_write(data_path('asterisk/pjsip_acl.conf'), $acl, 0640);
