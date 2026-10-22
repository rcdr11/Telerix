<?php
   require_once __DIR__ . '/../hoerprobe.php';
   $cur = settings_effective();
   $gainLocked = setting_locked('audio_gain_db');
   $bufLocked = setting_locked('audio_buffer_s');
   $probe = hoerprobe_info();
   $highpassText = fn(int $hz): string => $hz === 0 ? 'aus' : $hz . ' Hz';
?>
<div class="container">
  <form action="set_audio.php" method="POST">
  <?= csrf_field() ?>
  <div class="row">
    <div class="col-25">
      <label for="audio_gain_db">Lautst&auml;rke</label>
    </div>
    <div class="col-75">
      <select id="audio_gain_db" name="audio_gain_db"<?= $gainLocked ? ' disabled' : '' ?>>
<?php for ($i = AUDIO_GAIN_MIN; $i <= AUDIO_GAIN_MAX; $i++): ?>
        <option value="<?= $i ?>"<?= $i === $cur['audio_gain_db'] ? ' selected' : '' ?>>+<?= $i ?> dB<?= $i === SETTINGS['audio_gain_db']['default'] ? ' (Standard, Sprache etwa -20 dBFS)' : '' ?></option>
<?php endfor; ?>
      </select>
<?php if ($gainLocked): ?>
      <p class="hint"><?= h(setting_locked_text('audio_gain_db')) ?></p>
<?php endif; ?>
    </div>
  </div>
  <div class="row">
    <div class="col-25">
      <label for="audio_profile">Klangprofil</label>
    </div>
    <div class="col-75">
      <select id="audio_profile" name="audio_profile">
<?php foreach (AUDIO_PROFILES as $key => $label): ?>
        <option value="<?= h($key) ?>"<?= $key === $cur['audio_profile'] ? ' selected' : '' ?>><?= h($label) ?><?= $key === SETTINGS['audio_profile']['default'] ? ' (Standard)' : '' ?></option>
<?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="row">
    <div class="col-25">
      <label for="audio_highpass_hz">Hochpass</label>
    </div>
    <div class="col-75">
      <select id="audio_highpass_hz" name="audio_highpass_hz">
<?php foreach (AUDIO_HIGHPASS_CHOICES as $hz): ?>
        <option value="<?= $hz ?>"<?= $hz === $cur['audio_highpass_hz'] ? ' selected' : '' ?>><?= $hz === 0 ? 'aus' : 'unter ' . $hz . ' Hz abschneiden' ?><?= $hz === SETTINGS['audio_highpass_hz']['default'] ? ' (Standard)' : '' ?></option>
<?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="row">
    <div class="col-25">
      <label for="audio_buffer_s">Vorlauf</label>
    </div>
    <div class="col-75">
      <select id="audio_buffer_s" name="audio_buffer_s"<?= $bufLocked ? ' disabled' : '' ?>>
<?php for ($i = AUDIO_BUFFER_MIN; $i <= AUDIO_BUFFER_MAX; $i++): ?>
        <option value="<?= $i ?>"<?= $i === $cur['audio_buffer_s'] ? ' selected' : '' ?>><?= $i ?> s<?= $i === SETTINGS['audio_buffer_s']['default'] ? ' (Standard)' : '' ?></option>
<?php endfor; ?>
      </select>
<?php if ($bufLocked): ?>
      <p class="hint"><?= h(setting_locked_text('audio_buffer_s')) ?></p>
<?php endif; ?>
    </div>
  </div>
  <ul class="hint">
    <li><b>Lautst&auml;rke:</b> Anhebung des Streams am Telefon, jedes dB mehr ist etwa 1 dB lauter. Ein Begrenzer
       verhindert &Uuml;bersteuern.</li>
    <li><b>Klangprofil:</b> &bdquo;Sprache&ldquo; gleicht stark aus, Predigt und Lesung kommen gleichm&auml;&szlig;ig
       laut an. &bdquo;Sprache und Musik&ldquo; gleicht sanfter aus, leise und laute Stellen von Gesang und Orgel
       bleiben unterscheidbar. &bdquo;Ohne Leveler&ldquo; l&auml;sst die Dynamik des Streams unver&auml;ndert.</li>
    <li><b>Hochpass:</b> entfernt Brummen, Rumpeln und Trittschall unterhalb der Frequenz. Im Telefonnetz kommt
       unter etwa 300 Hz ohnehin nichts an, HD-Telefonie reicht bis etwa 50 Hz. Niedriger klingt w&auml;rmer,
       h&ouml;her r&auml;umt mehr auf.</li>
    <li><b>Vorlauf:</b> Puffer gegen Stockungen des Streams, um diese Zeit kommt der Ton verz&ouml;gert an.</li>
  </ul>
  <p class="hint">Gespeicherte &Auml;nderungen gelten nach wenigen Sekunden, auch f&uuml;r laufende Anrufe (dabei
     kurz kein Ton). Die H&ouml;rprobe nimmt 20 Sekunden des Streams mit den gew&auml;hlten Werten auf, ohne sie zu
     speichern, und dauert etwa so lange.</p>
  <div class="row">
    <input type="submit" value="Speichern und Anwenden">
    <button type="submit" formaction="hoerprobe.php" class="w3-button w3-dark-gray w3-round" data-busy="Hörprobe wird aufgenommen …">H&ouml;rprobe mit diesen Werten</button>
  </div>
  </form>
<?php if ($probe !== null): $pv = $probe['values']; ?>
  <h5 style="color:#333">H&ouml;rprobe vom <?= h(date('d.m.Y H:i', $probe['created'])) ?> UTC
     (+<?= (int)$pv['audio_gain_db'] ?> dB, <?= h(AUDIO_PROFILES[$pv['audio_profile']] ?? '?') ?>,
     Hochpass <?= h($highpassText((int)$pv['audio_highpass_hz'])) ?>)</h5>
  <p style="color:#333">Wie im Telefonnetz (Festnetz, Handy):<br />
     <audio controls preload="none" src="hoerprobe.php?f=telefon&amp;t=<?= (int)$probe['created'] ?>"></audio></p>
  <p style="color:#333">Wie bei HD-Telefonie (G.722):<br />
     <audio controls preload="none" src="hoerprobe.php?f=hd&amp;t=<?= (int)$probe['created'] ?>"></audio></p>
<?php endif; ?>
</div>
