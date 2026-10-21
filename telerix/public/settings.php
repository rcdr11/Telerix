<?php
   require(__DIR__ . '/../lib/checklogin.php');
   include(__DIR__ . '/../lib/layout/navbar.php');
?>
<div class="w3-container w3-padding-32" style="background-color: #d4e1ff">
  <div class="w3-content" style="max-width:980px">

 <!-- Optionen Panel Begin-->
 <div class="w3-panel w3-card-4 w3-blue">
  <h3 style="color: #ffffff">Eingetragene Nummern</h3>
  <?php
     system("../lib/sh/showlist.sh");
  ?>
  <br />
  <br/>
 </div>
  <!-- Optionen Panel End-->
  <br />

  <form action="deletelist.php" method="POST" class="inline" data-confirm="Die aktuelle Liste unwiderruflich löschen?">
     <?= csrf_field() ?>
     <button type="submit" class="w3-button w3-dark-gray w3-round">Aktuelle Liste l&ouml;schen</button>
  </form>
  <a href="listupload.php" class="w3-button w3-dark-gray w3-round">Neue Liste hochladen</a>

 </div> 

  </div>
</div>

<?php
   include(__DIR__ . '/../lib/layout/footer.php');
?>
