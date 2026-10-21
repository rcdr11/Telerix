<?php
require(__DIR__ . '/../lib/checklogin.php');
require_post_csrf();
require_once __DIR__ . '/../lib/sip.php';
require_once __DIR__ . '/../lib/asterisk.php';

$old = sip_load();
$sip_id = trim(post_str('sip_id'));
$sip_password = post_str('sip_pw');
$sip_registrar = sip_normalize_registrar(post_str('sip_reg'));

/* Leeres Passwortfeld = bisheriges Passwort behalten (es wird nicht angezeigt). */
if ($sip_password === '' && $sip_id !== '') {
   $sip_password = $old['password'];
}

/* Nur prüfen, nicht still "bereinigen": ungültige Eingaben werden abgelehnt. */
$error = sip_validate($sip_id, $sip_password, $sip_registrar);
if ($error !== null) {
   flash('error', $error);
   redirect('configuration.php');
}

sip_save($sip_id, $sip_password, $sip_registrar);

if (asterisk_reload_pjsip()) {
   flash('ok', $sip_id === '' ? 'SIP-Registrierung entfernt.' : 'SIP-Konfiguration gespeichert und geladen.');
} else {
   flash('error', 'SIP-Konfiguration gespeichert, Asterisk konnte aber nicht neu laden.');
}

redirect('configuration.php');
