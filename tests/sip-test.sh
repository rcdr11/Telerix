#!/bin/bash
# Test mit nachgebildetem SIP-Anbieter und Test-Livestream.
#
# Startet im Compose-Netz:
#   - einen zweiten Asterisk als "Anbieter" (tests/sip/provider), bei dem sich
#     Telerix registriert und der Telerix anruft und den Anruf aufnimmt,
#   - einen Test-Livestream (tests/sip/stream/router.php) mit leiser,
#     verhallter Sprache, der alle 10 s kurz stockt.
# Geprüft werden Begrüßungsansage, Wartemusik, Abmeldung beim Stoppen,
# doppelte Anrufe nach einem Neustart sowie Pegel und Aussetzer.
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

# Anrufe, die Telerix seit dem Start bekommen hat ("calls processed")
tx_calls_processed() { tx_cli "core show channels count" | awk '/calls? processed/ {print $1}'; }
# Anruf vom Anbieter an Telerix, Aufnahme beim Anbieter; prüft nach 3 s,
# welche Ansage läuft, und zählt, wie viele Anrufe bei Telerix ankommen.
# Hat der Anbieter eine veraltete Zweitregistrierung, schickt er zwei INVITEs.
# call <Name> <Sekunden>  -> setzt CALL_APP, CALL_CHANNELS, CALL_STATS
call() {
   local before; before="$(tx_calls_processed)"
   prov_cli "channel originate Local/anruf@provider application Record /tmp/$1.wav16,0,$2,qx" >/dev/null
   sleep 3
   CALL_APP="$(tx_cli "core show channels concise" | grep "^PJSIP/" | head -1 | cut -d'!' -f6,7)"
   sleep 5
   CALL_CHANNELS=$(( $(tx_calls_processed) - before ))
   sleep $(($2 - 6))
   docker exec "$PROV" sh -c "sox -t wav /tmp/$1.wav16 -t raw /tmp/$1.raw" 2>/dev/null
   CALL_STATS="$(docker exec "$PROV" php84 /analyze.php "/tmp/$1.raw" 16000 "$ANSAGE_SKIP")"
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

echo "== Skriptausgabe (telerix-stream direkt, 35 s)"
RATE=8000
in_tx "grep -q '^format=slin16' /etc/asterisk/musiconhold.conf" && RATE=16000
$DC cp tests/sip/analyze.php telerix:/tmp/analyze.php >/dev/null 2>&1
# Testkopie markieren und danach samt Kindprozessen beenden (die Wartemusik
# von Asterisk läuft parallel weiter und bleibt unberührt)
in_tx "su -s /bin/sh asterisk -c 'TELERIX_SKRIPTTEST=1 timeout 35 /usr/local/bin/telerix-stream > /tmp/skript.raw' 2>/dev/null &
   sleep 37
   for p in /proc/[0-9]*; do grep -qz TELERIX_SKRIPTTEST=1 \$p/environ 2>/dev/null && kill -9 \${p#/proc/} 2>/dev/null; done; true"
info "stream.url: $(in_tx "cat /var/lib/telerix/asterisk/stream.url"), Ausgabe: $(in_tx "wc -c < /tmp/skript.raw") Bytes"
SKRIPT="$(in_tx "php84 /tmp/analyze.php /tmp/skript.raw $RATE 5")"
info "Abtastrate $RATE Hz: $SKRIPT"

echo "== Anruf (Ansage und Wartemusik)"
START="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
call anruf1 45
info "nach 3 s: $CALL_APP"
info "Aufnahme ab Sekunde $ANSAGE_SKIP: $CALL_STATS"
check "Begrüßungsansage wird gespielt" 'grep -q "Playback!tx/willkommen-gottesdienst" <<<"$CALL_APP"'
check "Keine Meldung 'does not exist'" '! $DC logs --since "$START" telerix 2>/dev/null | grep -q "does not exist"'
check "Genau ein eingehender Anruf bei Telerix" '[ "$CALL_CHANNELS" = 1 ]'
check "Wartemusik kommt an (Pegel über -50 dBFS)" 'awk -v s="$CALL_STATS" "BEGIN {split(s,a,\"rms=\"); exit !(a[2]+0 > -50)}"'

echo "== Eigene Ansage wird bevorzugt"
docker exec "$PROV" sh -c "sox -n -r 16000 -c 1 /tmp/eigene.wav synth 3 sine 600 vol 0.3"
docker cp "$PROV:/tmp/eigene.wav" "$(winpath "$TMP")/eigene.wav" >/dev/null
t="$(token ansage.php)"
curl -s -o /dev/null -b "$JAR" -c "$JAR" -F "csrf=$t" -F "ansage=@$(winpath "$TMP/eigene.wav")" "$BASE/ansage.php"
call anruf2 10
info "nach 3 s: $CALL_APP"
check "Hochgeladene Ansage wird gespielt" 'grep -q "Playback!/var/lib/telerix/sounds/ansage" <<<"$CALL_APP"'
t="$(token ansage.php)"
curl -s -o /dev/null -b "$JAR" -c "$JAR" --data-urlencode "csrf=$t" -d action=delete "$BASE/ansage.php"

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
