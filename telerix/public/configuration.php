<?php
   require(__DIR__ . '/../lib/checklogin.php');
   include(__DIR__ . '/../lib/layout/navbar.php');
?>

<div class="w3-container w3-padding-32" class="page-bg">

  <div class="w3-content" style="max-width:980px"><!-- Wrapper start -->

 <!-- Internetstream Konfiguration Panel Begin-->
 <div class="w3-panel w3-card-4 w3-blue" id="stream">
  <h3 style="color: #ffffff">Internetstream:</h3>

  <?php
  include(__DIR__ . '/../lib/panels/streamconfig.php');
  ?>

  <br />
  <br/>
 </div>
  <!-- Internetstream Konfiguration Panel End-->

 <!-- SIP - Konfiguration Begin-->
 <div class="w3-panel w3-card-4 w3-blue">
  <h3 style="color: #ffffff">SIP-Konfiguration:</h3>
  <?php
  include(__DIR__ . '/../lib/panels/sipconfig.php');
  ?>

  <br />
  <br/>
 </div>
  <!-- SIP - Konfiguration End-->

 <div class="w3-panel w3-card-4 w3-blue" id="ton">
  <h3 style="color: #ffffff">Ton am Telefon:</h3>
  <?php
  include(__DIR__ . '/../lib/panels/audioconfig.php');
  ?>
  <br />
 </div>

 <div class="w3-panel w3-card-4 w3-blue">
  <h3 style="color: #ffffff">&Ouml;ffentliche Adresse (Server hinter NAT):</h3>
  <?php
  include(__DIR__ . '/../lib/panels/networkconfig.php');
  ?>
  <br />
 </div>

 <div class="w3-panel w3-card-4 w3-blue">
  <h3 style="color: #ffffff">Testtelefon:</h3>
  <?php
  include(__DIR__ . '/../lib/panels/testphoneconfig.php');
  ?>
  <br />
 </div>

  <!-- Zugangsdaten - Konfiguration Begin-->
  <div class="w3-panel w3-card-4 w3-blue">
  <h3 style="color: #ffffff">Zugangsdaten &auml;ndern:</h3>
  <?php
  include(__DIR__ . '/../lib/panels/changepass.php');
  ?>

  <br />
  <br/>
  </div>
  <!-- Zugangsdaten - Konfiguration End-->

 <div class="w3-panel w3-card-4 w3-blue">
  <h3 style="color: #ffffff">Nur &uuml;ber .env einstellbar:</h3>
  <?php
  include(__DIR__ . '/../lib/panels/envinfo.php');
  ?>
 </div>


 </div> 

  </div>
</div>

<?php
   include(__DIR__ . '/../lib/layout/footer.php');
?>
