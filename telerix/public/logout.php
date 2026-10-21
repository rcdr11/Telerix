<?php
   require_once __DIR__ . '/../lib/auth.php';
   telerix_session_start();
   require_post_csrf();

   /* Lösche die Session */
   logout_session();

   redirect('index.php');
