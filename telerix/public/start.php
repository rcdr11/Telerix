<?php
   require(__DIR__ . '/../lib/checklogin.php');
   include(__DIR__ . '/../lib/layout/navbar.php');
?>
<div class="w3-container w3-padding-32" style="background-color: #d4e1ff">
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
  <br />
  <br/>
 </div>
  <!-- Optionen Panel End-->

  <!-- Streamübersichtkasten begin -->
  <div class="w3-panel w3-card-4 w3-blue">
  <h3 style="color: #ffffff">Internetradiostreams</h3>
  <!-- Streamliste begin -->
  <ul class="w3-ul w3-center">
  <?php
    $out=system("../lib/sh/show_internetstreams.sh");
  ?>
  </ul>
  <br />
  <!-- Streamliste end -->
  </div>
  <!-- Streamübersichtkasten end -->
 
 <div class="w3-panel w3-card-4 w3-blue">
  <h3 style="color: #ffffff">Aktuelle Zuh&oumlrer</h3>
 
  <!-- Zuhoererliste begin -->
  <div class="w3-panel w3-light-gray" >
  <ul class="w3-ul w3-center">
  <?php
     $out=system("../lib/sh/show_caller_numbers.sh");
  ?>
   </ul>
   </div>  
   <!-- Zuhoererliste end -->

   <div class="w3-panel w3-light-blue" style="border-radius: 5px">
   <p>Zuh&oumlrer: <?php system("../lib/sh/show_how_much_callers.sh"); ?></p>
   </div>  
</div> 

</div> 

  </div>
</div> 
<?php
   include(__DIR__ . '/../lib/layout/footer.php');
?>
