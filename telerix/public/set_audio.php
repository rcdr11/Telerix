<?php
require(__DIR__ . '/../lib/checklogin.php');
require_post_csrf();
require_once __DIR__ . '/../lib/settings.php';

/* Felder, die über .env festgelegt sind, werden nicht übernommen. */
$changes = [];
foreach (['audio_processing', 'audio_input_db', 'audio_gain_db', 'audio_buffer_s', 'audio_profile',
      'audio_highpass_hz', 'audio_presence_db'] as $key) {
   if (!setting_locked($key) && isset($_POST[$key])) {
      $changes[$key] = post_str($key);
   }
}

try {
   settings_save($changes);
   /* asterisk/stream.sh bemerkt die geänderte audio.conf selbst. */
   flash('ok', 'Toneinstellungen gespeichert. Sie gelten nach wenigen Sekunden.');
} catch (SettingsError $e) {
   flash('error', $e->getMessage());
}

redirect('configuration.php#ton');
