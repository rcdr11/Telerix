#!/bin/bash
# Test: Betrieb hinter einem Reverse-Proxy (TELERIX_TRUSTED_PROXIES).
#
# Startet einen Test-Proxy (nginx aus dem Telerix-Image) im Compose-Netz und
# mehrere kurzlebige curl-Clients mit jeweils eigener IP. Geprüft wird:
#   - ohne Variable: X-Forwarded-For wird ignoriert (auch gefälscht),
#     alle Clients hinter dem Proxy teilen sich eine Sperre (altes Verhalten)
#   - mit Variable: die echte Client-IP kommt an, die Drosselung greift pro
#     Client, gefälschte X-Forwarded-For-Header der Clients bleiben wirkungslos
#   - ungültige Werte brechen den Start mit klarer Meldung ab
#
# Aufruf: tests/proxy-test.sh   (Container muss laufen; startet ihn mehrmals neu)

set -u
cd "$(dirname "$0")/.." || exit 1
export MSYS_NO_PATHCONV=1
DC="docker compose"
NET="telerix_default"
PROXY="telerix-test-proxy"
CLIENT_IMAGE="telerix-test-client"
PASS="$(tr -d '\r\n' < secrets/admin_password.txt)"
winpath() { if command -v cygpath >/dev/null; then cygpath -m "$(realpath "$1")"; else realpath "$1"; fi; }
fails=0
ok()   { echo "  OK   $*"; }
bad()  { echo "  FAIL $*"; fails=$((fails + 1)); }
check() { if eval "$2"; then ok "$1"; else bad "$1"; fi; }

