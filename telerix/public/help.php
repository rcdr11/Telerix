<?php
   require(__DIR__ . '/../lib/checklogin.php');
   include(__DIR__ . '/../lib/layout/navbar.php');
?>

<div class="w3-container w3-padding-32" class="page-bg">
  <div class="w3-content w3-white" style="max-width:980px">

 <!-- Optionen Panel Begin-->
 <div class="w3-panel w3-card-4 w3-blue">
  <h3 style="color: #ffffff">Hilfe</h3>



<!-- Frage 01 -->
<button type="button" data-toggle="Frage1" class="w3-button w3-block w3-left-align w3-light-blue">
<h6>Wie kann ich zur Weiterentwicklung von TELERIX beitragen?</h6></button>

<div id="Frage1" class="w3-hide w3-container w3-light-blue">
  <p>Wenn du C, PHP oder Bashskripte programmieren kannst, oder dich mit Asterisk, HTML, CSS oder Regex auskennst,
     kannst du dich gern im &ouml;ffentlichen Repository an der Entwicklung beteiligen. <br />Die Repositories f&uuml;r 
     Telerix findest du unter folgendem GitHub-Account: <a href="https://github.com/beni1993" target="_blank" rel="noopener noreferrer">github.com/beni1993</a> </p>
  <p>Wenn du mich kennst, kannst du gerne auch Verbesserungsvorschl&auml;ge und Kritik weitergeben.</p>
  <p><b>Hinweis:</b> Diese Installation ist eine ver&auml;nderte Version von Telerix (siehe Info).
     Fehler, die nur in dieser Version auftreten, bitte nicht im Original-Repository melden.</p>
</div>
<!-- End -->
<br />

<!-- Frage 02 -->
<button type="button" data-toggle="Track2" class="w3-button w3-block w3-left-align w3-light-blue">
<h6>Auf welchen Plattformen l&auml;uft TELERIX?</h6></button>

<div id="Track2" class="w3-hide w3-container w3-light-blue">
  <p>Diese ver&auml;nderte Version l&auml;uft als Docker-Container (Alpine Linux 3.24, Asterisk 22,
     nginx, PHP 8.4) &ndash; z. B. mit Docker Desktop unter Windows oder macOS oder mit Docker unter Linux.</p>
  <p>Das urspr&uuml;ngliche Telerix 0.02 war f&uuml;r Ubuntu 18.04 geschrieben.</p>
</div>
<!-- End -->
<br />

<!-- Frage 03 -->
<button type="button" data-toggle="Track3" class="w3-button w3-block w3-left-align w3-light-blue">
<h6>Wie kann ich TELERIX installieren?</h6></button>

<div id="Track3" class="w3-hide w3-container w3-light-blue">
  <p>Voraussetzungen sind Docker und Git. Dann im Terminal (unter Windows in Git Bash):<br />
  <i><b>git clone https://github.com/rcdr11/Telerix.git</b></i><br />
  <i><b>cd Telerix</b></i><br />
  <i><b>./install.sh</b></i></p>
  <p>Das Skript zeigt am Ende Adresse, Benutzer und Passwort an.
     Weitere Hinweise stehen in der README.</p>
</div>
<!-- End -->
<br />

<!-- Frage 04 -->
<button type="button" data-toggle="Frage4" class="w3-button w3-block w3-left-align w3-light-blue">
<h6>Warum wird meine Stream-Adresse oder SIP-Eingabe abgelehnt?</h6></button>

<div id="Frage4" class="w3-hide w3-container w3-light-blue">
  <p>Eingaben werden gepr&uuml;ft und bei unerlaubten Zeichen mit einer Meldung abgelehnt
     (fr&uuml;her wurden Sonderzeichen still entfernt).</p>
  <p>Stream-Adresse: nur http:// oder https://, ohne Leerzeichen und Anf&uuml;hrungszeichen.<br />
     SIP-ID: A-Z, a-z, 0-9 sowie . _ + -<br />
     Passwort: druckbare Zeichen ohne Leerzeichen und Backslash<br />
     Registrar: Hostname oder IPv4-Adresse, optional mit :Port</p>
</div>
<!-- End -->

<br /> <!-- Unten Platz schaffen -->

</div>

 </div>
  <!-- Optionen Panel End-->
 </div> 
  </div>
</div>

<?php
   include(__DIR__ . '/../lib/layout/footer.php');
?>
