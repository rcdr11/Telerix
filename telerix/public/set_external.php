<?php
require(__DIR__ . '/../lib/checklogin.php');
require_post_csrf();
require_once __DIR__ . '/../lib/settings.php';

if (setting_locked('external_address')) {
   flash('error', setting_locked_text('external_address'));
   redirect('configuration.php');
}

$before = settings_effective()['external_address'];
try {
   settings_save(['external_address' => post_str('external_address')]);
   if (settings_effective()['external_address'] === $before) {
      flash('ok', 'Öffentliche Adresse unverändert.');
   } else {
      /* PJSIP lädt Transporte nicht neu, daher erst nach einem Neustart. */
      flash('ok', 'Öffentliche Adresse gespeichert. Sie gilt nach einem Neustart von Asterisk; laufende Anrufe werden dabei getrennt.');
   }
} catch (SettingsError $e) {
   flash('error', $e->getMessage());
}

redirect('configuration.php');
