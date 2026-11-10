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
   /* Wächter der Wartemusik und Anrufstatistik der letzten 24 Stunden */
   $watch = $streamUrl !== '' ? stream_watch_summary(stream_watch_status(), time()) : ['level' => 'none'];
   $stats = call_stats(24);
?>
  <!-- Wartemusik begin -->
  <div class="w3-panel w3-card-4 w3-blue">
  <h3 style="color: #ffffff">Wartemusik</h3>
<?php if ($watch['level'] === 'warn'): ?>
  <div class="w3-panel w3-pale-red w3-leftbar w3-border-red" style="border-radius:5px" id="stream-warnung">
    <p><b>Achtung:</b> <?= h($watch['text']) ?></p>
<?php if ($watch['detail'] !== ''): ?>
    <p class="hint"><?= h($watch['detail']) ?></p>
<?php endif; ?>
  </div>
<?php elseif ($watch['level'] !== 'none'): ?>
  <div class="w3-panel w3-light-gray" style="border-radius:5px" id="stream-zustand">
    <p><?= h($watch['text']) ?></p>
<?php if ($watch['detail'] !== ''): ?>
    <p class="hint"><?= h($watch['detail']) ?></p>
<?php endif; ?>
  </div>
<?php else: ?>
  <div class="w3-panel w3-light-gray" style="border-radius:5px"><p>Keine Stream-Adresse eingerichtet.</p></div>
<?php endif; ?>
<?php if ($stats !== null): ?>
  <div class="w3-panel w3-light-blue" style="border-radius:5px" id="anrufstatistik">
    <p>Anrufe in den letzten 24 Stunden: <?= $stats['calls'] ?><?php if ($stats['calls'] > 0):
         $avg = intdiv($stats['seconds'], $stats['calls']);
         $shortPct = (int)round(100 * $stats['short'] / $stats['calls']); ?>,
       durchschnittlich <?= h(sprintf('%d:%02d', intdiv($avg, 60), $avg % 60)) ?> min,
       unter <?= CALL_SHORT_SECONDS ?> s: <?= $shortPct ?> %<?php endif; ?></p>
<?php if ($stats['calls'] >= 5 && $stats['short'] * 2 >= $stats['calls']): ?>
    <p><b>Auff&auml;llig viele sehr kurze Anrufe.</b> Das kann bedeuten, dass Anrufer nichts h&ouml;ren und
       gleich wieder auflegen. Bitte selbst anrufen und pr&uuml;fen.</p>
<?php endif; ?>
    <p class="hint">Gez&auml;hlt werden angenommene Anrufe, ohne Rufnummern. Nach einem Update beginnt die Z&auml;hlung
       von vorn.</p>
  </div>
<?php endif; ?>
  </div>
  <!-- Wartemusik end -->

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
<?php if ($streamUrl !== ''): $info = stream_info($streamUrl); ?>
  <p class="hint" style="color:#fff">Qualit&auml;t des Streams: <?= h(stream_info_text($info)) ?></p>
<?php if (stream_info_low($info)): ?>
  <div class="w3-panel w3-light-gray" style="border-radius:5px">
<?php include __DIR__ . '/stream_quality_hint.php'; ?>
  </div>
<?php endif; ?>
<?php endif; ?>
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
