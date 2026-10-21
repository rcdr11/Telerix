<?php
   require(__DIR__ . '/../lib/checklogin.php');
   require_post_csrf();
   $a = system("rm ./uploads/*");
   redirect('settings.php');
?>
