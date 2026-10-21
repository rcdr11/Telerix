<?php
   require(__DIR__ . '/../lib/checklogin.php');
   require_once __DIR__ . '/../lib/backup.php';
   require_once __DIR__ . '/../lib/asterisk.php';

   if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      require_post_csrf();
      try {
         if (post_str('action') === 'create') {
            if (post_str('backup_pw') !== post_str('backup_pw2')) {
               throw new BackupError('Die beiden Backup-Passwörter stimmen nicht überein.');
            }
            $blob = backup_create(post_str('backup_pw'));
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="telerix-backup-' . gmdate('Ymd-Hi') . '.tlxbak"');
            header('Content-Length: ' . strlen($blob));
            header('Cache-Control: no-store');
            echo $blob;
            exit;
         }

         if (post_str('action') === 'restore') {
            /* Kein MIME-Check nötig: Der Inhalt ist authentifiziert verschlüsselt,
               alles andere wird von backup_open() abgewiesen. */
            $f = $_FILES['backup'] ?? null;
            if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                  || !is_uploaded_file($f['tmp_name'] ?? '')) {
               throw new BackupError('Keine Sicherungsdatei ausgewählt oder Upload fehlgeschlagen.');
            }
            if ($f['size'] > BACKUP_MAX_BYTES) {
               throw new BackupError('Die Sicherungsdatei ist zu groß.');
            }
            $values = backup_validate(backup_open(file_get_contents($f['tmp_name']), post_str('restore_pw')));
            backup_apply($values);
            /* Admin-Zugang kann sich geändert haben: Session darauf umstellen. */
            login_session($values['admin']['user']);
            if (asterisk_reload_pjsip()) {
               flash('ok', 'Sicherung eingespielt. Es gelten jetzt die Zugangsdaten aus der Sicherung (Benutzer: ' . $values['admin']['user'] . ').');
            } else {
               flash('error', 'Sicherung eingespielt, Asterisk konnte die SIP-Konfiguration aber nicht neu laden.');
            }
         }
      } catch (BackupError | UploadError $e) {
         flash('error', $e->getMessage());
      }
      redirect('backup.php');
   }

   include(__DIR__ . '/../lib/layout/navbar.php');
?>
<div class="w3-container w3-padding-32 page-bg">
  <div class="w3-content" style="max-width:980px">

 <div class="w3-panel w3-card-4 w3-blue">
  <h3 style="color: #ffffff">Sicherung erstellen</h3>
  <div class="container">
   <p style="color:#333">Die Sicherung enth&auml;lt Admin-Zugang, SIP-Zugang (mit Passwort), Stream-Adresse,
      Nummernliste und die eigene Ansage. Sie wird mit dem hier gew&auml;hlten Passwort verschl&uuml;sselt.
      <b>Ohne dieses Passwort l&auml;sst sie sich nicht wieder einspielen.</b></p>
   <form action="backup.php" method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div class="row">
      <div class="col-25"><label for="backup_pw">Backup-Passwort</label></div>
      <div class="col-75"><input type="password" id="backup_pw" name="backup_pw" autocomplete="new-password"
         minlength="<?= BACKUP_PASSWORD_MIN_LENGTH ?>" placeholder="mindestens <?= BACKUP_PASSWORD_MIN_LENGTH ?> Zeichen" required></div>
    </div>
    <div class="row">
      <div class="col-25"><label for="backup_pw2">Wiederholung</label></div>
      <div class="col-75"><input type="password" id="backup_pw2" name="backup_pw2" autocomplete="new-password"
         minlength="<?= BACKUP_PASSWORD_MIN_LENGTH ?>" required></div>
    </div>
    <br />
    <div class="row"><input type="submit" value="Sicherung herunterladen"></div>
   </form>
  </div>
  <br />
 </div>

 <div class="w3-panel w3-card-4 w3-blue">
  <h3 style="color: #ffffff">Sicherung einspielen</h3>
  <div class="container">
   <p style="color:#333">Ersetzt die aktuelle Konfiguration vollst&auml;ndig durch die Sicherung &ndash;
      einschlie&szlig;lich des Admin-Zugangs. Anschlie&szlig;end gelten Benutzername und Passwort aus der Sicherung.</p>
   <form action="backup.php" method="POST" enctype="multipart/form-data"
         data-confirm="Aktuelle Konfiguration durch die Sicherung ersetzen?">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="restore">
    <div class="row">
      <div class="col-25"><label for="backup">Datei</label></div>
      <div class="col-75"><input type="file" id="backup" name="backup" accept=".tlxbak" class="w3-button w3-white" required></div>
    </div>
    <div class="row">
      <div class="col-25"><label for="restore_pw">Backup-Passwort</label></div>
      <div class="col-75"><input type="password" id="restore_pw" name="restore_pw" autocomplete="off" required></div>
    </div>
    <br />
    <div class="row"><input type="submit" value="Sicherung einspielen"></div>
   </form>
  </div>
  <br />
 </div>

  </div>
</div>

<?php
   include(__DIR__ . '/../lib/layout/footer.php');
?>
