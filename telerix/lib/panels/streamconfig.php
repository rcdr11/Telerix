<div class="container">
  <form action="set_stream.php" method="POST">
  <?= csrf_field() ?>
  <div class="row">
    <div class="col-25">
      <label for="fname" style="color: black">Stream-URL</label>
    </div>
    <div class="col-75">
    <input type="text" id="streamadress" name="inetstream" placeholder="z.B. www.beispiel.de:8000/stream" value="<?php system("../lib/sh/get_stream.sh"); ?>">
    </div>
  </div>
 <br />
  <div class="row">
    <input type="submit" value="Speichern und Anwenden">
  </div>
  </form>
</div>
