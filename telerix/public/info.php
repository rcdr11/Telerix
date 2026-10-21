<?php
   require(__DIR__ . '/../lib/checklogin.php');
   require_once __DIR__ . '/../lib/asterisk.php';
   $os = @parse_ini_file('/etc/os-release');
   include(__DIR__ . '/../lib/layout/navbar.php');
?>
<div class="w3-container w3-padding-32" class="page-bg">
  <div class="w3-content w3-white" style="max-width:980px">

 <!-- Optionen Panel Begin-->
 <div class="w3-panel w3-card-4 w3-blue">
  <h3 style="color: #ffffff">Informationen</h3>

  <div class="w3-panel w3-light-gray" style="border-radius:5px" >
  <ul class="w3-ul">
  <li>Telerix-Version: <?= h(TELERIX_VERSION) ?> &ndash; <b>ver&auml;nderte Version</b> auf Basis von Telerix 0.02</li>
  <li>Asterisk-Version: <?= h(asterisk_version()) ?></li>
  <li>Original: 15. April 2020, Benjamin Hedert (github.com/beni1993/Telerix)</li>
  <li>Lizenz: zlib</li>
  <li>Betriebssystem: <?= h($os['PRETTY_NAME'] ?? 'unbekannt') ?></li>
  <li>Dankesch&oumln f&uumlr die Unterst&uumltzung an Edgar W, Martin G, Eduard D, Alex J, Daniel L und Viktor B</li>
  </ul>
  </div>  

  <div class="w3-panel w3-pale-yellow" style="border-radius:5px; color:#333">
  <p><b>Ver&auml;nderte Version (Hinweis gem&auml;&szlig; zlib-Lizenz, Punkt 2):</b>
  Dies ist nicht die Originalsoftware. Gegen&uuml;ber Telerix 0.02 wurden u. a. die
  Rechteausweitung per sudo entfernt, Login, Uploads und Shell-Aufrufe abgesichert,
  Asterisk von chan_sip auf PJSIP umgestellt und ein Docker-Betrieb erg&auml;nzt.
  Einzelheiten stehen in der README. Fehler dieser Version bitte nicht dem
  urspr&uuml;nglichen Autor melden.</p>
  </div>

Urspr&uuml;nglicher Lizenzhinweis (unver&auml;ndert):<br />
Copyright (c) 2020 Benjamin Hedert
<br/>
This software is provided 'as-is', without any express or implied warranty. In no event will the authors be held liable for any damages arising from the use of this software. <br />
Permission is granted to anyone to use this software for any purpose, including commercial applications, and to alter it and redistribute it freely, subject to the following restrictions:<br />
1) The origin of this software must not be misrepresented; you must not claim that you wrote the original software. If you use this software in a product, an acknowledgment in the product documentation would be appreciated but is not required.<br />
2) Altered source versions must be plainly marked as such, and must not be misrepresented as being the original software.<br />
3) This notice may not be removed or altered from any source distribution.<br />

  <div class="w3-panel w3-light-blue" style="border-radius:5px" >
  <ul class="w3-ul">
  <li>Hinweis! Das Webinterface ist f&uuml;r den Betrieb im lokalen Netz bzw. nur auf 127.0.0.1 gedacht. F&uuml;r den Zugriff aus dem Internet geh&ouml;rt ein HTTPS-Proxy davor.</li>
  </ul>
  </div>


 </div>
  <!-- Optionen Panel End-->
 </div> 

  </div>
</div> 

<?php
   include(__DIR__ . '/../lib/layout/footer.php');
?>
