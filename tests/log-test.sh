#!/bin/bash
# Test der Protokollierung: nichts im Container wächst ohne Grenze, SIP-
# Scanner füllen das Log nicht mehr, echte Fehler bleiben sichtbar.
#
# Geprüft werden:
#   - Docker-Log-Rotation (json-file, 10 MB x 5) am laufenden Container
#   - Asterisk schreibt keine messages.log/queue_log mehr, nur die Konsole
#   - pjproject: Paketinhalte nicht mehr als NOTICE
#   - abgewiesene SIP-Anfragen (wie von Scannern) erscheinen nicht einzeln,
#     sondern als stündliche Zusammenfassung (docker/asterisk-log.awk);
#     andere Warnungen gehen unverändert durch
#   - DNS-Meldung beim Start als Hinweis statt ERROR
#   - nginx protokolliert den Healthcheck nicht, normale Zugriffe schon
#   - Reste abgebrochener Hörproben werden beim Start entfernt
#
# Aufruf: tests/log-test.sh   (Container muss laufen; startet ihn einmal
# neu). Dauer etwa 1 min.

set -u
cd "$(dirname "$0")/.." || exit 1
export MSYS_NO_PATHCONV=1
DC="docker compose"
NET="telerix_default"
BASE="http://127.0.0.1:8080"
fails=0
ok()   { echo "  OK   $*"; }
bad()  { echo "  FAIL $*"; fails=$((fails + 1)); }
info() { echo "  INFO $*"; }
check() { if eval "$2"; then ok "$1"; else bad "$1"; fi; }
in_tx() { $DC exec -T telerix sh -c "$1"; }
tx_cli() { $DC exec -T telerix asterisk -rx "$1"; }
cid() { $DC ps -q telerix; }
wait_healthy() {
   for _ in $(seq 1 40); do
      [ "$(docker inspect -f '{{.State.Health.Status}}' "$(cid)" 2>/dev/null)" = healthy ] && return 0
      sleep 2
   done
   return 1
}

echo "== Docker-Log und Neustart"
check "Log-Rotation: json-file, 10 MB, 5 Dateien" '[ "$(docker inspect -f "{{.HostConfig.LogConfig.Type}} {{index .HostConfig.LogConfig.Config \"max-size\"}} {{index .HostConfig.LogConfig.Config \"max-file\"}}" "$(cid)")" = "json-file 10m 5" ]'
# Reste einer abgebrochenen Hörprobe und von telerix-stream anlegen, dann neu starten
in_tx 'touch /var/lib/telerix/state/.hoerprobe-0123456789abcdef.au /var/lib/telerix/state/.pegel-0123456789abcdef.f32 && mkdir -p /tmp/telerix-stream.99999 /tmp/nginx-body/1 && chown telerix /var/lib/telerix/state/.hoerprobe-*'
START="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
$DC restart telerix >/dev/null 2>&1
check "Container nach Neustart healthy" 'wait_healthy'
check "Reste abgebrochener Hörproben beim Start entfernt" '[ -z "$(in_tx "ls -A /var/lib/telerix/state | grep -E \"^\.(hoerprobe|pegel)-\"")" ]'
check "Alte Arbeitsverzeichnisse von telerix-stream entfernt" '! in_tx "test -e /tmp/telerix-stream.99999"'
LOG="$($DC logs --since "$START" telerix 2>/dev/null)"
check "Keine ERROR-Meldung zu DNS-Servern mehr" '! grep -q "no local system nameservers" <<<"$LOG"'
check "Stattdessen einmal ein Hinweis" '[ "$(grep -c "INFO\[telerix\] Hinweis: Asterisk ermittelt die DNS-Server" <<<"$LOG")" = 1 ]'

echo "== Asterisk-Logdateien"
check "Nur der Kanal Konsole (keine messages.log)" 'ch="$(tx_cli "logger show channels")"; grep -q "Console" <<<"$ch" && ! grep -q "messages" <<<"$ch"'
sleep 2
check "Keine Dateien in /var/log/asterisk (messages.log, queue_log)" '[ -z "$(in_tx "find /var/log/asterisk -type f")" ]'
check "pjproject: Stufe 4 (Paketinhalte) nicht mehr als NOTICE" 'm="$(tx_cli "pjproject show log mappings")"; grep -q "asterisk notice:\s*$" <<<"$m" || ! grep -q "asterisk notice:.*4" <<<"$m"'
info "$(tx_cli "pjproject show log mappings" | tr -s " \n" " ")"

