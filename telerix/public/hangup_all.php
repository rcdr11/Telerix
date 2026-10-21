<?php
   require(__DIR__ . '/../lib/checklogin.php');
   require_post_csrf();
   require_once __DIR__ . '/../lib/asterisk.php';

   if (asterisk_hangup_all()) {
      flash('ok', 'Alle Anrufer wurden aufgelegt.');
   } else {
      flash('error', 'Asterisk ist nicht erreichbar.');
   }
   redirect('start.php');
