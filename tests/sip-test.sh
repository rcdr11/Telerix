#!/bin/bash
# Test mit nachgebildetem SIP-Anbieter und Test-Livestream.
#
# Startet im Compose-Netz:
#   - einen zweiten Asterisk als "Anbieter" (tests/sip/provider), bei dem sich
#     Telerix registriert und der Telerix anruft und den Anruf aufnimmt,
#   - einen Test-Livestream (tests/sip/stream/router.php) mit leiser,
#     verhallter Sprache, der alle 10 s kurz stockt.
# Geprüft werden Begrüßungsansage (auch in 16 kHz), Wartemusik, Abmeldung
# beim Stoppen, doppelte Anrufe nach einem Neustart, Pegel und Aussetzer,
# Klangprofile und Hochpass (mit einem Orgel-Testsignal), Präsenz,
# Eingangspegel und Begrenzer (mit einem übersteuerten Stream), Neuversuch
# nach einem Abbruch des Streams, Hörprobe mit Pegeln, Qualität des Streams,
# Codec-Anzeige und -Wahl (HD oder Telefonqualität), Umrechnung in Asterisk,
# kein Comfort Noise und die Codec-Diagnose im Protokoll.
#
# Aufruf: tests/sip-test.sh   (Container muss laufen; ändert SIP-, Stream-
# und Ansage-Einstellungen und erzeugt den Telerix-Container einmal neu)

set -u
cd "$(dirname "$0")/.." || exit 1
export MSYS_NO_PATHCONV=1
DC="docker compose"
NET="telerix_default"
PROV="telerix-test-provider"
STREAM="telerix-test-stream"
BASE="http://127.0.0.1:8080"
PASS="$(tr -d '\r\n' < secrets/admin_password.txt)"
winpath() { if command -v cygpath >/dev/null; then cygpath -m "$(realpath "$1")"; else realpath "$1"; fi; }
TMP="$(mktemp -d)"
# Natives curl unter Windows braucht Windows-Pfade
command -v cygpath >/dev/null && TMP="$(cygpath -m "$TMP")"
JAR="$TMP/cookies"
fails=0
ok()   { echo "  OK   $*"; }
bad()  { echo "  FAIL $*"; fails=$((fails + 1)); }
info() { echo "  INFO $*"; }
check() { if eval "$2"; then ok "$1"; else bad "$1"; fi; }

in_tx() { $DC exec -T telerix sh -c "$1"; }
tx_cli() { $DC exec -T telerix asterisk -rx "$1"; }
prov_cli() { docker exec "$PROV" asterisk -C /provider/asterisk.conf -rx "$1"; }
ip_of() { docker inspect -f "{{(index .NetworkSettings.Networks \"$NET\").IPAddress}}" "$1"; }
wait_healthy() {
   for _ in $(seq 1 40); do
      [ "$(docker inspect -f '{{.State.Health.Status}}' "$($DC ps -q telerix)" 2>/dev/null)" = healthy ] && return 0
      sleep 2
   done
   return 1
}
wait_registered() {
   for _ in $(seq 1 30); do
      tx_cli "pjsip show registrations" | grep -q "Registered" && return 0
      sleep 2
   done
   return 1
}
prov_contacts() { prov_cli "pjsip show contacts" | grep -c "Contact:  4711/"; }
tx_pjsip_channels() { tx_cli "core show channels concise" | grep -c "^PJSIP/"; }

# Webinterface
token() { curl -s -b "$JAR" -c "$JAR" "$BASE/$1" | grep -o 'name="csrf" value="[0-9a-f]*"' | head -1 | sed 's/.*value="//;s/"//'; }
login() {
   rm -f "$JAR"
   local t; t="$(token index.php)"
   curl -s -o /dev/null -b "$JAR" -c "$JAR" --data-urlencode "csrf=$t" -d tuser=admin --data-urlencode "tpass=$PASS" "$BASE/login.php"
}
set_stream() { local t; t="$(token configuration.php)"; curl -s -o /dev/null -b "$JAR" -c "$JAR" --data-urlencode "csrf=$t" --data-urlencode "inetstream=$1" "$BASE/set_stream.php"; }
set_sip() { local t; t="$(token configuration.php)"; curl -s -o /dev/null -b "$JAR" -c "$JAR" --data-urlencode "csrf=$t" --data-urlencode "sip_id=$1" --data-urlencode "sip_pw=$2" --data-urlencode "sip_reg=$3" "$BASE/set_sipconfig.php"; }
set_audio() { local t; t="$(token configuration.php)"; curl -s -o /dev/null -b "$JAR" -c "$JAR" --data-urlencode "csrf=$t" -d "audio_gain_db=$1" -d "audio_buffer_s=$2" -d audio_profile=sprache -d audio_highpass_hz=180 -d audio_presence_db=0 -d audio_input_db=0 -d audio_processing=1 -d audio_codec=hd "$BASE/set_audio.php"; }
set_codec() { local t; t="$(token configuration.php)"; curl -s -o /dev/null -b "$JAR" -c "$JAR" --data-urlencode "csrf=$t" -d "audio_codec=$1" "$BASE/set_audio.php"; }
# Kanal des laufenden Anrufs bei Telerix: Formate und Umrechnung
tx_channel() { tx_cli "core show channel $(tx_cli "core show channels concise" | grep "^PJSIP/" | head -1 | cut -d"!" -f1)"; }

