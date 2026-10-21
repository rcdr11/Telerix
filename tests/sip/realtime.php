<?php
/*
 * Liest die Ausgabe von telerix-stream so wie Asterisk: in Echtzeit, alle
 * 20 ms ein Paket. Für tests/sip-test.sh.
 *
 *   telerix-stream | php84 realtime.php <abtastrate> <sekunden> <ausgabe.raw>
 *
 * Ausgabe: verzoegerung (s bis zum ersten Ton), aussetzer (Anzahl Lücken),
 * bei_s (Beginn der Lücken in s ab dem ersten Ton),
 * aussetzer_s (Summe der Zeit, in der kein vollständiges Paket bereitlag).
 * Gezählt wird erst ab dem ersten Ton.
 */
[, $rate, $seconds, $out] = $argv;
$packet = (int)$rate * 2 / 50;          // 20 ms, 16 bit mono
$in = fopen('php://stdin', 'rb');
$fh = fopen($out, 'wb');

$t0 = microtime(true);
$first = fread($in, $packet);           // blockiert bis zum ersten Ton
$delay = microtime(true) - $t0;
fwrite($fh, $first);
stream_set_blocking($in, false);

$buf = '';
$underruns = 0;
$gapAt = [];
$gaps = 0;
$inGap = false;
$ticks = (int)($seconds * 50);
$next = microtime(true);
for ($i = 0; $i < $ticks; $i++) {
    $next += 0.02;
    while (($chunk = fread($in, 65536)) !== false && $chunk !== '') {
        $buf .= $chunk;
    }
    if (strlen($buf) >= $packet) {
        fwrite($fh, substr($buf, 0, $packet));
        $buf = substr($buf, $packet);
        $inGap = false;
    } else {
        $underruns++;
        if (!$inGap) {
            $gaps++;
            $inGap = true;
            $gapAt[] = round($i * 0.02, 1);
        }
    }
    $sleep = $next - microtime(true);
    if ($sleep > 0) {
        usleep((int)($sleep * 1e6));
    }
}
printf("verzoegerung=%.1f aussetzer=%d aussetzer_s=%.2f bei_s=%s\n",
    $delay, $gaps, $underruns * 0.02, $gapAt ? implode(',', $gapAt) : '-');
