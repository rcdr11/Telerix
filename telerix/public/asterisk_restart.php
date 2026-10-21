<?php
   require(__DIR__ . '/../lib/checklogin.php');
   require_post_csrf();
   $a = system('../lib/sh/asterisk_restart.sh');
   redirect('start.php');
?>

