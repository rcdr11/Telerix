<?php
/*
 * Test-Livestream für tests/sip-test.sh (php -S ... router.php).
 *
 * /live    liefert /srv/test.mp3 (CBR 128 kbit/s) endlos in Echtzeit,
 *          stockt aber alle 10 s für 2,5 s und schickt das Fehlende danach
 *          schubweise nach – wie ein Livestream über eine wackelige Leitung.
 * /steady  dasselbe ohne Stockungen.
 * /musik   /srv/musik.mp3 (Orgelakkord, abwechselnd 8 s leise und laut)
 *          endlos ohne Stockungen, für den Vergleich der Klangprofile.
 * /abbruch /srv/test.mp3, beendet die Verbindung aber nach 8 s – wie ein
 *          abgebrochener Livestream.
 * Jede Verbindung beginnt am Anfang der Datei.
 *
 * Nachbildung von AzuraCast für den Icecast-Status auf der Startseite:
 * /status-json.xsl                  404 (bei AzuraCast die Weboberfläche)
 * /listen/gemeinde/live             wie /live
 * /listen/gemeinde/status-json.xsl  Icecast-Status mit den Mountpoints live
 *                                   (7 Zuhörer, max. 12) und andere
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/listen/gemeinde/status-json.xsl') {
    $host = 'http://' . $_SERVER['HTTP_HOST'];
    header('Content-Type: application/json');
    echo json_encode(['icestats' => ['server_id' => 'Icecast 2.4.0-kh22', 'source' => [
        ['listenurl' => "$host/listen/gemeinde/live", 'listeners' => 7, 'listener_peak' => 12],
        ['listenurl' => "$host/listen/gemeinde/andere", 'listeners' => 3, 'listener_peak' => 4],
    ]]]);
    return true;
}
if ($path === '/listen/gemeinde/live') {
    $path = '/live';
}
if (!in_array($path, ['/live', '/steady', '/musik', '/abbruch'], true)) {
    http_response_code(404);
    return true;
}
$bursty = $path === '/live';
$limit = $path === '/abbruch' ? 8.0 : INF;

header('Content-Type: audio/mpeg');
header('Cache-Control: no-cache');
while (ob_get_level()) {
    ob_end_flush();
}
set_time_limit(0);

$data = file_get_contents($path === '/musik' ? '/srv/musik.mp3' : '/srv/test.mp3');
$len = strlen($data);
$bytesPerSec = 128000 / 8;
$chunk = 1600;            // 100 ms
$pos = 0;
$sent = 0;
$start = microtime(true);

while (!connection_aborted()) {
    $elapsed = microtime(true) - $start;
    if ($elapsed >= $limit) {
        break;
    }
    // Stockung: in den letzten 2,5 s jedes 10-s-Fensters nichts senden
    if ($bursty && fmod($elapsed, 10.0) >= 7.5) {
        usleep(50000);
        continue;
    }
    // Soll-Menge nach Echtzeit; nach einer Stockung wird nachgeholt (Schub)
    while ($sent < ($elapsed + 0.5) * $bytesPerSec) {
        $part = substr($data, $pos, $chunk);
        if (strlen($part) < $chunk) {
            $part .= substr($data, 0, $chunk - strlen($part));
        }
        $pos = ($pos + $chunk) % $len;
        echo $part;
        $sent += $chunk;
    }
    flush();
    usleep(100000);
}
return true;
