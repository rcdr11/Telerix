<?php
   require(__DIR__ . '/../lib/checklogin.php');
   require_once __DIR__ . '/../lib/numbers.php';

   if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      require_post_csrf();
      try {
         $n = numbers_upload('uploaddatei');
         flash('ok', "Liste hochgeladen: $n Einträge übernommen.");
      } catch (UploadError $e) {
         flash('error', $e->getMessage());
      }
      redirect('listupload.php');
   }

   include(__DIR__ . '/../lib/layout/navbar.php');
?>
<div class="w3-container w3-padding-32 page-bg">
  <div class="w3-content" style="max-width:980px">

<div class="w3-card w3-panel w3-light-blue w3-padding">
<h3>Telefonnummerliste Upload</h3>
<h5>Bitte hier eine Liste im txt- oder csv-Format (max. 1 MB) mit den Telefonnummern hochladen.<br />
   Nach folgendem beispielhaften Aufbau:<br /><br />Max Mustermann 06011123<br />
   Erika Muster 0123112323<br />John Doe 0232142312<br /><br />
   Mehrere Listen sind nacheinander möglich.<br />
   Neue Listen werden hinten an die bereits vorhandenen angehängt.
</h5>
<br />
</div>
<form name="uploadformular" enctype="multipart/form-data" action="listupload.php" method="post">
<?= csrf_field() ?>
<input type="file" name="uploaddatei" accept=".txt,.csv,text/plain,text/csv" class="w3-button w3-blue" required>
<input type="submit" value="Datei hochladen" class="w3-button w3-dark-gray" style="float:none">
<a href="settings.php" class="w3-button w3-dark-gray">Fertig</a>
</form>

  </div>
</div>

<?php
   include(__DIR__ . '/../lib/layout/footer.php');
?>
