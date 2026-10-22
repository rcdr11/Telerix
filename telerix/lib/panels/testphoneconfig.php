<?php
   require_once __DIR__ . '/../settings.php';
   $active = settings_effective()['testphone_password'] !== '';
   $locked = setting_locked('testphone_password');
   $stored = settings_stored()['testphone_password'] !== '';
?>
<div class="container">
  <form action="set_testphone.php" method="POST">
  <?= csrf_field() ?>
  <div class="row">
    <div class="col-25">
      <label for="testphone_enabled">Aktiv</label>
    </div>
    <div class="col-75">
      <label><input type="checkbox" id="testphone_enabled" name="testphone_enabled" value="1"<?= $active ? ' checked' : '' ?><?= $locked ? ' disabled' : '' ?>>
         Testtelefon &bdquo;testphone&ldquo; einrichten</label>
    </div>
  </div>
  <div class="row">
    <div class="col-25">
      <label for="testphone_pw">Passwort</label>
    </div>
    <div class="col-75">
      <input type="password" id="testphone_pw" name="testphone_pw" autocomplete="new-password"<?= $locked ? ' disabled' : '' ?>
             placeholder="<?= $stored ? 'Gespeichert – leer lassen, um es beizubehalten' : 'mindestens ' . TESTPHONE_PASSWORD_MIN_LENGTH . ' Zeichen aus A-Z, a-z, 0-9 und . _ + -' ?>">
<?php if ($locked): ?>
      <p class="hint"><?= h(setting_locked_text('testphone_password')) ?></p>
<?php endif; ?>
    </div>
  </div>
  <p class="hint">Ein Softphone im eigenen Netz meldet sich mit Benutzer <b>testphone</b> und diesem Passwort an
     (Server: Adresse dieses Rechners, Port 5060/UDP, der dazu freigegeben sein muss). Die Nummer 100 spielt einen
     Piepton und dann den Stream. Gilt sofort.</p>
  <div class="row">
    <input type="submit" value="Speichern und Anwenden"<?= $locked ? ' disabled' : '' ?>>
  </div>
  </form>
</div>
