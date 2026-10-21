<?php
   require_once __DIR__ . '/../lib/auth.php';
   telerix_session_start();
   require_post_csrf();

   $wait = throttle_remaining();
   if ($wait > 0) {
      flash('error', 'Zu viele Fehlversuche. Bitte in ' . (int)ceil($wait / 60) . ' Minute(n) erneut versuchen.');
      redirect('index.php');
   }

   $username = post_str('tuser');
   if (admin_verify($username, post_str('tpass'))) {
      /* User ist authentifiziert - Starte Session */
      throttle_reset();
      login_session($username);
      redirect('start.php');
   }

   throttle_fail();
   /* Bremst automatisierte Versuche zusätzlich. */
   sleep(1);
   flash('error', 'Benutzername oder Passwort falsch.');
   redirect('index.php');
