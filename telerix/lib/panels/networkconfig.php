<?php
   require_once __DIR__ . '/../settings.php';
   $addr = settings_effective()['external_address'];
   $locked = setting_locked('external_address');
?>
<div class="container">
  <form action="set_external.php" method="POST">
  <?= csrf_field() ?>
  <div class="row">
    <div class="col-25">
      <label for="external_address">Adresse</label>
    </div>
    <div class="col-75">
    <input type="text" id="external_address" name="external_address" placeholder="leer = keine (z. B. 203.0.113.10 oder sip.example.org)" autocomplete="off" value="<?= h($addr) ?>"<?= $locked ? ' disabled' : '' ?>>
<?php if ($locked): ?>
    <p class="hint"><?= h(setting_locked_text('external_address')) ?></p>
<?php endif; ?>
    </div>
  </div>
  <p class="hint">Nur n&ouml;tig, wenn Telerix auf einem Server hinter NAT l&auml;uft und beim Anrufer kein Ton ankommt:
     &ouml;ffentliche IPv4-Adresse oder Hostname des Servers. Zus&auml;tzlich m&uuml;ssen die SIP- und RTP-Ports
     freigegeben sein (docker-compose.override.yml, siehe docs/konfiguration.md). Die Adresse gilt erst nach einem
     Neustart von Asterisk.</p>
  <div class="row">
    <input type="submit" value="Speichern"<?= $locked ? ' disabled' : '' ?>>
  </div>
  </form>
  <form action="asterisk_restart.php" method="POST" data-confirm="Asterisk neu starten? Alle bestehenden Verbindungen werden damit unterbrochen.">
    <?= csrf_field() ?>
    <button type="submit" class="w3-button w3-dark-gray w3-round">Asterisk neu starten</button>
  </form>
</div>
