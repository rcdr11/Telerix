<?php
   require_once __DIR__ . '/../settings.php';
   $streamUrl = stream_load();
   $ice = settings_effective();
   $iceStatus = $streamUrl !== '' || $ice['icecast_status_url'] !== ''
      ? icecast_status($streamUrl, $ice['icecast_status_url']) : null;
   $info = $streamUrl !== '' ? stream_info($streamUrl) : null;
?>
<div class="container">
  <form action="set_stream.php" method="POST">
  <?= csrf_field() ?>
  <div class="row">
    <div class="col-25">
      <label for="streamadress">Stream-URL</label>
    </div>
    <div class="col-75">
    <input type="text" id="streamadress" name="inetstream" placeholder="z.B. http://www.beispiel.de:8000/stream" value="<?= h($streamUrl) ?>">
    </div>
  </div>
 <br />
<?php if ($info !== null): ?>
  <p class="hint">Qualit&auml;t des Streams: <b><?= h(stream_info_text($info)) ?></b></p>
<?php include __DIR__ . '/stream_quality_hint.php'; ?>
<?php endif; ?>
  <div class="row">
    <input type="submit" value="Speichern und Anwenden">
  </div>
  </form>

  <h5 style="color:#333">Icecast-Status auf der Startseite</h5>
  <form action="set_icecast.php" method="POST">
  <?= csrf_field() ?>
  <div class="row">
    <div class="col-25">
      <label for="icecast_status_url">Statusadresse</label>
    </div>
    <div class="col-75">
    <input type="text" id="icecast_status_url" name="icecast_status_url" placeholder="leer = automatisch aus der Stream-URL" autocomplete="off" value="<?= h($ice['icecast_status_url']) ?>">
    </div>
  </div>
  <div class="row">
    <div class="col-25">
      <label for="icecast_panel">Anzeigen</label>
    </div>
    <div class="col-75">
      <label><input type="checkbox" id="icecast_panel" name="icecast_panel" value="1"<?= $ice['icecast_panel'] ? ' checked' : '' ?>>
         Zuh&ouml;rer laut Icecast auf der Startseite anzeigen</label>
    </div>
  </div>
  <p class="hint">Die Startseite zeigt, wie viele Zuh&ouml;rer den Stream gerade im Internet h&ouml;ren. Ohne eigene
     Statusadresse sucht Telerix die Icecast-Statusseite (status-json.xsl) neben der Stream-URL und in der Wurzel des
     Servers. Liegt sie woanders, hier eintragen, z. B. <i>http://radio.example.org:8000</i>; status-json.xsl wird
     erg&auml;nzt. Bei AzuraCast findet Telerix sie automatisch unter <i>https://server/listen/&lt;station&gt;/</i>.</p>
<?php if ($iceStatus !== null): ?>
  <p class="hint">Ergebnis: <?= $iceStatus['error'] === null
     ? 'gefunden unter <i>' . h($iceStatus['url']) . '</i>'
     : h($iceStatus['error']) . ' Abgefragt: <i>' . h($iceStatus['url']) . '</i>' ?></p>
<?php endif; ?>
  <div class="row">
    <input type="submit" value="Speichern">
  </div>
  </form>
</div>
