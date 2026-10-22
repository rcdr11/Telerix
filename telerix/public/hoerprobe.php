<?php
   require(__DIR__ . '/../lib/checklogin.php');
   require_once __DIR__ . '/../lib/hoerprobe.php';

   /* Abspielen: hoerprobe.php?f=telefon bzw. ?f=hd */
   if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      $f = $_GET['f'] ?? '';
      if (!is_string($f) || !in_array($f, HOERPROBE_FASSUNGEN, true) || !is_file(hoerprobe_file($f))) {
         http_response_code(404);
         exit('Keine Hörprobe vorhanden.');
      }
      header('Content-Type: audio/wav');
      header('Content-Length: ' . filesize(hoerprobe_file($f)));
      header('Cache-Control: no-store');
      readfile(hoerprobe_file($f));
      exit;
   }

   /* Erstellen mit den Werten aus dem Formular "Ton am Telefon", ohne sie zu
      speichern; gesperrte Felder (.env) werden nicht gesendet und gelten wie
      eingestellt. */
   require_post_csrf();
   $cur = settings_effective();
   try {
      $v = [];
      foreach (['audio_gain_db', 'audio_highpass_hz', 'audio_profile'] as $key) {
         $v[$key] = setting_locked($key) || !isset($_POST[$key]) ? $cur[$key] : setting_check($key, post_str($key));
      }
      hoerprobe_create($v);
      flash('ok', 'Hörprobe erstellt, sie steht unter „Ton am Telefon“. Gespeichert wurde dabei nichts.');
   } catch (SettingsError $e) {
      flash('error', $e->getMessage());
   }
   redirect('configuration.php#ton');
