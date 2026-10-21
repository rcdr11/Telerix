<div class="container">
  <form action="set_sipconfig.php" method="POST">
  <?= csrf_field() ?>
  <div class="row">
    <div class="col-25">
      <label for="fname" style="color: black">SIP-ID</label>
    </div>
    <div class="col-75">
    <input type="text" id="fname" name="sip_id" placeholder="Deine SIP-ID vom Anbieter" value="<?php system("../lib/sh/get_sip_id.sh"); ?>">
    </div>
  </div>
  <div class="row">
    <div class="col-25">
      <label for="lname" style="color: black">Passwort</label>
    </div>
    <div class="col-75">
      <input type="password" id="lname" name="sip_pw" placeholder="Das Passwort zu deiner SIP-ID" value="<?php system("../lib/sh/get_sip_pw.sh"); ?>">
    </div>
  </div>
  <div class="row">
   <div class="col-25">
      <label for="lname" style="color: black">Registrar</label>
    </div>
    <div class="col-75">
      <input type="text" id="registrar" name="sip_reg" placeholder="z.B. sipgate.de" value="<?php system("../lib/sh/get_sip_reg.sh"); ?>">
    </div>
  </div>
  <br />
  <div class="row">
    <input type="submit" value="Speichern und Anwenden">
  </div>
  </form>
</div>
