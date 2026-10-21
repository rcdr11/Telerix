<?php
   require(__DIR__ . '/../lib/checklogin.php');
   require_post_csrf();
   require_once __DIR__ . '/../lib/numbers.php';

   numbers_delete_all();
   flash('ok', 'Die Nummernliste wurde gelöscht.');
   redirect('settings.php');
