<div class="container">
  <form action="action_change_pass.php" method="POST">
  <?= csrf_field() ?>
  <!-- Username -->
  <div class="row">
    <div class="col-25">
      <label for="telerix_user">Benutzername</label>
    </div>
    <div class="col-75">
    <input type="text" id="telerix_user" name="telerix_user" placeholder="Dein Benutzername" autocomplete="username" value="<?= h((string)($_SESSION['user'] ?? '')) ?>" required>
    </div>
  </div>
  <!-- End -->
  <div class="row">
    <div class="col-25">
      <label for="telerix_old_pw">Altes Passwort</label>
    </div>
    <div class="col-75">
    <input type="password" id="telerix_old_pw" name="telerix_old_pw" placeholder="Dein aktuelles Passwort" autocomplete="current-password" required>
    </div>
  </div>
  <div class="row">
    <div class="col-25">
      <label for="telerix_new_pw_a">Neues Passwort</label>
    </div>
    <div class="col-75">
      <input type="password" id="telerix_new_pw_a" name="telerix_new_pw_a" placeholder="Das neue Passwort (mindestens <?= PASSWORD_MIN_LENGTH ?> Zeichen)" autocomplete="new-password" minlength="<?= PASSWORD_MIN_LENGTH ?>" required>
    </div>
  </div>
  <div class="row">
   <div class="col-25">
      <label for="telerix_new_pw_b">Neues Passwort</label>
    </div>
    <div class="col-75">
      <input type="password" id="telerix_new_pw_b" name="telerix_new_pw_b" placeholder="Die Wiederholung des neuen Passworts" autocomplete="new-password" minlength="<?= PASSWORD_MIN_LENGTH ?>" required>
    </div>
  </div>
  <br />
  <div class="row">
    <input type="submit" value="Speichern und Anwenden">
  </div>
  </form>
</div>
