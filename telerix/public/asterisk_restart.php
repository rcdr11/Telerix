<?php
   require(__DIR__ . '/../lib/checklogin.php');
   $a = system('../lib/sh/asterisk_restart.sh');
   header('Location: start.php');
?>

