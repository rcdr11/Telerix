<?php
require(__DIR__ . '/../lib/checklogin.php');
require_post_csrf();
require_once __DIR__ . '/../lib/stream.php';

/* Statt Sonderzeichen still zu entfernen, wird die Adresse geprüft. */
$stream = stream_normalize(post_str('inetstream'));
if ($stream === null) {
   flash('error', 'Ungültige Stream-Adresse. Erwartet wird z. B. http://www.beispiel.de:8000/stream');
   redirect('configuration.php');
}

/* asterisk/stream.sh bemerkt die neue Adresse selbst und wechselt innerhalb
   weniger Sekunden; laufende Gespräche bleiben bestehen. */
stream_save($stream);
flash('ok', 'Stream-Adresse gespeichert. Sie wird innerhalb weniger Sekunden übernommen.');

redirect('configuration.php');