echo "== SIP-Scanner"
START="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
TX_IP="$(docker inspect -f "{{(index .NetworkSettings.Networks \"$NET\").IPAddress}}" "$(cid)")"
# 20 REGISTER ohne passenden Endpunkt und 5 ungültige Pakete von einem
# anderen Container aus, wie ein Scanner aus dem Internet
docker run --rm --network "$NET" --entrypoint php84 telerix:local -r '
   $ip = $argv[1];
   $f = stream_socket_client("udp://$ip:5060");
   for ($i = 0; $i < 20; $i++) {
      $c = bin2hex(random_bytes(6));
      fwrite($f, "REGISTER sip:$ip SIP/2.0\r\nVia: SIP/2.0/UDP 10.9.9.9:5060;branch=z9hG4bK$c\r\nFrom: <sip:100@$ip>;tag=$c\r\nTo: <sip:100@$ip>\r\nCall-ID: $c\r\nCSeq: 1 REGISTER\r\nContact: <sip:100@10.9.9.9>\r\nMax-Forwards: 70\r\nContent-Length: 0\r\n\r\n");
      usleep(30000);
   }
   for ($i = 0; $i < 5; $i++) {
      fwrite($f, "INVITE sip:x SIP/2.0\r\nVia: kaputt\r\nFrom: <sip:a@b>\r\nCSeq: x\r\nContent-Length: 0\r\n\r\n");
   }
' "$TX_IP"
sleep 3
LOG="$($DC logs --since "$START" telerix 2>/dev/null)"
check "Abgewiesene Anfragen nicht einzeln im Log" '! grep -q "No matching endpoint found" <<<"$LOG"'
check "Ungültige Pakete nicht einzeln im Log" '! grep -q "Dropping .* bytes packet" <<<"$LOG"'
check "Keine Paketinhalte im Log" '! grep -q "end of packet" <<<"$LOG"'
info "Log-Zeilen in diesen Sekunden: $(grep -c . <<<"$LOG")"

echo "== Zusammenfassung (Filter mit Beispielzeilen)"
OUT="$(in_tx 'awk -f /usr/local/lib/telerix/asterisk-log.awk' <<'EOF'
[2026-11-10 13:31:52] NOTICE[67]: res_pjsip/pjsip_distributor.c:690 log_failed_request: Request 'REGISTER' from '<sip:100@1.2.3.4>' failed for '1.2.3.4:5060' (callid: a) - No matching endpoint found
[2026-11-10 13:31:53] NOTICE[67]: res_pjsip/pjsip_distributor.c:690 log_failed_request: Request 'INVITE' from '<sip:100@1.2.3.4>' failed for '1.2.3.4:5061' (callid: b) - Failed to authenticate
[2026-11-10 13:40:00] WARNING[67]: pjproject: sip_transport.c Dropping 312 bytes packet from UDP 5.6.7.8:5060 : PJSIP syntax error exception when parsing 'Request Line' header on line 1 col 1
[2026-11-10 13:41:00] WARNING[67]: res_pjsip_acl.c:158 check_acls: Incoming SIP message from [2001:db8::5]:5060 did not pass ACL test
[2026-11-10 13:50:00] WARNING[70]: res_pjsip_outbound_registration.c:800 handle_registration_response: Registration to 'anbieter' failed (403)
[2026-11-10 14:00:01] telerix-stream: wieder verbunden
EOF
)"
echo "$OUT" | sed 's/^/      /'
check "Echter Fehler (Registrierung) geht unverändert durch" 'grep -q "Registration to .anbieter. failed (403)" <<<"$OUT"'
check "Stündliche Zusammenfassung mit Anzahl und Absendern" 'grep -q "SIP-Scanner 2026-11-10 13:00-13:59: 4 abgewiesene Anfragen bzw. ungültige Pakete von 3 Absendern" <<<"$OUT" && grep -q "häufigste Absender: 1.2.3.4 (2)" <<<"$OUT"'
check "Zusammenfassung vor der ersten Zeile der nächsten Stunde" '[ "$(tail -n 1 <<<"$OUT")" = "[2026-11-10 14:00:01] telerix-stream: wieder verbunden" ]'

echo "== nginx"
START="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
curl -s -o /dev/null -A "log-test" "$BASE/index.php?log-test"
# Der Healthcheck läuft alle 30 s
sleep 35
LOG="$($DC logs --since "$START" telerix 2>/dev/null)"
check "Normaler Zugriff im Log" 'grep -q "GET /index.php?log-test" <<<"$LOG"'
check "Healthcheck (Wget von 127.0.0.1) nicht im Log" '! grep -q "\"Wget\"" <<<"$LOG"'
check "Healthcheck lief trotzdem (healthy)" '[ "$(docker inspect -f "{{.State.Health.Status}}" "$(cid)")" = healthy ]'

echo
if [ "$fails" -eq 0 ]; then echo "Alle Tests bestanden."; else echo "$fails Test(s) fehlgeschlagen."; fi
exit $((fails > 0))
