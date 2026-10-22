<?php
require(__DIR__ . '/../lib/checklogin.php');
require_post_csrf();
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/asterisk.php';

if (setting_locked('testphone_password')) {
   flash('error', setting_locked_text('testphone_password'));
   redirect('configuration.php');
}

/* Leeres Passwortfeld = bisheriges Passwort behalten (es wird nicht angezeigt). */
$password = '';
if (post_str('testphone_enabled') === '1') {
   $password = post_str('testphone_pw');
   if ($password === '') {
      $password = settings_stored()['testphone_password'];
   }
   if ($password === '') {
      flash('error', 'Bitte ein Passwort für das Testtelefon eingeben.');
      redirect('configuration.php');
   }
}

try {
   settings_save(['testphone_password' => $password]);
   if (!asterisk_reload_pjsip()) {
      flash('error', 'Testtelefon gespeichert, Asterisk konnte aber nicht neu laden.');
   } else {
      flash('ok', $password === '' ? 'Testtelefon abgeschaltet.' : 'Testtelefon eingerichtet.');
   }
} catch (SettingsError $e) {
   flash('error', $e->getMessage());
}

redirect('configuration.php');
