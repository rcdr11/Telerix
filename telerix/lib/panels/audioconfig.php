<?php
   require_once __DIR__ . '/../hoerprobe.php';
   $cur = settings_effective();
   $gainLocked = setting_locked('audio_gain_db');
   $bufLocked = setting_locked('audio_buffer_s');
   $std = fn(string $key, mixed $v): string => $v === SETTINGS[$key]['default'] ? ' (Standard)' : '';
?>
<div class="container">
  <form action="set_audio.php" method="POST">
  <?= csrf_field() ?>
  <div class="row">
    <div class="col-25">
      <label for="audio_processing">Tonaufbereitung</label>
    </div>
    <div class="col-75">
      <!-- Ohne Häkchen wird nur das versteckte Feld (0) gesendet -->
      <input type="hidden" name="audio_processing" value="0">
      <label><input type="checkbox" id="audio_processing" name="audio_processing" value="1"<?= $cur['audio_processing'] ? ' checked' : '' ?>>
         an (Standard)</label>
      <p class="hint">Ohne H&auml;kchen reicht Telerix den Stream ganz unbearbeitet weiter, nur in Mono und 16 kHz, wie
         es das Telefon braucht. Die Einstellungen darunter
         (au&szlig;er Vorlauf) bleiben gespeichert, gelten aber erst wieder mit H&auml;kchen.</p>
    </div>
  </div>
  <div class="row">
    <div class="col-25">
      <label for="audio_highpass_hz">Hochpass</label>
    </div>
    <div class="col-75">
      <select id="audio_highpass_hz" name="audio_highpass_hz">
<?php foreach (AUDIO_HIGHPASS_CHOICES as $hz): ?>
        <option value="<?= $hz ?>"<?= $hz === $cur['audio_highpass_hz'] ? ' selected' : '' ?>><?= $hz === 0 ? 'aus' : 'unter ' . $hz . ' Hz abschneiden' ?><?= $std('audio_highpass_hz', $hz) ?></option>
<?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="row">
    <div class="col-25">
      <label for="audio_presence_db">Sprachverst&auml;ndlichkeit (Pr&auml;senz)</label>
    </div>
    <div class="col-75">
      <select id="audio_presence_db" name="audio_presence_db">
<?php foreach (AUDIO_PRESENCE_CHOICES as $db): ?>
        <option value="<?= $db ?>"<?= $db === $cur['audio_presence_db'] ? ' selected' : '' ?>><?= $db === 0 ? 'aus' : '+' . $db . ' dB bei etwa 2,8 kHz' ?><?= $std('audio_presence_db', $db) ?></option>
<?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="row">
    <div class="col-25">
      <label for="audio_profile">Klangprofil</label>
    </div>
    <div class="col-75">
      <select id="audio_profile" name="audio_profile">
<?php foreach (AUDIO_PROFILES as $key => $label): ?>
        <option value="<?= h($key) ?>"<?= $key === $cur['audio_profile'] ? ' selected' : '' ?>><?= h($label) ?><?= $std('audio_profile', $key) ?></option>
<?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="row">
    <div class="col-25">
      <label for="audio_gain_db">Lautst&auml;rke</label>
    </div>
    <div class="col-75">
      <div class="slider-row">
        <input type="range" id="audio_gain_db" name="audio_gain_db" min="<?= AUDIO_GAIN_MIN ?>" max="<?= AUDIO_GAIN_MAX ?>" step="1"
               value="<?= (int)$cur['audio_gain_db'] ?>" list="audio_gain_marken" data-anzeige="audio_gain_wert"<?= $gainLocked ? ' disabled' : '' ?>>
        <output id="audio_gain_wert" for="audio_gain_db"><?= h(gain_text((int)$cur['audio_gain_db'])) ?></output>
      </div>
      <datalist id="audio_gain_marken">
<?php foreach ([-24, -12, 0, 12, 24] as $m): ?>
        <option value="<?= $m ?>"></option>
<?php endforeach; ?>
      </datalist>
      <p class="hint">Links leiser, rechts lauter, Mitte (0 dB) = unver&auml;ndert<?= $std('audio_gain_db', 0) ?>.</p>
<?php if ($gainLocked): ?>
      <p class="hint"><?= h(setting_locked_text('audio_gain_db')) ?></p>
<?php endif; ?>
    </div>
  </div>
  <div class="row">
    <div class="col-25">
      <label for="audio_buffer_s">Vorlauf</label>
    </div>
    <div class="col-75">
      <select id="audio_buffer_s" name="audio_buffer_s"<?= $bufLocked ? ' disabled' : '' ?>>
<?php for ($i = AUDIO_BUFFER_MIN; $i <= AUDIO_BUFFER_MAX; $i++): ?>
        <option value="<?= $i ?>"<?= $i === $cur['audio_buffer_s'] ? ' selected' : '' ?>><?= $i ?> s<?= $std('audio_buffer_s', $i) ?></option>
<?php endfor; ?>
      </select>
<?php if ($bufLocked): ?>
      <p class="hint"><?= h(setting_locked_text('audio_buffer_s')) ?></p>
