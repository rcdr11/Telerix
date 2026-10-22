<?php
   /* Aktueller Inhalt der Startseite (Icecast-Status, Anrufer) für die
      regelmäßige Aktualisierung durch telerix.js. Zählt nicht als
      Bedienung (keine Verlängerung der Anmeldung). */
   require_once __DIR__ . '/../lib/auth.php';
   require_login(false);
   /* Token der Formulare im Inhalt sicherstellen, dann Sitzung freigeben */
   csrf_token();
   session_write_close();

   header('Content-Type: text/html; charset=UTF-8');
   header('Cache-Control: no-store');
   include __DIR__ . '/../lib/panels/live.php';
