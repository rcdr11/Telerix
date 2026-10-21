<?php
   require_once __DIR__ . '/../stream.php';
?>
<div class="container">
  <form action="set_stream.php" method="POST">
  <?= csrf_field() ?>
  <div class="row">
    <div class="col-25">
      <label for="streamadress">Stream-URL</label>
    </div>
    <div class="col-75">
    <input type="text" id="streamadress" name="inetstream" placeholder="z.B. http://www.beispiel.de:8000/stream" value="<?= h(stream_load()) ?>">
    </div>
  </div>
 <br />
  <div class="row">
    <input type="submit" value="Speichern und Anwenden">
  </div>
  </form>
</div>
