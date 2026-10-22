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
      eingestellt. Aus telerix.js (Accept: application/json) kommt die Antwort
      als JSON mit dem neuen Ergebnis-Block, die Seite lädt dann nicht neu;
      ohne JavaScript wie gewohnt per Weiterleitung. */
   require_post_csrf();
   $ajax = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
   $cur = settings_effective();
   /* Die Aufnahme dauert etwa 20 s: Sitzung solange freigeben, sonst warten
      andere Seiten derselben Anmeldung (z. B. die Startseite) so lange. */
   session_write_close();
   $ok = false;
   try {
      $v = [];
      foreach (['audio_gain_db', 'audio_highpass_hz', 'audio_profile'] as $key) {
         $v[$key] = setting_locked($key) || !isset($_POST[$key]) ? $cur[$key] : setting_check($key, post_str($key));
      }
      hoerprobe_create($v);
      $ok = true;
      $msg = 'Hörprobe erstellt. Gespeichert wurde dabei nichts.';
   } catch (SettingsError $e) {
      $msg = $e->getMessage();
   }

   if ($ajax) {
      ob_start();
      include __DIR__ . '/../lib/panels/hoerprobe_result.php';
      header('Content-Type: application/json');
      header('Cache-Control: no-store');
      echo json_encode(['ok' => $ok, 'message' => $msg, 'html' => ob_get_clean()]);
      exit;
   }
   telerix_session_start();
   flash($ok ? 'ok' : 'error', $msg);
   redirect('configuration.php#ton');
