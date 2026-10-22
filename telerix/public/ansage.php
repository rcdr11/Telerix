<?php
   require(__DIR__ . '/../lib/checklogin.php');
   require_once __DIR__ . '/../lib/audio.php';
   require_once __DIR__ . '/../lib/settings.php';

   if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      require_post_csrf();
      try {
         $action = post_str('action');
         if ($action === 'enable' || $action === 'disable') {
            settings_save(['announcement' => $action === 'enable']);
            flash('ok', $action === 'enable'
               ? 'Begrüßungsansage eingeschaltet. Sie gilt ab dem nächsten Anruf.'
               : 'Begrüßungsansage abgeschaltet. Anrufer hören ab dem nächsten Anruf sofort den Stream.');
         } elseif ($action === 'delete') {
            announcement_delete();
            flash('ok', 'Eigene Ansage entfernt, die Standardansage wird wieder verwendet.');
         } else {
            announcement_upload('ansage');
            flash('ok', settings_effective()['announcement']
               ? 'Neue Ansage gespeichert. Sie gilt ab dem nächsten Anruf.'
               : 'Neue Ansage gespeichert. Die Begrüßungsansage ist abgeschaltet; zum Abspielen oben einschalten.');
         }
      } catch (UploadError | SettingsError $e) {
         flash('error', $e->getMessage());
      }
      redirect('ansage.php');
   }

   $enabled = settings_effective()['announcement'];

   include(__DIR__ . '/../lib/layout/navbar.php');
?>
<div class="w3-container w3-padding-32 page-bg">
  <div class="w3-content" style="max-width:980px">

 <div class="w3-panel w3-card-4 w3-blue">
  <h3 style="color: #ffffff">Begr&uuml;&szlig;ungsansage</h3>
  <div class="w3-panel w3-light-gray" style="border-radius:5px">
   <p>Aktuell: <b><?= !$enabled ? 'keine Ansage (abgeschaltet)' : (announcement_exists() ? 'eigene Ansage' : 'Standardansage (willkommen-gottesdienst)') ?></b></p>
   <form action="ansage.php" method="post">
     <?= csrf_field() ?>
     <input type="hidden" name="action" value="<?= $enabled ? 'disable' : 'enable' ?>">
     <button type="submit" class="w3-button w3-dark-gray"><?= $enabled ? 'Ansage abschalten' : 'Ansage einschalten' ?></button>
   </form>
   <p class="hint"><?= $enabled
      ? 'Abgeschaltet hören Anrufer gleich nach dem Annehmen den Stream. Eine hochgeladene Ansage bleibt dabei gespeichert.'
      : 'Anrufer hören gleich nach dem Annehmen den Stream.' ?></p>
   <h5>Eigene Ansage</h5>
   <p class="hint">Erlaubt sind <?= h(implode(', ', array_keys(AUDIO_ALLOWED))) ?> bis
      <?= AUDIO_MAX_BYTES / 1024 / 1024 ?> MB. Die Datei wird f&uuml;r das Telefon in 8 kHz Mono umgewandelt
      und nach <?= AUDIO_MAX_SECONDS / 60 ?> Minuten abgeschnitten.</p>
   <form enctype="multipart/form-data" action="ansage.php" method="post">
     <?= csrf_field() ?>
     <input type="file" name="ansage" accept=".wav,.mp3,.ogg,.flac,audio/*" class="w3-button w3-blue" required>
     <button type="submit" class="w3-button w3-dark-gray">Ansage hochladen</button>
   </form>
<?php if (announcement_exists()): ?>
   <br />
   <form action="ansage.php" method="post" data-confirm="Eigene Ansage löschen und zur Standardansage zurückkehren?">
     <?= csrf_field() ?>
     <input type="hidden" name="action" value="delete">
     <button type="submit" class="w3-button w3-dark-gray">Eigene Ansage l&ouml;schen</button>
   </form>
<?php endif; ?>
   <br />
  </div>
 </div>

  </div>
</div>

<?php
   include(__DIR__ . '/../lib/layout/footer.php');
?>
