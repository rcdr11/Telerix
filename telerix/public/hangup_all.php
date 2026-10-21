<?php
   require(__DIR__ . '/../lib/checklogin.php');
   require_post_csrf();
   $a = system("../lib/sh/hangup_all.sh");
   redirect('start.php');
?>
