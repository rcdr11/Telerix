#!/bin/bash
# Browser-Test der Oberfläche mit Playwright (Chromium ohne Bildschirm):
# Schieberegler der Lautstärke, Hauptschalter, Eingangspegel, Hörprobe mit
# Ladebalken ohne Neuladen und mit Pegeln,
# regelmäßige Aktualisierung der Startseite, keine JavaScript-Fehler.
#
# Startet im Compose-Netz einen Test-Stream (Sinuston) und einen
# Playwright-Container (beim ersten Mal ein großer Download).
#
# Aufruf: tests/ui-test.sh   (Container muss laufen; setzt die
# Stream-Adresse auf den Test-Stream und legt eine Hörprobe an)

set -u
cd "$(dirname "$0")/.." || exit 1
export MSYS_NO_PATHCONV=1
NET="telerix_default"
STREAM="telerix-test-ui-stream"
PLAYWRIGHT_IMAGE="mcr.microsoft.com/playwright/python:v1.49.1-noble"
PASS="$(tr -d '\r\n' < secrets/admin_password.txt)"
winpath() { if command -v cygpath >/dev/null; then cygpath -m "$(realpath "$1")"; else realpath "$1"; fi; }
TMP="$(mktemp -d)"
command -v cygpath >/dev/null && TMP="$(cygpath -m "$TMP")"
cleanup() { docker rm -f "$STREAM" >/dev/null 2>&1; rm -rf "$TMP"; }
trap cleanup EXIT

cp tests/sip/stream/router.php "$TMP/"
docker run --rm -v "$TMP:/out" --entrypoint sh telerix:local -c \
   'sox -n -r 44100 -c 2 /tmp/t.wav synth 60 sine 440 vol 0.1 && sox /tmp/t.wav -C 128 /out/test.mp3' 2>/dev/null
docker rm -f "$STREAM" >/dev/null 2>&1
docker run -d --name "$STREAM" --network "$NET" -e PHP_CLI_SERVER_WORKERS=4 -v "$TMP:/srv:ro" \
   --entrypoint php84 telerix:local -S 0.0.0.0:8099 /srv/router.php >/dev/null
docker compose exec -T telerix rm -f /var/lib/telerix/state/login_attempts.json

docker run --rm --network "$NET" -v "$(winpath tests/ui):/t:ro" -e PASS="$PASS" \
   -e STREAM="http://$STREAM:8099/steady" "$PLAYWRIGHT_IMAGE" \
   sh -c 'pip install -q playwright==1.49.1 2>/dev/null; python /t/ui_test.py'