# Anrufe, die Telerix seit dem Start bekommen hat ("calls processed")
tx_calls_processed() { tx_cli "core show channels count" | awk '/calls? processed/ {print $1}'; }
# Anruf vom Anbieter an Telerix, Aufnahme beim Anbieter; prüft in den ersten 6 s,
# welche Ansage läuft, und zählt, wie viele Anrufe bei Telerix ankommen.
# Hat der Anbieter eine veraltete Zweitregistrierung, schickt er zwei INVITEs.
# call <Name> <Sekunden>  -> setzt CALL_APP, CALL_CHANNELS, CALL_STATS
call() {
   local before; before="$(tx_calls_processed)"
   prov_cli "channel originate Local/anruf@provider application Record /tmp/$1.wav16,0,$2,qx" >/dev/null
   # 6 s lang alle 0,5 s nachsehen, welche Anwendung läuft; Playback merken
   # und ob schon die Wartemusik (Stream) läuft
   CALL_APP=""
   CALL_MOH=""
   for _ in $(seq 1 12); do
      app="$(tx_cli "core show channels concise" | grep "^PJSIP/" | head -1 | cut -d'!' -f6,7)"
      case "$app" in Playback*) CALL_APP="$app" ;; *) [ -z "$CALL_APP" ] && CALL_APP="$app" ;; esac
      case "$app" in MusicOnHold*) CALL_MOH=1 ;; esac
      sleep 0.5
   done
   sleep 2
   CALL_CHANNELS=$(( $(tx_calls_processed) - before ))
   sleep $(($2 - 6))
   docker exec "$PROV" sh -c "sox -t wav /tmp/$1.wav16 -t raw /tmp/$1.raw" 2>/dev/null
   CALL_STATS="$(docker exec "$PROV" php84 /analyze.php "/tmp/$1.raw" 16000 "$ANSAGE_SKIP")"
   # Fehlt Asterisk Audio, sendet es keine Pakete: die Aufnahme ist dann
   # kürzer als der Anruf. Fehlende Sekunden = Anrufdauer - Aufnahmedauer.
   local dauer; dauer="$(docker exec "$PROV" php84 /analyze.php "/tmp/$1.raw" 16000 0 | awk '{split($1, a, "="); print a[2]}')"
   CALL_MISSING="$(awk -v d="$dauer" -v s="$2" 'BEGIN {printf "%.1f", s - d}')"
}

cleanup() {
   login
   set_sip "" "" ""
   set_stream ""
   docker rm -f "$PROV" "$STREAM" >/dev/null 2>&1
   rm -rf "$TMP"
}
trap cleanup EXIT

echo "== Vorbereitung"
# Testsignal: leise, verhallte Sprache mit Grundrauschen, 44,1 kHz Stereo,
# RMS etwa -26 dBFS, MP3 128 kbit/s (wie ein Gottesdienst-Stream)
cp tests/sip/stream/router.php "$TMP/"
docker run --rm -v "$(winpath "$TMP"):/out" --entrypoint sh telerix:local -c '
   cd /usr/share/asterisk/sounds/en
   sox -n -r 8000 -c 1 /tmp/pause.wav trim 0 0.8
   set --
   for f in $(ls *.gsm | awk "NR % 11 == 0" | head -40); do set -- "$@" "$f" /tmp/pause.wav; done
   sox "$@" /tmp/sprache.wav
   sox /tmp/sprache.wav -r 44100 -c 2 /tmp/hall.wav reverb 60 50 90
   dauer=$(soxi -D /tmp/hall.wav)
   sox -n -r 44100 -c 2 /tmp/rauschen.wav synth "$dauer" pinknoise vol 0.004
   sox -m /tmp/hall.wav /tmp/rauschen.wav /tmp/mix.wav
   rms=$(sox /tmp/mix.wav -n stats 2>&1 | awk "/RMS lev dB/ {print \$4}")
   sox /tmp/mix.wav -C 128 /out/test.mp3 gain "$(awk -v r="$rms" "BEGIN {print -26 - r}")"
   sox /out/test.mp3 -n stats 2>&1 | grep -E "RMS lev|Pk lev|Length"
   # Orgelakkord (131, 165, 196, 262 Hz), abwechselnd 8 s leise (etwa
   # -42 dBFS) und laut (etwa -24 dBFS), wie piano und forte
   cd /tmp
   for f in 131 165 196 262; do sox -n -r 44100 -c 1 t$f.wav synth 8 sine $f; done
   sox -m t131.wav t165.wav t196.wav t262.wav akkord.wav
   sox akkord.wav akkord-n.wav gain -n -1
   sox akkord-n.wav leise.wav gain -30 fade 0.3 8 0.3
   sox akkord-n.wav laut.wav gain -12 fade 0.3 8 0.3
   sox leise.wav laut.wav leise.wav laut.wav -c 2 -C 128 /out/musik.mp3
   # Übersteuerter Stream: dieselbe Sprache mit Orgel (Sägezahn), 6 dB über
   # Vollaussteuerung abgeschnitten; beim Dekodieren entstehen Werte über
   # 0 dBFS. Dazu dieselbe Sprache mit nur 64 kbit/s.
   d=$(soxi -D /tmp/mix.wav)
   sox -n -r 44100 -c "$(soxi -c /tmp/mix.wav)" saege.wav synth "$d" sawtooth 131 sawtooth 196 gain -n -14
   sox -m /tmp/mix.wav saege.wav heiss.wav
   sox heiss.wav -C 128 /out/heiss.mp3 gain -n 0 gain 6 2>/dev/null
   sox /out/test.mp3 -C 64 /out/niedrig.mp3