reset_throttle() { $DC exec -T telerix rm -f /var/lib/telerix/state/login_attempts.json; }
wait_healthy() {
   for _ in $(seq 1 40); do
      [ "$(docker inspect -f '{{.State.Health.Status}}' "$($DC ps -q telerix)" 2>/dev/null)" = healthy ] && return 0
      sleep 2
   done
   return 1
}
# Clients laufen dauerhaft (je eigene IP); kurzlebige Container bekämen
# nacheinander dieselbe IP und wären für Telerix nicht unterscheidbar.
CLIENTS="telerix-test-client-a telerix-test-client-b telerix-test-client-c telerix-test-client-d telerix-test-client-e"
start_clients() {
   for c in $CLIENTS; do
      docker rm -f "$c" >/dev/null 2>&1
      docker run -d --name "$c" --network "$NET" -v "$(winpath tests/proxy/client.sh):/client.sh:ro" \
         "$CLIENT_IMAGE" sleep 3600 >/dev/null
   done
}
# client <a-e> <Ziel-URL> <Fehlversuche> <gefälschtes XFF 0|1>  ->  "IP=... RESULT=..."
client() {
   docker exec "telerix-test-client-$1" sh /client.sh "$2" admin "$PASS" "$3" "$4"
}
# Hostnamen ohne Punkt (telerix) behandelt curl wie eine TLD und verwirft
# Cookies dafür; deshalb werden Proxy und Telerix per IP angesprochen.
telerix_url() { echo "http://$(docker inspect -f "{{(index .NetworkSettings.Networks \"$NET\").IPAddress}}" "$($DC ps -q telerix)"):8080"; }
ip_of() { sed -n 's/^IP=\([^ ]*\).*/\1/p' <<<"$1"; }
result_of() { sed -n 's/.*RESULT=//p' <<<"$1"; }
# Zugriffe im nginx-Log von Telerix seit Testbeginn
telerix_log() { $DC logs --no-log-prefix --since "$START" telerix 2>/dev/null; }

cleanup() {
   docker rm -f "$PROXY" $CLIENTS >/dev/null 2>&1
   $DC up -d >/dev/null 2>&1
   wait_healthy
   reset_throttle
}
trap cleanup EXIT

echo "== Vorbereitung"
docker build -q -t "$CLIENT_IMAGE" - >/dev/null <<'EOF'
FROM alpine:3.24.2@sha256:294b683cb724975bec92580e1e685676bd4b50bda910ddb8c51d4cabeaec77e6
RUN apk add --no-cache curl
EOF
docker rm -f "$PROXY" >/dev/null 2>&1
docker run -d --name "$PROXY" --network "$NET" --entrypoint nginx \
   -v "$(winpath tests/proxy/proxy-nginx.conf):/proxy.conf:ro" telerix:local \
   -c /proxy.conf -g 'daemon off;' >/dev/null
PROXY_IP="$(docker inspect -f "{{(index .NetworkSettings.Networks \"$NET\").IPAddress}}" "$PROXY")"
start_clients
check "Test-Proxy läuft ($PROXY_IP)" '[ -n "$PROXY_IP" ]'
$DC up -d >/dev/null 2>&1; wait_healthy

echo "== Ohne TELERIX_TRUSTED_PROXIES"
reset_throttle
START="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
a="$(client a "http://$PROXY_IP" 5 0)"
b="$(client b "http://$PROXY_IP" 0 0)"
check "Client A über Proxy nach 5 Fehlversuchen gesperrt" '[ "$(result_of "$a")" = LOCKED ]'
check "Client B über Proxy mitgesperrt (alle teilen die Proxy-IP)" '[ "$(result_of "$b")" = LOCKED ]'
check "Telerix sieht nur die Proxy-IP" 'telerix_log | grep -q "^$PROXY_IP " && ! telerix_log | grep -q "^$(ip_of "$a") "'
reset_throttle
c="$(client c "$(telerix_url)" 5 1)"
check "Direkter Client mit gefälschtem X-Forwarded-For trotzdem gesperrt" '[ "$(result_of "$c")" = LOCKED ]'
check "Gefälschte Adressen tauchen nicht als Client-IP auf" '! telerix_log | grep -q "^198\.51\.100\."'
check "Direkter Client mit echter IP protokolliert" 'telerix_log | grep -q "^$(ip_of "$c") "'

echo "== Mit TELERIX_TRUSTED_PROXIES=$PROXY_IP"
TEST_TRUSTED_PROXIES="$PROXY_IP" $DC -f docker-compose.yml -f tests/proxy/compose.trusted.yml up -d >/dev/null 2>&1
check "Container startet" 'wait_healthy'
check "realip-Konfiguration erzeugt" '$DC exec -T telerix cat /etc/nginx/telerix-realip.conf | grep -q "set_real_ip_from $PROXY_IP;"'
reset_throttle
START="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
a="$(client a "http://$PROXY_IP" 5 0)"
b="$(client b "http://$PROXY_IP" 0 0)"
check "Client A über Proxy nach 5 Fehlversuchen gesperrt" '[ "$(result_of "$a")" = LOCKED ]'
check "Client B über Proxy NICHT gesperrt (Drosselung pro Client)" '[ "$(result_of "$b")" = OK ]'
check "Telerix sieht die echten Client-IPs" 'telerix_log | grep -q "^$(ip_of "$a") " && telerix_log | grep -q "^$(ip_of "$b") "'
reset_throttle
d="$(client d "http://$PROXY_IP" 5 1)"
check "Client mit gefälschtem X-Forwarded-For über Proxy trotzdem gesperrt" '[ "$(result_of "$d")" = LOCKED ]'
e="$(client e "$(telerix_url)" 5 1)"
check "Direkter Client (nicht vertrauenswürdig) mit gefälschtem Header gesperrt" '[ "$(result_of "$e")" = LOCKED ]'
check "Gefälschte Adressen tauchen nicht als Client-IP auf" '! telerix_log | grep -q "^198\.51\.100\."'

echo "== Ungültige Werte"
for bad_value in "10.0.0.0/33" "172.16.0.0/12,proxy" "0.0.0.0/0" "1.2.3"; do
   START="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
   TEST_TRUSTED_PROXIES="$bad_value" $DC -f docker-compose.yml -f tests/proxy/compose.trusted.yml up -d >/dev/null 2>&1
   sleep 4
   check "'$bad_value' bricht den Start mit Meldung ab" 'telerix_log | grep -q "telerix: TELERIX_TRUSTED_PROXIES:" && [ "$(docker inspect -f "{{.State.Health.Status}}" "$($DC ps -aq telerix)")" != healthy ]'
done

echo
if [ "$fails" -eq 0 ]; then echo "Alle Proxy-Tests bestanden."; else echo "$fails Proxy-Test(s) fehlgeschlagen."; fi
exit $((fails > 0))
