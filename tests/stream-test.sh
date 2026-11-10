#!/bin/bash
# Test des Wächters für die Wartemusik (asterisk/stream.sh).
#
# Startet im Compose-Netz einen Test-Livestream (tests/sip/stream/router.php)
# und prüft mit dem laufenden Wartemusik-Prozess von Asterisk:
#   - /haengt: Der Server sendet 6 s lang und dann nichts mehr, hält die
#     Verbindung aber offen. telerix-stream muss das nach
#     STREAM_TIMEOUT_SEKUNDEN (15 s) bemerken, die Kette samt mpg123
#     beenden und neu verbinden; es dürfen keine hängenden Prozesse
#     übrig bleiben.
#   - Abbrüche und Neuverbindungen stehen mit Grund im Protokoll.
#   - Zustand für das Webinterface (/run/telerix-stream/status) und
#     Anzeige auf der Startseite, Warnung bei fehlenden Daten.
#   - /stille: digitale Stille wird nach 120 s gemeldet (Protokoll und
#     Startseite), ohne neu zu verbinden.
#
# Aufruf: tests/stream-test.sh   (Container muss laufen; ändert die
# Stream-Adresse und setzt sie am Ende zurück auf leer). Dauer etwa 5 min.

set -u
cd "$(dirname "$0")/.." || exit 1
export MSYS_NO_PATHCONV=1
DC="docker compose"
NET="telerix_default"
STREAM="telerix-test-stream"
BASE="http://127.0.0.1:8080"
PASS="$(tr -d '\r\n' < secrets/admin_password.txt)"
winpath() { if command -v cygpath >/dev/null; then cygpath -m "$(realpath "$1")"; else realpath "$1"; fi; }
TMP="$(mktemp -d)"
command -v cygpath >/dev/null && TMP="$(cygpath -m "$TMP")"
JAR="$TMP/cookies"
fails=0
ok()   { echo "  OK   $*"; }
bad()  { echo "  FAIL $*"; fails=$((fails + 1)); }
info() { echo "  INFO $*"; }
check() { if eval "$2"; then ok "$1"; else bad "$1"; fi; }

in_tx() { $DC exec -T telerix sh -c "$1"; }
ip_of() { docker inspect -f "{{(index .NetworkSettings.Networks \"$NET\").IPAddress}}" "$1"; }
token() { curl -s -b "$JAR" -c "$JAR" "$BASE/$1" | grep -o 'name="csrf" value="[0-9a-f]*"' | head -1 | sed 's/.*value="//;s/"//'; }
login() {
   rm -f "$JAR"
   local t; t="$(token index.php)"
   curl -s -o /dev/null -b "$JAR" -c "$JAR" --data-urlencode "csrf=$t" -d tuser=admin --data-urlencode "tpass=$PASS" "$BASE/login.php"
}
set_stream() { local t; t="$(token configuration.php)"; curl -s -o /dev/null -b "$JAR" -c "$JAR" --data-urlencode "csrf=$t" --data-urlencode "inetstream=$1" "$BASE/set_stream.php"; }
startseite() { curl -s -b "$JAR" -c "$JAR" "$BASE/start.php"; }
# Meldungen von telerix-stream seit $1
stream_log() { $DC logs --since "$1" telerix 2>/dev/null | grep "telerix-stream:"; }
# Wartet bis zu $2 Sekunden, bis eine Meldung mit dem Muster $1 seit $3 erscheint
warte_log() {
   local i
   for i in $(seq 1 "$2"); do
      stream_log "$3" | grep -q "$1" && return 0
      sleep 1
   done
   return 1
}
status_wert() { in_tx "sed -n 's/^$1=//p' /run/telerix-stream/status"; }
# Laufende mpg123-Prozesse des Wartemusik-Prozesses (nicht die der Hörprobe)
mpg123_anzahl() { in_tx "ps -o user,args | grep '^ *asterisk .*mpg123' | grep -c -v grep"; }

cleanup() {
   login
   set_stream ""
   docker rm -f "$STREAM" >/dev/null 2>&1
   rm -rf "$TMP"
}
trap cleanup EXIT

in_tx 'rm -f /var/lib/telerix/state/login_attempts.json'
login

echo "== Vorbereitung"
cp tests/sip/stream/router.php "$TMP/"
# Testsignal: Ton mit Pausen (nicht still), und digitale Stille, MP3 128 kbit/s
docker run --rm -v "$(winpath "$TMP"):/out" --entrypoint sh telerix:local -c '
   sox -n -r 44100 -c 2 /tmp/t.wav synth 30 sine 440 vol 0.3 tremolo 0.5 80
   sox /tmp/t.wav -C 128 /out/test.mp3
   sox -n -r 44100 -c 2 /tmp/s.wav trim 0 30
   sox /tmp/s.wav -C 128 /out/stille.mp3
'
docker rm -f "$STREAM" >/dev/null 2>&1
docker run -d --name "$STREAM" --network "$NET" -e PHP_CLI_SERVER_WORKERS=8 -v "$(winpath "$TMP"):/srv:ro" --entrypoint php84 \
   telerix:local -S 0.0.0.0:8099 /srv/router.php >/dev/null
