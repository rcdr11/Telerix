<?php
   /* Werte, die bewusst nur über .env bzw. Docker eingestellt werden. */
   $env = fn(string $name): string => trim((string)getenv($name));
   $proxies = $env('TELERIX_TRUSTED_PROXIES');
   $secure = $env('TELERIX_COOKIE_SECURE') === '1';
   $repo = $env('TELERIX_UPDATE_REPO');
?>
<div class="w3-panel w3-light-gray" style="border-radius:5px; color:#333">
  <ul class="w3-ul">
    <li><b>Reverse-Proxy</b> (TELERIX_TRUSTED_PROXIES): <?= $proxies === '' ? 'nicht gesetzt' : h($proxies) ?><br />
       <span class="hint">Wird beim Start in die nginx-Konfiguration &uuml;bernommen. Ein falscher Wert w&uuml;rde die
       Login-Sperre aushebeln.</span></li>
    <li><b>Cookie nur &uuml;ber HTTPS</b> (TELERIX_COOKIE_SECURE): <?= $secure ? 'an' : 'aus' ?><br />
       <span class="hint">Eingeschaltet ohne HTTPS w&auml;re keine Anmeldung mehr m&ouml;glich.</span></li>
    <li><b>Update-Pr&uuml;fung</b> (TELERIX_UPDATE_REPO): <?= $repo === '' ? 'nicht eingerichtet' : h($repo) ?><br />
       <span class="hint">Setzt install.sh automatisch.</span></li>
    <li><b>Ports</b> f&uuml;r SIP/RTP und weitere Dienste: in docker-compose.override.yml</li>
    <li><b>Passwort vergessen:</b> &uuml;ber secrets/admin_password.txt, siehe docs/konfiguration.md</li>
  </ul>
  <p class="hint">&Auml;nderungen in .env &uuml;bernimmt im Telerix-Ordner der Befehl <i>docker compose up -d</i>.</p>
</div>
