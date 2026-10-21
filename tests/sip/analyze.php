<?php
/*
 * Misst eine Aufnahme (16 bit signed, mono, raw) für tests/sip-test.sh.
 *
 *   php84 analyze.php <datei.raw> <abtastrate> <ab Sekunde>
 *
 * Ausgabe: dauer, rms (dBFS), spitze (dBFS), aussetzer (Anzahl Lücken
 * >= 200 ms mit Abtastwerten |x| <= 2) und aussetzer_s (Summe in Sekunden).
 * Echte Stille im Material ist nie exakt null (Rauschen im Testsignal),
 * solche Lücken entstehen nur, wenn Asterisk keine Daten bekommt.
 */
[, $file, $rate, $skip] = $argv + [null, null, 16000, 0];
$rate = (int)$rate;
$data = file_get_contents($file);
$samples = unpack('s*', substr($data, (int)((float)$skip * $rate) * 2));
$n = count($samples);
if ($n === 0) {
    echo "dauer=0\n";
    exit(1);
}
$sum = 0.0;
$peak = 0;
$run = 0;
$gaps = 0;
$gapSamples = 0;
$minGap = (int)(0.2 * $rate);
foreach ($samples as $s) {
    $sum += $s * $s;
    $a = abs($s);
    if ($a > $peak) {
        $peak = $a;
    }
    if ($a <= 2) {
        $run++;
    } else {
        if ($run >= $minGap) {
            $gaps++;
            $gapSamples += $run;
        }
        $run = 0;
    }
}
if ($run >= $minGap) {
    $gaps++;
    $gapSamples += $run;
}
$db = fn($v) => $v > 0 ? 20 * log10($v / 32768) : -999;
printf("dauer=%.1f rms=%.1f spitze=%.1f aussetzer=%d aussetzer_s=%.1f\n",
    $n / $rate, $db(sqrt($sum / $n)), $db($peak), $gaps, $gapSamples / $rate);