STREAM_IP="$(ip_of "$STREAM")"
bereit() { in_tx "wget -q -O /dev/null -T 2 http://$STREAM_IP:8099/listen/gemeinde/status-json.xsl" 2>/dev/null; }
for _ in $(seq 1 20); do bereit && break; sleep 1; done
check "Teststream läuft ($STREAM_IP)" '[ -n "$STREAM_IP" ] && bereit'
check "Statusverzeichnis angelegt (750 asterisk:telerix)" '[ "$(in_tx "stat -c %a:%U:%G /run/telerix-stream")" = "750:asterisk:telerix" ]'

echo "== Normaler Stream"
START="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
set_stream "http://$STREAM_IP:8099/steady"
# Asterisk startet die Wartemusik nach einer Änderung neu (bis etwa 20 s)
check "Verbunden, Meldung im Protokoll" 'warte_log "verbunden mit http://$STREAM_IP:8099/steady" 45 "$START"'
sleep 4
check "Zustand: verbunden, Daten aktuell" '[ "$(status_wert zustand)" = verbunden ] && [ $(( $(date +%s) - $(status_wert letzte_daten) )) -le 6 ]'
check "Startseite: Stream läuft, letzte Daten" 'startseite | grep -q "Stream läuft, letzte Daten vor"'
check "Genau ein mpg123" '[ "$(mpg123_anzahl)" = 1 ]'

echo "== Server sendet nicht mehr, hält die Verbindung aber offen (/haengt)"
START="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
T0=$(date +%s)
set_stream "http://$STREAM_IP:8099/haengt"
check "Verbunden" 'warte_log "verbunden mit http://$STREAM_IP:8099/haengt" 45 "$START"'
T1=$(date +%s)
# Daten bis 6 s, im Puffer noch einige Sekunden, dann 15 s Frist
check "Stillstand erkannt: \"keine Daten seit 15 s\" (innerhalb von 40 s)" 'warte_log "haengt: keine Daten seit 1[5-9] s, neuer Versuch" 40 "$START"'
T2=$(date +%s)
info "Verbunden nach $((T1 - T0)) s, Stillstand erkannt nach weiteren $((T2 - T1)) s"
check "Zustand nach dem Abbruch mit Grund" 'in_tx "cat /run/telerix-stream/status" | grep -q "^grund=keine Daten seit"'
check "Wieder verbunden (innerhalb von 15 s)" 'warte_log "wieder verbunden mit http://$STREAM_IP:8099/haengt" 15 "$START"'
check "Kein hängender mpg123 übrig: genau einer läuft" '[ "$(mpg123_anzahl)" = 1 ]'
# Zweiter Durchlauf: wieder erkannt, weiter keine Reste
check "Zweiter Stillstand erkannt" 'for _ in $(seq 1 45); do [ "$(stream_log "$START" | grep -c "haengt: keine Daten seit")" -ge 2 ] && break; sleep 1; done; [ "$(stream_log "$START" | grep -c "haengt: keine Daten seit")" -ge 2 ]'
sleep 3
check "Auch danach keine Reste (mpg123, sox, mbuffer je höchstens einmal)" '[ "$(mpg123_anzahl)" -le 1 ] && [ "$(in_tx "ps -o user,args | grep \"^ *asterisk .*mbuffer\" | grep -c -v grep")" -le 1 ]'
check "Arbeitsverzeichnisse aufgeräumt (höchstens eins)" '[ "$(in_tx "ls -d /tmp/telerix-stream.* 2>/dev/null | wc -l")" -le 1 ]'
info "Protokoll:"
stream_log "$START" | sed 's/^.*telerix-stream:/      telerix-stream:/'

echo "== Kein Stream erreichbar: Warnung auf der Startseite"
START="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
set_stream "http://$STREAM_IP:8099/gibtsnicht"
check "Abbruch mit Grund im Protokoll (HTTP-Fehler)" 'warte_log "gibtsnicht: keine Verbindung (" 45 "$START"'
sleep 32
s="$(startseite)"
check "Startseite warnt: keine Daten vom Stream" 'grep -q "stream-warnung" <<<"$s" && grep -q "kommen keine Daten vom Stream an" <<<"$s"'
check "Startseite nennt den letzten Abbruch" 'grep -q "Letzter Abbruch: keine Verbindung" <<<"$s"'

echo "== Digitale Stille (/stille)"
START="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
set_stream "http://$STREAM_IP:8099/stille"
check "Verbunden" 'warte_log "verbunden mit http://$STREAM_IP:8099/stille" 45 "$START"'
check "Stille nach etwa 120 s gemeldet" 'warte_log "Der Stream liefert seit 1[2-3][0-9] s nur Stille" 150 "$START"'
check "Startseite warnt: nur Stille" 'startseite | grep -q "nur Stille. Läuft die Übertragung?"'
check "Wegen Stille nicht neu verbunden" '! stream_log "$START" | grep -q "stille: keine Daten\|stille: Verbindung beendet"'
START2="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
set_stream "http://$STREAM_IP:8099/steady"
check "Nach Wechsel wieder Ton ohne Warnung" 'warte_log "verbunden mit http://$STREAM_IP:8099/steady" 45 "$START2" && sleep 8 && ! startseite | grep -q "stream-warnung"'

echo
if [ "$fails" -eq 0 ]; then echo "Alle Tests bestanden."; else echo "$fails Test(s) fehlgeschlagen."; fi
exit $((fails > 0))
