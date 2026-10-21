<div class="container">
  <form action="login.php" method="POST">
  <?= csrf_field() ?>
  <div class="row">
    <div class="col-25">
      <label for="tuser">Benutzer</label>
    </div>
    <div class="col-75">
    <input type="text" id="tuser" name="tuser" placeholder="Dein Telerix Benutzername" autocomplete="username" required>
    </div>
  </div>
  <div class="row">
    <div class="col-25">
      <label for="tpass">Passwort</label>
    </div>
    <div class="col-75">
      <input type="password" id="tpass" name="tpass" placeholder="Dein Telerix Passwort" autocomplete="current-password" required>
    </div>
  </div>
  <br />
  <div class="row">
    <input type="submit" value="Login">
  </div>
  </form>
</div>