' | sed 's/^/  INFO Testsignal: /'
docker rm -f "$PROV" "$STREAM" >/dev/null 2>&1
docker run -d --name "$STREAM" --network "$NET" -e PHP_CLI_SERVER_WORKERS=4 -v "$(winpath "$TMP"):/srv:ro" --entrypoint php84 \
   telerix:local -S 0.0.0.0:8099 /srv/router.php >/dev/null
docker run -d --name "$PROV" --network "$NET" -v "$(winpath tests/sip/provider):/provider:ro" \
   -v "$(winpath tests/sip/analyze.php):/analyze.php:ro" --entrypoint sh telerix:local \
   -c 'mkdir -p /run/provider && exec /usr/sbin/asterisk -f -C /provider/asterisk.conf' >/dev/null
for _ in $(seq 1 20); do prov_cli "core show uptime" >/dev/null 2>&1 && break; sleep 1; done
prov_cli "pjsip set logger on" >/dev/null
PROV_IP="$(ip_of "$PROV")"
STREAM_IP="$(ip_of "$STREAM")"
check "Anbieter läuft ($PROV_IP), Teststream läuft ($STREAM_IP)" '[ -n "$PROV_IP" ] && [ -n "$STREAM_IP" ]'

login
# Eine von anderen Tests hochgeladene eigene Ansage entfernen
t="$(token ansage.php)"
curl -s -o /dev/null -b "$JAR" -c "$JAR" --data-urlencode "csrf=$t" -d action=delete "$BASE/ansage.php"
# Gemessen wird mit den Standardwerten der Tonaufbereitung (docs/tonqualitaet.md):
# +5 dB, 4 s Vorlauf, Profil Sprache, Hochpass 180 Hz
set_audio 5 4
set_stream "http://$STREAM_IP:8099/live"
set_sip 4711 TestGeheim123 "$PROV_IP"
check "Telerix registriert sich beim Anbieter" 'wait_registered' || tx_cli "pjsip show registrations" | grep telerix-trunk-reg | sed "s/^/  INFO /"
check "Anbieter hat genau eine Registrierung" '[ "$(prov_contacts)" = 1 ]'
info "Contact: $(prov_cli "pjsip show contacts" | grep "Contact:  4711/" | awk '{print $2}')"
# Dauer der Ansage aus der Dateigröße (GSM: 1650 Byte je Sekunde; soxi kennt GSM-Dauern nicht)
ANSAGE_LEN="$(( $(in_tx "stat -c %s /usr/share/asterisk/sounds/tx/willkommen-gottesdienst.gsm" | tr -d '\r') / 1650 ))"
ANSAGE_SKIP="$(awk -v d="$ANSAGE_LEN" 'BEGIN {printf "%d", d + 6}')"

echo "== Einstellungen in Asterisk"
check "Data directory ist /usr/share/asterisk" 'tx_cli "core show settings" | grep -q "Data directory: *\/usr\/share\/asterisk"'
check "Ton 'beep' ist auffindbar" 'tx_cli "core show sound beep" | grep -qi "gsm"'

