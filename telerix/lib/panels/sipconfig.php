<?php
   require_once __DIR__ . '/../sip.php';
   $sip = sip_load();
?>
<div class="container">
  <form action="set_sipconfig.php" method="POST">
  <?= csrf_field() ?>
  <div class="row">
    <div class="col-25">
      <label for="sip_id">SIP-ID</label>
    </div>
    <div class="col-75">
    <input type="text" id="sip_id" name="sip_id" placeholder="Deine SIP-ID vom Anbieter" autocomplete="off" value="<?= h($sip['user']) ?>">
    </div>
  </div>
  <div class="row">
    <div class="col-25">
      <label for="sip_pw">Passwort</label>
    </div>
    <div class="col-75">
      <input type="password" id="sip_pw" name="sip_pw" autocomplete="new-password"
             placeholder="<?= $sip['password'] !== '' ? 'Gespeichert – leer lassen, um es beizubehalten' : 'Das Passwort zu deiner SIP-ID' ?>">
    </div>
  </div>
  <div class="row">
   <div class="col-25">
      <label for="sip_reg">Registrar</label>
    </div>
    <div class="col-75">
      <input type="text" id="sip_reg" name="sip_reg" placeholder="z.B. sipgate.de" autocomplete="off" value="<?= h($sip['registrar']) ?>">
    </div>
  </div>
  <p class="hint">Alle drei Felder leeren, um die Registrierung abzuschalten. Eingehende Anrufe werden auf Extension 200 geleitet.</p>
  <div class="row">
    <input type="submit" value="Speichern und Anwenden">
  </div>
  </form>
</div>
