<?php
   /* Hinweis bei niedriger Bitrate; erwartet $info aus stream_info(). */
   if (stream_info_low($info)):
?>
  <p class="hint">Der Stream hat nur <?= (int)$info['bitrate'] ?> kbit/s. MP3 ist die erste verlustbehaftete Stufe;
     zum Anrufer kommen G.722 oder G.711 und im Mobilfunk oft noch eine weitere Kodierung dazu, und jede kostet
     Qualit&auml;t. Eine h&ouml;here Bitrate beim Streamanbieter (<?= STREAM_INFO_MIN_KBPS ?> kbit/s oder mehr)
     verbessert den Klang am Telefon.</p>
<?php endif; ?>
