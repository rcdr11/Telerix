<?php
   require(__DIR__ . '/../lib/checklogin.php');
   $a = system("../lib/sh/hangup_all.sh");
   header('Location: start.php');
?>
