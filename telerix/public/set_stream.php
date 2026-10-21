<?php
$stream = $_POST["inetstream"];
require(__DIR__ . '/../lib/checklogin.php');
require_post_csrf();

/*Stream von allen Sonderzeichen bereinigen*/
/* Erlaubt: A-Z a-z 0-9 . : / -  */
$stream = preg_replace ( '/[^a-z0-9.:\/\-_]/i', '', $stream );
system("../lib/sh/set_stream.sh $stream");
system("../lib/sh/asterisk_restart.sh");

redirect('configuration.php');
?>
