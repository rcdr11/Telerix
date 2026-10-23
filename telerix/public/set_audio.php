<?php
require(__DIR__ . '/../lib/checklogin.php');
require_post_csrf();
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/asterisk.php';

/* Felder, die über .env festgelegt sind, werden nicht übernommen. */
$changes = [];
foreach (['audio_processing', 'audio_input_db', 'audio_gain_db', 'audio_buffer_s', 'audio_profile',
      'audio_highpass_hz', 'audio_presence_db', 'audio_codec'] as $key) {
   if (!setting_locked($key) && isset($_POST[$key])) {
      $changes[$key] = post_str($key);
   }
}

try {
   $codecBefore = settings_effective()['audio_codec'];
   /* Schreibt auch Trunk und Ansage neu (settings_render) */
   settings_save($changes);
   /* asterisk/stream.sh bemerkt die geänderte audio.conf selbst. Der Codec
      steht in pjsip_trunk.conf und gilt nach dem Neuladen von PJSIP für neue
      Anrufe; laufende Anrufe bleiben bestehen. */
   if (settings_effective()['audio_codec'] !== $codecBefore && !asterisk_reload_endpoints()) {
      flash('error', 'Toneinstellungen gespeichert, Asterisk konnte den Codec aber nicht neu laden.');
   } else {
      flash('ok', 'Toneinstellungen gespeichert. Sie gelten nach wenigen Sekunden.');
   }
} catch (SettingsError $e) {
   flash('error', $e->getMessage());
}

redirect('configuration.php#ton');
