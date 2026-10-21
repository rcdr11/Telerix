<?php
/*
 * Seitenkopf mit Navigation. Ohne Anmeldung (Login-Seite) wird nur das
 * Logo gezeigt. Schriften und Icons werden nicht mehr von externen CDNs
 * geladen.
 */
require_once __DIR__ . '/../auth.php';
$loggedIn = is_logged_in();
?>
<!DOCTYPE html>
<html lang="de">
<head>
<title>TELERIX</title>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="w3.css">
<link rel="stylesheet" href="telerix.css">
<link rel="icon" href="favicon.ico" type="image/x-icon">
<script src="telerix.js" defer></script>
</head>
<body>
<!-- Navbar -->
<div class="w3-top">
  <div class="w3-bar w3-blue w3-card w3-left-align w3-large">
<?php if ($loggedIn): ?>
    <a class="w3-bar-item w3-button w3-hide-medium w3-hide-large w3-right w3-padding-large w3-hover-white w3-large w3-red" href="#" data-toggle="navsmall" title="Navigation ein-/ausblenden">&#9776;</a>
    <a href="start.php" class="w3-bar-item w3-button w3-padding-large w3-light-blue">TELERIX</a>
    <a href="settings.php" class="w3-bar-item w3-button w3-hide-small w3-padding-large w3-hover-white">Nummerverwaltung</a>
    <a href="ansage.php" class="w3-bar-item w3-button w3-hide-small w3-padding-large w3-hover-white">Ansage</a>
    <a href="configuration.php" class="w3-bar-item w3-button w3-hide-small w3-padding-large w3-hover-white">Konfiguration</a>
    <a href="help.php" class="w3-bar-item w3-button w3-hide-small w3-padding-large w3-hover-white">Hilfe</a>
    <a href="info.php" class="w3-bar-item w3-button w3-hide-small w3-padding-large w3-hover-white">Info</a>
    <form action="logout.php" method="POST" class="w3-right w3-hide-small">
      <?= csrf_field() ?>
      <button type="submit" class="w3-bar-item w3-button w3-padding-large w3-hover-red">Logout</button>
    </form>
<?php else: ?>
    <a href="index.php" class="w3-bar-item w3-button w3-padding-large w3-light-blue">TELERIX</a>
<?php endif; ?>
  </div>

<?php if ($loggedIn): ?>
  <!-- Navbar on small screens -->
  <div id="navsmall" class="w3-bar-block w3-hide w3-white w3-hide-large w3-hide-medium w3-large">
    <a href="settings.php" class="w3-bar-item w3-button w3-padding-large">Nummerverwaltung</a>
    <a href="ansage.php" class="w3-bar-item w3-button w3-padding-large">Ansage</a>
    <a href="configuration.php" class="w3-bar-item w3-button w3-padding-large">Konfiguration</a>
    <a href="help.php" class="w3-bar-item w3-button w3-padding-large">Hilfe</a>
    <a href="info.php" class="w3-bar-item w3-button w3-padding-large">Info</a>
    <form action="logout.php" method="POST">
      <?= csrf_field() ?>
      <button type="submit" class="w3-bar-item w3-button w3-padding-large">Logout</button>
    </form>
  </div>
<?php endif; ?>
</div>
<!-- Platzhalter in Höhe der fixierten Navigation -->
<div class="navbar-spacer"></div>
<?php foreach (take_flashes() as $f): ?>
<div class="w3-content flash flash-<?= h($f['type']) ?>" style="max-width:980px"><?= h($f['msg']) ?></div>
<?php endforeach; ?>
