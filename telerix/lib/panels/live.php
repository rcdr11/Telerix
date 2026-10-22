<?php
   /*
    * Startseite: Icecast-Status und aktuelle Anrufer. Wird von start.php
    * eingebunden und von start_status.php für die regelmäßige
    * Aktualisierung ausgeliefert (telerix.js, data-refresh).
    */
   require_once __DIR__ . '/../asterisk.php';
   require_once __DIR__ . '/../settings.php';
   require_once __DIR__ . '/../numbers.php';

   $streamUrl = stream_load();
   $ice = settings_effective();
   /* Ausgeblendet: gar nicht erst abfragen */
   $iceStatus = $ice['icecast_panel'] && ($streamUrl !== '' || $ice['icecast_status_url'] !== '')
      ? icecast_status($streamUrl, $ice['icecast_status_url']) : null;
   $callers = asterisk_callers();
   $entries = $callers ? numbers_all() : [];
?>
  <!-- Streamübersichtkasten begin -->
<?php if ($ice['icecast_panel']): ?>
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
<?php if ($iceStatus === null): ?>
    <tr><td colspan="3">Keine Stream-Adresse eingerichtet</td></tr>
<?php elseif ($iceStatus['error'] !== null): ?>
    <tr><td colspan="3">Icecast-Status nicht abrufbar: <?= h($iceStatus['error']) ?><br />
       <span class="hint">Abgefragt: <?= h($iceStatus['url']) ?></span></td></tr>
<?php elseif (!$iceStatus['rows']): ?>
    <tr><td colspan="3">Kein Stream aktiv</td></tr>
<?php else: foreach ($iceStatus['rows'] as $s): ?>
    <tr>
      <td><?php if ($s['url'] !== ''): ?><a href="<?= h($s['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($s['mount']) ?></a><?php else: ?><?= h($s['mount']) ?><?php endif; ?></td>
      <td><?= $s['listeners'] ?></td>
      <td><?= $s['peak'] ?></td>
    </tr>
<?php endforeach; endif; ?>
  </table>
<?php if ($iceStatus !== null && $iceStatus['error'] === null && $iceStatus['rows']): ?>
  <p class="hint" style="color:#fff"><?= $iceStatus['own'] ? '' : 'Alle Mountpoints des Servers (der eigene lie&szlig; sich nicht zuordnen). ' ?>Telerix
     selbst z&auml;hlt dabei als ein Zuh&ouml;rer, w&auml;hrend einer H&ouml;rprobe als zwei. Die Anzeige wird
     alle <?= LIVE_REFRESH_SECONDS ?> Sekunden aktualisiert.</p>
<?php elseif ($iceStatus !== null && $iceStatus['error'] !== null): ?>
  <div>
  <a href="configuration.php#stream" class="w3-button w3-dark-gray w3-round" style="margin-bottom: 5px">Statusadresse einstellen</a>
  <form action="set_icecast.php" method="POST" class="inline" data-confirm="Icecast-Status ausblenden? Wieder einblenden unter Konfiguration → Internetstream.">
     <?= csrf_field() ?>
     <input type="hidden" name="action" value="hide">
     <button type="submit" class="w3-button w3-dark-gray w3-round" style="margin-bottom: 5px">Ausblenden</button>
  </form>
  </div>
<?php endif; ?>
  <br />
  </div>
<?php endif; ?>
  <!-- Streamübersichtkasten end -->

 <div class="w3-panel w3-card-4 w3-blue">
  <h3 style="color: #ffffff">Aktuelle Zuh&ouml;rer</h3>

  <!-- Zuhoererliste begin -->
  <div class="w3-panel w3-light-gray" >
  <ul class="w3-ul w3-center">
<?php if (!$callers): ?>
   <li>Aktuell kein Anrufer</li>
<?php else: foreach ($callers as $c): ?>
   <li><?= h(caller_display($c['caller'], $entries)) ?> <span class="hint">&ndash; <?= h(codec_label($c['codec'])[0]) ?></span></li>
<?php endforeach; endif; ?>
   </ul>
   </div>
   <!-- Zuhoererliste end -->

   <div class="w3-panel w3-light-blue" style="border-radius: 5px">
   <p>Zuh&ouml;rer: <?= count($callers) ?><?php
      $hd = count(array_filter($callers, fn($c) => codec_label($c['codec'])[1]));
      if ($callers): ?>, davon in HD: <?= $hd ?><?php endif; ?></p>
<?php if ($callers): ?>
   <p class="hint">Angezeigt wird der Codec zwischen Anbieter und Telerix. Bei &bdquo;Telefonqualit&auml;t&ldquo;
      h&ouml;rt der Anrufer sicher schmalbandig. Bei &bdquo;HD&ldquo; kommt Breitband bis zum Anbieter an; ob der
      Anrufer selbst HD h&ouml;rt, h&auml;ngt von seinem Netz ab.</p>
<?php endif; ?>
   </div>
</div>
