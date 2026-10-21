<?php
   require(__DIR__ . '/../lib/checklogin.php');
   require_once __DIR__ . '/../lib/asterisk.php';
   require_once __DIR__ . '/../lib/stream.php';
   require_once __DIR__ . '/../lib/numbers.php';

   $streamUrl = stream_load();
   $streams = $streamUrl === '' ? [] : icecast_status($streamUrl);
   $running = asterisk_running();
   $callers = $running ? asterisk_callers() : [];
   $entries = $callers ? numbers_all() : [];

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

  <!-- Streamübersichtkasten begin -->
  <div class="w3-panel w3-card-4 w3-blue">
  <h3 style="color: #ffffff">Internetradiostreams</h3>
  <table class="w3-table w3-striped w3-light-blue" style="border-radius:5px">
    <thead>
      <tr class="w3-dark-gray">
        <th>Stream</th>
        <th>Zuh&ouml;rer</th>
        <th>max. Zuh&ouml;rer</th>
      </tr>
    </thead>
<?php if ($streamUrl === ''): ?>
    <tr><td colspan="3">Keine Stream-Adresse eingerichtet</td></tr>
<?php elseif ($streams === null): ?>
    <tr><td colspan="3">Icecast-Status nicht abrufbar</td></tr>
<?php elseif (!$streams): ?>
    <tr><td colspan="3">Kein Stream aktiv</td></tr>
<?php else: foreach ($streams as $s): ?>
    <tr>
      <td><?php if ($s['url'] !== ''): ?><a href="<?= h($s['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($s['mount']) ?></a><?php else: ?><?= h($s['mount']) ?><?php endif; ?></td>
      <td><?= $s['listeners'] ?></td>
      <td><?= $s['peak'] ?></td>
    </tr>
<?php endforeach; endif; ?>
  </table>
  <br />
  </div>
  <!-- Streamübersichtkasten end -->

 <div class="w3-panel w3-card-4 w3-blue">
  <h3 style="color: #ffffff">Aktuelle Zuh&ouml;rer</h3>

  <!-- Zuhoererliste begin -->
  <div class="w3-panel w3-light-gray" >
  <ul class="w3-ul w3-center">
<?php if (!$callers): ?>
   <li>Aktuell kein Anrufer</li>
<?php else: foreach ($callers as $c): ?>
   <li><?= h(caller_display($c, $entries)) ?></li>
<?php endforeach; endif; ?>
   </ul>
   </div>
   <!-- Zuhoererliste end -->

   <div class="w3-panel w3-light-blue" style="border-radius: 5px">
   <p>Zuh&ouml;rer: <?= count($callers) ?></p>
   </div>
</div>

  </div>
</div>
<?php
   include(__DIR__ . '/../lib/layout/footer.php');
?>
