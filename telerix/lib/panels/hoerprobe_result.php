<?php
   /* Ergebnis der letzten Hörprobe. Wird auch von hoerprobe.php nach einer
      Aufnahme im Hintergrund zurückgegeben und ersetzt dann diesen Block. */
   require_once __DIR__ . '/../hoerprobe.php';
   $probe = hoerprobe_info();
   /* Pegel für die Anzeige: "-23,4 dBFS" */
   $dbfs = fn(float $v): string => ($v <= -120 ? '-&infin;' : h(str_replace('.', ',', sprintf('%.1f', $v)))) . ' dBFS';
?>
<div id="hoerprobe-ergebnis">
<?php if ($probe !== null): $pv = $probe['values']; $lv = $probe['levels']; ?>
  <h5 style="color:#333">H&ouml;rprobe vom <?= h(date('d.m.Y H:i', $probe['created'])) ?> UTC
<?php if (!$pv['audio_processing']): ?>
     (Tonaufbereitung aus)</h5>
<?php else: ?>
     (Lautst&auml;rke <?= h(gain_text((int)$pv['audio_gain_db'])) ?>,
     <?= h(AUDIO_PROFILES[$pv['audio_profile']] ?? '?') ?>,
     Hochpass <?= (int)$pv['audio_highpass_hz'] === 0 ? 'aus' : (int)$pv['audio_highpass_hz'] . ' Hz' ?>,
     Pr&auml;senz <?= h(presence_text((int)$pv['audio_presence_db'])) ?><?php if ((int)$pv['audio_input_db'] !== 0): ?>,
     Eingangspegel <?= h(input_text((int)$pv['audio_input_db'])) ?><?php endif; ?>)</h5>
<?php endif; ?>
  <p style="color:#333">Wie im Telefonnetz (Festnetz, Handy):<br />
     <audio controls preload="none" src="hoerprobe.php?f=telefon&amp;t=<?= (int)$probe['created'] ?>"></audio></p>
  <p style="color:#333">Wie bei HD-Telefonie (G.722):<br />
     <audio controls preload="none" src="hoerprobe.php?f=hd&amp;t=<?= (int)$probe['created'] ?>"></audio></p>
<?php if ($lv !== null): ?>
  <table class="pegel">
    <tr><th>Gemessen &uuml;ber 20 s</th><th>RMS</th><th>Spitze</th></tr>
    <tr><td>Stream, unbearbeitet</td><td class="zahl"><?= $dbfs((float)$lv['stream']['rms']) ?></td>
        <td class="zahl"><?= $dbfs((float)$lv['stream']['peak']) ?></td></tr>
    <tr><td>Aufbereitet (wie zum Anrufer)</td><td class="zahl"><?= $dbfs((float)$lv['telefon']['rms']) ?></td>
        <td class="zahl"><?= $dbfs((float)$lv['telefon']['peak']) ?></td></tr>
  </table>
  <p class="hint">Sprache ist gut, wenn sie etwa zwischen -26 und -20 dBFS RMS liegt; bei Musik schwankt der Wert
     st&auml;rker. Spitzen sollten unter etwa -3 dBFS bleiben. Lauter ist am Telefon nicht besser: G.711 verzerrt
     bei &Uuml;bersteuerung, und viele Telefone regeln selbst herunter.</p>
<?php if ($lv['stream']['clipped'] && (int)$lv['input_db'] === 0): ?>
  <p class="hint"><b>Der Stream erreicht 0 dBFS.</b> Er ist wahrscheinlich zu laut ausgesteuert und wird beim
     Dekodieren abgeschnitten. Den Eingangspegel um 3 dB absenken und die H&ouml;rprobe wiederholen; liegt die
     Spitze dann unter 0 dBFS, reicht das. Besser ist, die Aussteuerung beim Stream selbst zu verringern.</p>
<?php elseif ($lv['stream']['clipped']): ?>
  <p class="hint"><b>Der Stream wird trotz Eingangspegel noch abgeschnitten.</b> Den Eingangspegel weiter absenken.</p>
<?php elseif ((int)$lv['input_db'] !== 0 && $lv['stream']['peak'] > 0): ?>
  <p class="hint">Der Stream geht &uuml;ber 0 dBFS hinaus; der eingestellte Eingangspegel verhindert, dass dabei
     etwas abgeschnitten wird.</p>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>
</div>
