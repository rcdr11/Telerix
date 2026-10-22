<?php
require(__DIR__ . '/../lib/checklogin.php');
require_post_csrf();
require_once __DIR__ . '/../lib/settings.php';

/* Ausblenden direkt von der Startseite aus */
if (post_str('action') === 'hide') {
   settings_save(['icecast_panel' => false]);
   flash('ok', 'Icecast-Status ausgeblendet. Wieder einblenden unter Konfiguration → Internetstream.');
   redirect('start.php');
}

try {
   settings_save([
      'icecast_status_url' => post_str('icecast_status_url'),
      'icecast_panel' => post_str('icecast_panel') === '1',
   ]);
   flash('ok', 'Einstellungen zum Icecast-Status gespeichert.');
} catch (SettingsError $e) {
   flash('error', $e->getMessage());
}

redirect('configuration.php#stream');