echo "== Skriptausgabe (wie Asterisk in Echtzeit gelesen, 40 s)"
RATE=8000
in_tx "grep -q '^format=slin16' /etc/asterisk/musiconhold.conf" && RATE=16000
$DC cp tests/sip/analyze.php telerix:/tmp/analyze.php >/dev/null 2>&1
$DC cp tests/sip/realtime.php telerix:/tmp/realtime.php >/dev/null 2>&1
# Testkopie markieren und danach samt Kindprozessen beenden (die Wartemusik
# von Asterisk läuft parallel weiter und bleibt unberührt)
in_tx "rm -f /tmp/echtzeit.txt
   su -s /bin/sh asterisk -c 'TELERIX_SKRIPTTEST=1 /usr/local/bin/telerix-stream | php84 /tmp/realtime.php $RATE 40 /tmp/skript.raw > /tmp/echtzeit.txt' 2>/dev/null &
   for i in \$(seq 1 60); do [ -s /tmp/echtzeit.txt ] && break; sleep 1; done
   for p in /proc/[0-9]*; do grep -qz TELERIX_SKRIPTTEST=1 \$p/environ 2>/dev/null && kill -9 \${p#/proc/} 2>/dev/null; done; true"
ECHTZEIT="$(in_tx "cat /tmp/echtzeit.txt")"
SKRIPT="$(in_tx "php84 /tmp/analyze.php /tmp/skript.raw $RATE 0" | sed 's/ aussetzer.*//')"
info "Abtastrate $RATE Hz: $SKRIPT"
info "Echtzeit-Leser: $ECHTZEIT"
check "Keine Aussetzer trotz Stockungen im Stream" 'grep -q "aussetzer=0 " <<<"$ECHTZEIT"'
check "Verzögerung bis zum Ton höchstens 6 s" 'awk -v s="$ECHTZEIT" "BEGIN {split(s, a, \"verzoegerung=\"); exit !(a[2] + 0 <= 6)}"'

echo "== Klangprofile und Hochpass (Hörprobe-Modus, wie im Webinterface)"
# probe <Name> <Anhebung> <Hochpass> <Profil> <Sekunden> [<Präsenz> <Eingangspegel> <an|aus>]:
# Ausgabe als Benutzer telerix (wie die Hörprobe im Webinterface) nach
# /tmp/probe-<Name>.raw
probe() { in_tx "su -s /bin/sh telerix -c '/usr/local/bin/telerix-stream --hoerprobe $5 ${8:-an} ${7:-0} $3 ${6:-0} $4 $2' > /tmp/probe-$1.raw"; }
# Spitze eines Abschnitts und Anzahl der Abtastwerte mit Vollausschlag
pk() { in_tx "sox -t raw -r 16000 -e signed -b 16 -c 1 /tmp/probe-$1.raw -n stats 2>&1" | awk '/Pk lev dB/ {print $4}'; }
voll() { in_tx "od -An -v -t d2 -w2 /tmp/probe-$1.raw" | awk '$1 >= 32767 || $1 <= -32768 {n++} END {print n + 0}'; }
# RMS eines Abschnitts: seg <Name> <ab Sekunde> <Länge>
seg() { in_tx "sox -t raw -r 16000 -e signed -b 16 -c 1 /tmp/probe-$1.raw -n trim $2 $3 stats 2>&1" | awk '/RMS lev dB/ {print $4}'; }
# Unterschied laut - leise im Orgelsignal (Abschnitte 1-7 s und 9-15 s)
dyn() { awk -v a="$(seg "$1" 9 6)" -v b="$(seg "$1" 1 6)" 'BEGIN {printf "%.1f", a - b}'; }
set_stream "http://$STREAM_IP:8099/steady"
probe musiksprache 5 180 musik 20
MS="$(in_tx "php84 /tmp/analyze.php /tmp/probe-musiksprache.raw 16000 0" | sed 's/ aussetzer.*//')"
info "Sprache mit Profil 'Sprache und Musik': $MS"
check "Hörprobe liefert genau 20 s" '[ "$(in_tx "stat -c %s /tmp/probe-musiksprache.raw")" = 640000 ]'
check "Sprache auch mit 'Sprache und Musik' bei -20 dBFS (±3)" 'awk -v s="$MS" "BEGIN {split(s, a, \"rms=\"); r = a[2] + 0; exit !(r >= -23 && r <= -17)}"'
set_stream "http://$STREAM_IP:8099/musik"
probe sprache 5 180 sprache 16
probe musik 5 180 musik 16
probe aus 5 0 aus 16
probe aus250 5 250 aus 16
info "Orgel leise/laut (Original 18 dB Unterschied): Sprache $(dyn sprache) dB, Sprache und Musik $(dyn musik) dB, aus $(dyn aus) dB"
check "'Sprache und Musik' erhält mehr Dynamik als 'Sprache' (mind. 2 dB)" 'awk -v m="$(dyn musik)" -v s="$(dyn sprache)" "BEGIN {exit !(m >= s + 2)}"'
check "'Aus' erhält die Dynamik (mind. 15 dB)" 'awk -v a="$(dyn aus)" "BEGIN {exit !(a >= 15)}"'
info "Orgel laut ohne Hochpass $(seg aus 9 6) dBFS, mit Hochpass 250 Hz $(seg aus250 9 6) dBFS"
check "Hochpass 250 Hz dämpft den Akkord (131-262 Hz) um mind. 3 dB" 'awk -v a="$(seg aus 9 6)" -v b="$(seg aus250 9 6)" "BEGIN {exit !(a - b >= 3)}"'
# Standard (alles aus): Ton wie im Stream; Vergleich mit dem direkt dekodierten Teststream
$DC cp "$TMP/test.mp3" telerix:/tmp/test.mp3 >/dev/null 2>&1
set_stream "http://$STREAM_IP:8099/steady"
probe aus0 0 0 aus 20
probe leiser -12 0 aus 20
probe ganzaus 0 0 aus 20 0 0 aus
REF="$(in_tx "sox /tmp/test.mp3 -n remix - rate 16000 trim 0 20 stats 2>&1" | awk '/RMS lev dB/ {print $4}')"
info "Teststream direkt: RMS $REF dBFS; Standard: $(seg aus0 0 20) dBFS, Spitze $(pk aus0) dBFS; Aufbereitung aus: $(seg ganzaus 0 20) dBFS; -12 dB: $(seg leiser 0 20) dBFS"
check "Standard (nur Begrenzer): Pegel wie im Stream (±0,5 dB)" 'awk -v a="$(seg aus0 0 20)" -v r="$REF" "BEGIN {d = a - r; exit !(d >= -0.5 && d <= 0.5)}"'
check "Tonaufbereitung aus: Pegel wie im Stream (±0,5 dB)" 'awk -v a="$(seg ganzaus 0 20)" -v r="$REF" "BEGIN {d = a - r; exit !(d >= -0.5 && d <= 0.5)}"'
check "Lautstärke -12 dB macht 12 dB leiser (±0,5 dB)" 'awk -v a="$(seg aus0 0 20)" -v b="$(seg leiser 0 20)" "BEGIN {d = a - b; exit !(d >= 11.5 && d <= 12.5)}"'
check "Ungültige Werte für die Hörprobe abgelehnt" '! in_tx "su -s /bin/sh telerix -c \"/usr/local/bin/telerix-stream --hoerprobe 5 an 0 100 0 sprache 5\"" >/dev/null 2>&1'

echo "== Begrenzer, Präsenz und Eingangspegel (übersteuerter Stream)"
set_stream "http://$STREAM_IP:8099/heiss"
probe h-aus 0 0 aus 20 0 0 aus
probe h-std 0 0 aus 20
probe h-praes 0 0 aus 20 4
probe h-praes-spr 5 180 sprache 20 4
probe h-ein 0 0 aus 20 0 -3
info "Aufbereitung aus: Spitze $(pk h-aus) dBFS, $(voll h-aus) Werte mit Vollausschlag; Standard: Spitze $(pk h-std) dBFS, $(voll h-std); Präsenz +4: $(pk h-praes) dBFS, $(voll h-praes); Präsenz +4, Sprache, +5 dB: $(pk h-praes-spr) dBFS; Eingangspegel -3: $(pk h-ein) dBFS"
check "Ohne Aufbereitung erreicht der Stream 0 dBFS (Testsignal stimmt)" '[ "$(voll h-aus)" -gt 0 ]'
check "Begrenzer läuft immer: Spitzen unter -3 dBFS, nichts abgeschnitten" 'awk -v p="$(pk h-std)" "BEGIN {exit !(p < -3)}" && [ "$(voll h-std)" = 0 ]'
check "Präsenz +4 dB bei 0 dB Lautstärke übersteuert nicht" 'awk -v p="$(pk h-praes)" "BEGIN {exit !(p < -3)}" && [ "$(voll h-praes)" = 0 ]'
check "Präsenz, Klangprofil und Anhebung zusammen: Spitzen unter -3 dBFS" 'awk -v p="$(pk h-praes-spr)" "BEGIN {exit !(p < -3)}"'
check "Eingangspegel -3 dB senkt ab" 'awk -v a="$(seg h-std 0 20)" -v b="$(seg h-ein 0 20)" "BEGIN {exit !(a - b >= 0.5)}"'
# Hörprobe im Webinterface: Spitze des Streams und Hinweis
hp_json() { curl -s -b "$JAR" -c "$JAR" -H "Accept: application/json" --data-urlencode "csrf=$(token configuration.php)" -d audio_processing=1 -d audio_gain_db=0 -d audio_profile=aus -d audio_highpass_hz=0 -d audio_presence_db=0 -d "audio_input_db=$1" "$BASE/hoerprobe.php"; }
j="$(hp_json 0)"
check "Hörprobe zeigt Pegel (Stream und aufbereitet)" 'grep -q "Gemessen &uuml;ber 20 s" <<<"$j" && grep -q "Stream, unbearbeitet" <<<"$j"'
check "Hörprobe: übersteuerter Stream erreicht 0 dBFS, Hinweis auf Eingangspegel" 'grep -q "Der Stream erreicht 0 dBFS" <<<"$j"'
j="$(hp_json -3)"
info "Pegel mit Eingangspegel -3 dB (Stream RMS, Spitze; aufbereitet RMS, Spitze): $(grep -o "[-+]\?[0-9]\+,[0-9] dBFS" <<<"$j" | tr "\n" " ")"
check "Mit Eingangspegel -3 dB: Spitze über 0 dBFS sichtbar, nichts abgeschnitten" 'grep -q "geht &uuml;ber 0 dBFS hinaus" <<<"$j" && ! grep -q "Der Stream erreicht 0 dBFS" <<<"$j"'

echo "== Qualität des Streams"
in_tx "rm -f /var/lib/telerix/state/stream_info.json"
set_stream "http://$STREAM_IP:8099/niedrig"
check "64 kbit/s: Qualität und Hinweis bei Internetstream" 'curl -s -b "$JAR" -c "$JAR" "$BASE/configuration.php" | grep -q "MP3 64 kbit/s, 44,1 kHz" && curl -s -b "$JAR" "$BASE/configuration.php" | grep -q "Der Stream hat nur 64 kbit/s"'
check "64 kbit/s: Hinweis auch auf der Startseite" 'curl -s -b "$JAR" -c "$JAR" "$BASE/start.php" | grep -q "Der Stream hat nur 64 kbit/s"'
set_stream "http://$STREAM_IP:8099/steady"
check "128 kbit/s: Qualität ohne Hinweis" 'curl -s -b "$JAR" -c "$JAR" "$BASE/configuration.php" | grep -q "MP3 128 kbit/s, 44,1 kHz" && ! curl -s -b "$JAR" "$BASE/configuration.php" | grep -q "Der Stream hat nur"'

echo "== Hörprobe im Webinterface"
t="$(token configuration.php)"
curl -s -o /dev/null -b "$JAR" -c "$JAR" --data-urlencode "csrf=$t" -d audio_gain_db=5 -d audio_profile=musik -d audio_highpass_hz=120 -d audio_presence_db=3 "$BASE/hoerprobe.php"
conf="$(curl -s -b "$JAR" -c "$JAR" "$BASE/configuration.php")"
check "Hörprobe erstellt und angezeigt" 'grep -q "H&ouml;rprobe vom" <<<"$conf" && grep -q "Sprache und Musik," <<<"$conf" && grep -q "Pr&auml;senz +3 dB" <<<"$conf"'
probe_json="$(curl -s -b "$JAR" -c "$JAR" -H "Accept: application/json" --data-urlencode "csrf=$(token configuration.php)" -d audio_gain_db=-6 -d audio_profile=aus -d audio_highpass_hz=0 "$BASE/hoerprobe.php")"
check "Hörprobe im Hintergrund (JSON mit neuem Ergebnis-Block)" 'grep -q "\"ok\":true" <<<"$probe_json" && grep -q "<audio" <<<"$probe_json" && grep -q "Lautst&auml;rke -6 dB" <<<"$probe_json"'
check "Hörprobe hat nichts gespeichert" 'in_tx "grep -qx PROFIL=sprache /var/lib/telerix/asterisk/audio.conf"'
for f in telefon hd; do
   curl -s -b "$JAR" -c "$JAR" -o "$TMP/probe-$f.wav" -D "$TMP/probe-$f.hdr" "$BASE/hoerprobe.php?f=$f"
   $DC cp "$TMP/probe-$f.wav" "telerix:/tmp/probe-$f.wav" >/dev/null 2>&1
done
check "Fassung 'Telefonnetz' als WAV mit 8 kHz" 'grep -qi "^content-type: audio/wav" "$TMP/probe-telefon.hdr" && in_tx "soxi -r /tmp/probe-telefon.wav" | grep -qx 8000'
check "Fassung 'HD' als WAV mit 16 kHz" 'in_tx "soxi -r /tmp/probe-hd.wav" | grep -qx 16000'
check "Hörprobe ohne Anmeldung nicht abrufbar" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$BASE/hoerprobe.php?f=hd")" = 303 ]'

