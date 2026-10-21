<?php
   require(__DIR__ . '/../lib/checklogin.php');
   require_once __DIR__ . '/../lib/update.php';

   if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      require_post_csrf();
      /* Fehler zeigt die Seite selbst an, daher nur Erfolg als Hinweis. */
      $s = update_status(true);
      if ($s['error'] === null && $s['latest'] !== null) {
         flash('ok', $s['update'] ? 'Eine neue Version ist verfügbar.' : 'Telerix ist auf dem neuesten Stand.');
      }
      redirect('update.php');
   }

   $s = update_status();
   include(__DIR__ . '/../lib/layout/navbar.php');
?>
<div class="w3-container w3-padding-32 page-bg">
  <div class="w3-content" style="max-width:980px">

 <div class="w3-panel w3-card-4 w3-blue">
  <h3 style="color: #ffffff">Updates</h3>
  <div class="w3-panel w3-light-gray" style="border-radius:5px">
   <p>Installierte Version: <b><?= h(TELERIX_VERSION) ?></b></p>
<?php if ($s['repo'] === ''): ?>
   <p>Die Update-Pr&uuml;fung ist nicht eingerichtet. Dazu in <i>docker-compose.yml</i> die Variable
      <b>TELERIX_UPDATE_REPO</b> auf das GitHub-Repository setzen (z. B. <i>meinname/Telerix</i>)
      und den Container neu starten.</p>
<?php else: ?>
   <p>Repository: <a href="https://github.com/<?= h($s['repo']) ?>/releases" target="_blank" rel="noopener noreferrer"><?= h($s['repo']) ?></a></p>
<?php if ($s['latest'] !== null): ?>
   <p>Neueste Version: <b><?= h($s['latest']['version']) ?></b>
      <?php if ($s['latest']['published'] !== ''): ?>(ver&ouml;ffentlicht <?= h(substr($s['latest']['published'], 0, 10)) ?>)<?php endif; ?></p>
<?php endif; ?>
<?php if ($s['error'] !== null): ?>
   <p class="flash flash-error"><?= h($s['error']) ?></p>
<?php endif; ?>
   <p class="hint">Zuletzt gepr&uuml;ft: <?= $s['checked'] ? h(date('d.m.Y H:i', $s['checked'])) . ' UTC' : 'noch nie' ?></p>
   <form action="update.php" method="POST">
     <?= csrf_field() ?>
     <button type="submit" class="w3-button w3-dark-gray">Jetzt pr&uuml;fen</button>
   </form>
<?php endif; ?>
   <br />
  </div>
 </div>

<?php if ($s['update']): ?>
 <div class="w3-panel w3-card-4 w3-blue">
  <h3 style="color: #ffffff">Neue Version <?= h($s['latest']['version']) ?><?= $s['latest']['name'] !== '' ? ' &ndash; ' . h($s['latest']['name']) : '' ?></h3>
  <div class="w3-panel w3-light-gray" style="border-radius:5px">
<?php if ($s['latest']['notes'] !== ''): ?>
   <h5>&Auml;nderungen</h5>
   <pre style="white-space: pre-wrap"><?= h($s['latest']['notes']) ?></pre>
<?php endif; ?>
<?php if ($s['latest']['url'] !== ''): ?>
   <p><a href="<?= h($s['latest']['url']) ?>" target="_blank" rel="noopener noreferrer">Release auf GitHub ansehen</a></p>
<?php endif; ?>
   <h5>So wird aktualisiert</h5>
   <p>Das Webinterface aktualisiert sich aus Sicherheitsgr&uuml;nden nicht selbst. Auf dem Rechner, auf dem
      Telerix l&auml;uft, im Telerix-Ordner ausf&uuml;hren (unter Windows in Git Bash):</p>
   <pre>./update.sh</pre>
   <p class="hint">Das Skript legt vorher eine Sicherung des Datenvolumes an, holt die neue Version,
      baut das Image neu und startet den Container. Laufende Anrufe werden dabei getrennt.</p>
  </div>
 </div>
<?php endif; ?>

  </div>
</div>

<?php
   include(__DIR__ . '/../lib/layout/footer.php');
?>