<?php endif; ?>
    </div>
  </div>

  <!-- Bewusst abgesetzt vom Lautstärke-Regler: nur für zu laute Streams -->
  <fieldset class="nur-bei-bedarf">
    <legend>Nur bei zu lautem Stream</legend>
    <div class="row">
      <div class="col-25">
        <label for="audio_input_db">Eingangspegel</label>
      </div>
      <div class="col-75">
        <select id="audio_input_db" name="audio_input_db">
<?php for ($db = AUDIO_INPUT_MAX; $db >= AUDIO_INPUT_MIN; $db--): ?>
          <option value="<?= $db ?>"<?= $db === $cur['audio_input_db'] ? ' selected' : '' ?>><?= h(input_text($db)) ?><?= $std('audio_input_db', $db) ?></option>
<?php endfor; ?>
        </select>
        <p class="hint">Kein zweiter Lautst&auml;rke-Regler. Nur verwenden, wenn die H&ouml;rprobe beim Stream eine Spitze
           von 0 dBFS zeigt: Dann ist der Stream zu laut ausgesteuert, und Werte &uuml;ber 0 dBFS werden schon beim
           Dekodieren abgeschnitten. Der Eingangspegel senkt vorher ab, sodass nichts abgeschnitten wird und das
           Klangprofil wie vorgesehen arbeitet. Lauter oder leiser f&uuml;r die Anrufer stellt man mit der
           Lautst&auml;rke oben.</p>
      </div>
    </div>
  </fieldset>

  <ul class="hint">
    <li><b>Reihenfolge:</b> Telerix bearbeitet den Ton in der Reihenfolge der Felder: Hochpass, Pr&auml;senz,
       Klangprofil, Lautst&auml;rke und zuletzt ein Begrenzer, der Spitzen unter etwa -3,5 dBFS h&auml;lt. Er
       l&auml;uft immer, bei normal ausgesteuertem Stream ber&uuml;hrt er nur seltene Spitzen.</li>
    <li><b>Hochpass:</b> entfernt Brummen, Rumpeln und Trittschall unterhalb der Frequenz. Im Telefonnetz kommt
       unter etwa 300 Hz ohnehin nichts an, HD-Telefonie reicht bis etwa 50 Hz. F&uuml;r reine Sprache passen etwa
       120 Hz, mit Musik eher 80 Hz.</li>
    <li><b>Sprachverst&auml;ndlichkeit (Pr&auml;senz):</b> hebt den Bereich um 2,8 kHz an, in dem Konsonanten liegen.
       Das hilft vor allem bei Sprache, die dumpf klingt, und wirkt auch im Telefonnetz. Geigen, Bl&auml;ser und Chor
       k&ouml;nnen dadurch scharf klingen, deshalb mit der H&ouml;rprobe pr&uuml;fen; meist reichen +2 bis +3 dB.</li>
    <li><b>Klangprofil:</b> &bdquo;Sprache&ldquo; gleicht stark aus, Predigt und Lesung kommen gleichm&auml;&szlig;ig
       laut an; bei Liedern klingt das schnell gepresst. &bdquo;Sprache und Musik&ldquo; gleicht sanfter aus,
       leise und laute Stellen bleiben unterscheidbar. Mit Orgel, Chor oder leisen Passagen ist es einen Versuch
       wert: Mobilfunk und manche Netze halten sehr leise Musik sonst f&uuml;r eine Pause und ersetzen sie durch
       Rauschen oder Stille. &bdquo;Aus&ldquo; l&auml;sst die Dynamik des Streams unver&auml;ndert.</li>
    <li><b>Lautst&auml;rke:</b> von -24 dB (leiser) bis +24 dB (lauter), jedes dB ist etwa 1 dB Unterschied
       am Telefon. Je st&auml;rker angehoben, desto &ouml;fter greift der Begrenzer und desto gepresster klingt
       es. Lauter ist am Telefon nicht besser; lieber mit der H&ouml;rprobe pr&uuml;fen als nach Gef&uuml;hl
       anheben. F&uuml;r einen leisen Stream (etwa -26 dBFS) sind +5 dB ein guter Wert.</li>
    <li><b>Vorlauf:</b> Puffer gegen Stockungen des Streams, um diese Zeit kommt der Ton verz&ouml;gert an.</li>
  </ul>
  <p class="hint">Gespeicherte &Auml;nderungen gelten nach wenigen Sekunden, auch f&uuml;r laufende Anrufe (dabei
     kurz kein Ton). Die
     H&ouml;rprobe nimmt 20 Sekunden des Streams mit den gew&auml;hlten Werten auf, ohne sie zu speichern, und
     dauert etwa so lange.</p>
  <div class="row">
    <input type="submit" value="Speichern und Anwenden">
    <!-- Mit JavaScript im Hintergrund (Ladebalken, telerix.js), sonst normal abgeschickt -->
    <button type="submit" formaction="hoerprobe.php" class="w3-button w3-dark-gray w3-round"
            data-hoerprobe="<?= HOERPROBE_SEKUNDEN + 3 ?>">H&ouml;rprobe mit diesen Werten</button>
  </div>
  </form>
  <div id="hoerprobe-fortschritt" class="w3-light-grey w3-round" style="margin-top:8px" hidden>
    <div class="w3-blue w3-round progress-bar" style="width:0%">&nbsp;</div>
  </div>
  <p id="hoerprobe-meldung" class="hint" aria-live="polite"></p>
<?php include __DIR__ . '/hoerprobe_result.php'; ?>
</div>
