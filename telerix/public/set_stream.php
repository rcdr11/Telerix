<?php
require(__DIR__ . '/../lib/checklogin.php');
require_post_csrf();
require_once __DIR__ . '/../lib/stream.php';
require_once __DIR__ . '/../lib/asterisk.php';

/* Statt Sonderzeichen still zu entfernen, wird die Adresse geprüft. */
$stream = stream_normalize(post_str('inetstream'));
if ($stream === null) {
   flash('error', 'Ungültige Stream-Adresse. Erwartet wird z. B. http://www.beispiel.de:8000/stream');
   redirect('configuration.php');
}

stream_save($stream);

/* Nur die Wartemusik neu laden, laufende Gespräche bleiben bestehen. */
if (asterisk_reload_moh()) {
   flash('ok', 'Stream-Adresse gespeichert.');
} else {
   flash('error', 'Stream-Adresse gespeichert, Asterisk konnte aber nicht neu laden.');
}

redirect('configuration.php');
