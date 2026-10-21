<?php
   require(__DIR__ . '/../lib/checklogin.php');
   $a = system("rm ./uploads/*");
   header('Location: settings.php');
?>