echo "== Neuversuch nach Abbruch des Streams"
# Der Teststream bricht jede Verbindung nach 8 s ab. Früher kam der Ton erst
# nach gut 30 s wieder.
set_stream "http://$STREAM_IP:8099/abbruch"
in_tx "rm -f /tmp/abbruch.txt
   su -s /bin/sh asterisk -c 'TELERIX_SKRIPTTEST=1 /usr/local/bin/telerix-stream | php84 /tmp/realtime.php 16000 20 /tmp/abbruch.raw > /tmp/abbruch.txt' 2>/dev/null &
   for i in \$(seq 1 60); do [ -s /tmp/abbruch.txt ] && break; sleep 1; done
   for p in /proc/[0-9]*; do grep -qz TELERIX_SKRIPTTEST=1 \$p/environ 2>/dev/null && kill -9 \${p#/proc/} 2>/dev/null; done; true"
ABBRUCH="$(in_tx "cat /tmp/abbruch.txt")"
info "Echtzeit-Leser: $ABBRUCH"
check "Abbruch erkannt (Lücke im Ton)" 'grep -q "aussetzer=[1-9]" <<<"$ABBRUCH"'
check "Ton nach höchstens 10 s wieder da" 'awk -v s="$ABBRUCH" "BEGIN {split(s, a, \"aussetzer_s=\"); exit !(a[2] + 0 <= 10)}"'

echo "== Icecast-Status auf der Startseite (nachgebildetes AzuraCast)"
set_icecast() { local t; t="$(token configuration.php)"; curl -s -o /dev/null -b "$JAR" -c "$JAR" --data-urlencode "csrf=$t" --data-urlencode "icecast_status_url=$1" -d "icecast_panel=$2" "$BASE/set_icecast.php"; }
startseite() { curl -s -b "$JAR" -c "$JAR" "$BASE/start.php"; }
set_icecast "" 1
set_stream "http://$STREAM_IP:8099/listen/gemeinde/live"
s="$(startseite)"
check "Status neben der Stream-Adresse gefunden (/listen/gemeinde/)" 'grep -q ">live</a>" <<<"$s" && grep -q "<td>7</td>" <<<"$s" && grep -q "<td>12</td>" <<<"$s"'
check "Nur der eigene Mountpoint angezeigt" '! grep -q ">andere</a>" <<<"$s"'
set_stream "http://$STREAM_IP:8099/live"
s="$(startseite)"
check "Ohne Status: Grund wird angezeigt (HTTP 404)" 'grep -q "Icecast-Status nicht abrufbar: Keine Icecast-Statusseite (HTTP 404)" <<<"$s"'
set_icecast "http://$STREAM_IP:8099/listen/gemeinde" 1
s="$(startseite)"
check "Eigene Statusadresse (status-json.xsl ergänzt)" 'grep -q "<td>7</td>" <<<"$s" && curl -s -b "$JAR" "$BASE/configuration.php" | grep -q "/listen/gemeinde/status-json.xsl"'
check "Mountpoint nicht zuzuordnen: alle angezeigt" 'grep -q ">andere</a>" <<<"$s" && grep -q "Alle Mountpoints des Servers" <<<"$s"'
set_icecast "" 1
t="$(token start.php)"
curl -s -o /dev/null -b "$JAR" -c "$JAR" --data-urlencode "csrf=$t" -d action=hide "$BASE/set_icecast.php"
check "Ausgeblendet" '! startseite | grep -q "Internetradiostreams"'
set_icecast "" 1
check "Wieder eingeblendet" 'startseite | grep -q "Internetradiostreams"'

echo "== Anruf (Ansage und Wartemusik)"
START="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
call anruf1 45
info "Anwendung: $CALL_APP"
info "Aufnahme ab Sekunde $ANSAGE_SKIP: $CALL_STATS"
check "Begrüßungsansage wird gespielt" 'grep -q "Playback!tx/willkommen-gottesdienst" <<<"$CALL_APP"'
check "Codec-Diagnose im Protokoll, ohne Rufnummer" '$DC logs --since "$START" telerix 2>/dev/null | grep -q "Codec: angeboten und unterstützt (g722|alaw), gewählt (g722)"'
check "Keine Rufnummer in der Diagnose" '! $DC logs --since "$START" telerix 2>/dev/null | grep "Codec:" | grep -q "4711\|anruf"'
check "Keine Meldung 'does not exist'" '! $DC logs --since "$START" telerix 2>/dev/null | grep -q "does not exist"'
check "Genau ein eingehender Anruf bei Telerix" '[ "$CALL_CHANNELS" = 1 ]'
info "Aufnahme kürzer als der Anruf um $CALL_MISSING s (davon 2 s Wait() im Dialplan ohne Ton)"
# Zielwerte der Aufbereitung (docs/tonqualitaet.md)
stat_of() { awk -v s="$CALL_STATS" -v k="$1=" 'BEGIN {split(s, a, k); split(a[2], b, " "); print b[1] + 0}'; }
check "Pegel der Wartemusik -20 dBFS (±3)" 'awk -v r="$(stat_of rms)" "BEGIN {exit !(r >= -23 && r <= -17)}"'
check "Spitzen unter -3 dBFS" 'awk -v p="$(stat_of spitze)" "BEGIN {exit !(p < -3)}"'

echo "== Eigene Ansage wird bevorzugt"
docker exec "$PROV" sh -c "sox -n -r 44100 -c 1 /tmp/eigene.wav synth 6 sine 600 vol 0.3"
docker cp "$PROV:/tmp/eigene.wav" "$(winpath "$TMP")/eigene.wav" >/dev/null
t="$(token ansage.php)"
curl -s -o /dev/null -b "$JAR" -c "$JAR" -F "csrf=$t" -F "ansage=@$(winpath "$TMP/eigene.wav")" "$BASE/ansage.php"
call anruf2 10
info "Anwendung: $CALL_APP"
check "Hochgeladene Ansage wird gespielt" 'grep -q "Playback!/var/lib/telerix/sounds/ansage" <<<"$CALL_APP"'

echo "== Ansage in HD und Telefonqualität, Umrechnung in Asterisk"
# ansage <Name>: Anruf, nach 3 s (Ansage läuft) die Formate des Kanals
ansage_kanal() {
   prov_cli "channel originate Local/anruf@provider application Wait 9" >/dev/null
   sleep 3.5
   KANAL="$(tx_channel)"
   STARTSEITE="$(curl -s -b "$JAR" -c "$JAR" "$BASE/start.php")"
   info "$1: $(grep -E "NativeFormats|WriteTranscode" <<<"$KANAL" | tr -s " " | tr "\n" " ")"
   sleep 7
}
ansage_kanal "HD bevorzugen, Ansage"
check "G.722: Ansage aus ansage.wav16 direkt kodiert (16 kHz, ohne Umweg über 8 kHz)" 'grep -q "NativeFormats: (g722)" <<<"$KANAL" && grep -q "WriteTranscode: Yes (slin@16000)->(g722@16000)" <<<"$KANAL"'
set_codec telefon
ansage_kanal "Telefonqualität bevorzugen, Ansage"
check "Telefonqualität: Angebot G.722 und G.711, gewählt A-law" 'grep -q "NativeFormats: (alaw)" <<<"$KANAL"'
check "G.711: Ansage aus ansage.wav (8 kHz) direkt kodiert" 'grep -q "WriteTranscode: Yes (slin@8000)->(alaw@8000)" <<<"$KANAL"'

echo "== Ansage abgeschaltet"
t="$(token ansage.php)"
curl -s -o /dev/null -b "$JAR" -c "$JAR" --data-urlencode "csrf=$t" -d action=disable "$BASE/ansage.php"
call anruf2b 10
info "Anwendung: $CALL_APP"
check "Keine Ansage, obwohl eine eigene hochgeladen ist" '! grep -q "Playback" <<<"$CALL_APP"'
check "Sofort Wartemusik (Stream)" '[ "$CALL_MOH" = 1 ]'
t="$(token ansage.php)"
curl -s -o /dev/null -b "$JAR" -c "$JAR" --data-urlencode "csrf=$t" -d action=enable "$BASE/ansage.php"
t="$(token ansage.php)"
curl -s -o /dev/null -b "$JAR" -c "$JAR" --data-urlencode "csrf=$t" -d action=delete "$BASE/ansage.php"

echo "== Wartemusik: Umrechnung in Asterisk"
t="$(token ansage.php)"
curl -s -o /dev/null -b "$JAR" -c "$JAR" --data-urlencode "csrf=$t" -d action=disable "$BASE/ansage.php"
ansage_kanal "Telefonqualität bevorzugen, Wartemusik"
check "G.711: Wartemusik einmal auf 8 kHz umgerechnet, einmal kodiert" 'grep -q "WriteTranscode: Yes (slin@16000)->(slin@8000)->(alaw@8000)" <<<"$KANAL"'
check "Startseite zeigt Telefonqualität (G.711)" 'grep -q "Telefonqualität (G.711)" <<<"$STARTSEITE"'
# Codec wechseln, während ein Anruf läuft: Anruf bleibt, ohne Aussetzer
prov_cli "channel originate Local/anruf@provider application Record /tmp/wechsel.wav16,0,16,qx" >/dev/null
sleep 4
set_codec hd
sleep 3
check "Codec-Wechsel (PJSIP neu geladen): laufender Anruf bleibt bestehen" '[ "$(tx_pjsip_channels)" = 1 ]'
sleep 11
docker exec "$PROV" sh -c "sox -t wav /tmp/wechsel.wav16 -t raw /tmp/wechsel.raw" 2>/dev/null
WECHSEL="$(docker exec "$PROV" php84 /analyze.php /tmp/wechsel.raw 16000 2)"
info "Aufnahme beim Wechsel: $WECHSEL"
check "Keine Aussetzer beim Wechsel" 'grep -q "aussetzer=0 " <<<"$WECHSEL"'
ansage_kanal "HD bevorzugen, Wartemusik"
check "G.722: Wartemusik direkt aus 16 kHz kodiert" 'grep -q "WriteTranscode: Yes (slin@16000)->(g722@16000)" <<<"$KANAL"'
# Anbieter bietet nur G.722 an: Anruf muss auch bei Telefonqualität zustande kommen
set_codec telefon
prov_cli "channel originate Local/nurg722@provider application Wait 8" >/dev/null
sleep 4
KANAL="$(tx_channel)"
check "Telefonqualität, Angebot nur G.722: Anruf kommt mit G.722 zustande" 'grep -q "NativeFormats: (g722)" <<<"$KANAL"'
sleep 5
set_codec hd
check "Telerix bietet kein Comfort Noise an (SDP ohne CN)" '! docker logs --since "$START" "$PROV" 2>&1 | grep -qi "rtpmap:[0-9]* CN/"'
check "QoS: tos_audio EF am Trunk, CS3 am Transport, ohne Warnung" 'tx_cli "pjsip show endpoint telerix-trunk" | grep -Eq "tos_audio +: 184" && tx_cli "pjsip show transport transport-udp" | grep -Eq " tos +: 96" && ! $DC logs telerix 2>/dev/null | grep -q "Unable to set .* TOS"'
t="$(token ansage.php)"
curl -s -o /dev/null -b "$JAR" -c "$JAR" --data-urlencode "csrf=$t" -d action=enable "$BASE/ansage.php"

echo "== Codec-Anzeige auf der Startseite"
prov_cli "channel originate Local/anruf@provider application Wait 12" >/dev/null
sleep 5
check "Codec im Kanal vermerkt (Accountcode g722)" 'tx_cli "core show channels concise" | grep "^PJSIP/" | cut -d"!" -f9 | grep -q g722'
check "Startseite zeigt HD (G.722)" 'curl -s -b "$JAR" -c "$JAR" "$BASE/start.php" | grep -q "HD (G.722)"'
check "Aktualisierung zeigt den Anrufer ebenfalls" 'curl -s -b "$JAR" -c "$JAR" "$BASE/start_status.php" | grep -q "HD (G.722)"'
sleep 9

echo "== Neustart des Containers"
MARK="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
t0=$(date +%s)
$DC stop telerix >/dev/null 2>&1
info "docker compose stop dauerte $(( $(date +%s) - t0 )) s"
check "Abmeldung beim Stoppen (REGISTER mit Expires: 0)" 'docker logs --since "$MARK" "$PROV" 2>&1 | grep -qi "^Expires: 0"'
check "Anbieter hat danach keine Registrierung mehr" '[ "$(prov_contacts)" = 0 ]'
$DC up -d --force-recreate >/dev/null 2>&1
wait_healthy
login
check "Nach dem Neustart wieder registriert" 'wait_registered'
check "Anbieter hat genau eine Registrierung" '[ "$(prov_contacts)" = 1 ]'
call anruf3 12
check "Nach Neustart genau ein eingehender Anruf (kein doppeltes INVITE)" '[ "$CALL_CHANNELS" = 1 ]'

echo
if [ "$fails" -eq 0 ]; then echo "Alle SIP-Tests bestanden."; else echo "$fails SIP-Test(s) fehlgeschlagen."; fi
exit $((fails > 0))
