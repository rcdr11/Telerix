<?php
   require(__DIR__ . '/../lib/checklogin.php');
   require_post_csrf();

   $user = post_str('telerix_user');
   $new = post_str('telerix_new_pw_a');

   if (!admin_verify((string)($_SESSION['user'] ?? ''), post_str('telerix_old_pw'))) {
      flash('error', 'Das aktuelle Passwort ist falsch.');
   } elseif (!valid_username($user)) {
      flash('error', 'Benutzername: nur A-Z, a-z, 0-9, Punkt, Unterstrich und Bindestrich (max. 64 Zeichen).');
   } elseif ($new !== post_str('telerix_new_pw_b')) {
      flash('error', 'Die neuen Passwörter stimmen nicht überein.');
   } elseif (strlen($new) < PASSWORD_MIN_LENGTH) {
      flash('error', 'Das neue Passwort muss mindestens ' . PASSWORD_MIN_LENGTH . ' Zeichen lang sein.');
   } else {
      admin_save($user, $new);
      /* Neue Session-ID nach Änderung der Zugangsdaten */
      login_session($user);
      flash('ok', 'Zugangsdaten wurden geändert.');
   }

   //Zeige Seite
   redirect('configuration.php');
