<?php
   require(__DIR__ . '/../lib/checklogin.php');
   require_once __DIR__ . '/../lib/asterisk.php';

   /* Anrufer und Icecast-Status werden so oft neu geladen */
   const LIVE_REFRESH_SECONDS = 5;

   $running = asterisk_running();

   include(__DIR__ . '/../lib/layout/navbar.php');
?>
<div class="w3-container w3-padding-32 page-bg">
  <div class="w3-content" style="max-width:980px">

  <!-- Optionen Panel Begin-->
  <div class="w3-panel w3-card-4 w3-blue">
  <h3 style="color: #ffffff">Optionen</h3>
  <!-- Buttonbar begin -->
  <div class="w3-bar">
  <form action="hangup_all.php" method="POST" class="inline" data-confirm="Alle Anrufer auflegen?">
     <?= csrf_field() ?>
     <button type="submit" class="w3-button w3-dark-gray w3-round" style="margin-bottom: 5px">Alle Anrufer auflegen</button>
  </form>
  <form action="asterisk_restart.php" method="POST" class="inline" data-confirm="Asterisk neu starten? Alle bestehenden Verbindungen werden damit unterbrochen.">
     <?= csrf_field() ?>
     <button type="submit" class="w3-button w3-dark-gray w3-round" style="margin-bottom: 5px">Asterisk neu starten</button>
  </form>
  </div>
  <p>Asterisk: <?= $running ? 'l&auml;uft' : '<b>nicht erreichbar</b>' ?></p>
 </div>
  <!-- Optionen Panel End-->

  <!-- Wird alle LIVE_REFRESH_SECONDS Sekunden neu geladen (start_status.php) -->
  <div id="live" data-refresh="start_status.php" data-refresh-seconds="<?= LIVE_REFRESH_SECONDS ?>">
<?php include __DIR__ . '/../lib/panels/live.php'; ?>
  </div>

  </div>
</div>
<?php
   include(__DIR__ . '/../lib/layout/footer.php');
?>
