<?php
/* Für alle geschützten Seiten einzubinden: prüft die Anmeldung. */
require_once __DIR__ . '/auth.php';
require_login();
