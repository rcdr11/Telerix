<?php
   /* Ergebnis der letzten Hörprobe. Wird auch von hoerprobe.php nach einer
      Aufnahme im Hintergrund zurückgegeben und ersetzt dann diesen Block. */
   require_once __DIR__ . '/../hoerprobe.php';
   $probe = hoerprobe_info();
?>
<div id="hoerprobe-ergebnis">
<?php if ($probe !== null): $pv = $probe['values']; ?>
  <h5 style="color:#333">H&ouml;rprobe vom <?= h(date('d.m.Y H:i', $probe['created'])) ?> UTC
     (Lautst&auml;rke <?= h(gain_text((int)$pv['audio_gain_db'])) ?>,
     <?= h(AUDIO_PROFILES[$pv['audio_profile']] ?? '?') ?>,
     Hochpass <?= (int)$pv['audio_highpass_hz'] === 0 ? 'aus' : (int)$pv['audio_highpass_hz'] . ' Hz' ?>)</h5>
  <p style="color:#333">Wie im Telefonnetz (Festnetz, Handy):<br />
     <audio controls preload="none" src="hoerprobe.php?f=telefon&amp;t=<?= (int)$probe['created'] ?>"></audio></p>
  <p style="color:#333">Wie bei HD-Telefonie (G.722):<br />
     <audio controls preload="none" src="hoerprobe.php?f=hd&amp;t=<?= (int)$probe['created'] ?>"></audio></p>
<?php endif; ?>
</div>
