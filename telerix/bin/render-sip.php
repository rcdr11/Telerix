<?php
/*
 * Erzeugt pjsip_trunk.conf beim Containerstart neu aus config/sip.json.
 *
 * Die Datei liegt im Datenvolume und wird sonst nur beim Speichern im
 * Webinterface geschrieben. Ohne diesen Schritt kämen Änderungen an der
 * Vorlage (lib/sip.php) bei einem Update nie an.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/sip.php';

$s = sip_load();
if (sip_validate($s['user'], $s['password'], $s['registrar']) !== null) {
   fwrite(STDERR, "telerix: Gespeicherte SIP-Daten ungültig, pjsip_trunk.conf bleibt unverändert.\n");
   exit(0);
}
atomic_write(sip_trunk_conf_file(), sip_render_trunk($s['user'], $s['password'], $s['registrar']), 0640);
