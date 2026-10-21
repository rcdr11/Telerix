#!/usr/bin/env bash
# Telerix einrichten und starten.
#
#   ./install.sh
#
# Prüft Docker, legt beim ersten Mal ein zufälliges Admin-Passwort an
# (secrets/admin_password.txt), richtet die Update-Prüfung ein, baut das
# Image und startet Telerix. Kann gefahrlos erneut ausgeführt werden;
# vorhandene Daten und Passwörter bleiben erhalten.
#
# Unter Windows in Git Bash ausführen.

set -euo pipefail
cd "$(dirname "$0")"
export MSYS_NO_PATHCONV=1

PROJECT="telerix"
PW_FILE="secrets/admin_password.txt"

die() { echo "Fehler: $*" >&2; exit 1; }

# 1. Docker vorhanden und gestartet?
command -v docker >/dev/null \
   || die "Docker ist nicht installiert. Anleitung: https://docs.docker.com/get-docker/ (Windows/macOS: Docker Desktop)."
docker compose version >/dev/null 2>&1 \
   || die "Docker Compose fehlt. Es gehört zu Docker Desktop bzw. zum Paket docker-compose-plugin."
docker info >/dev/null 2>&1 \
   || die "Docker läuft nicht. Bitte Docker (Desktop) starten und erneut versuchen."

# 2. Admin-Passwort beim ersten Mal erzeugen
# Startet Docker einmal ohne die Datei, legt es an ihrer Stelle ein leeres
# Verzeichnis an.
if [ -d "$PW_FILE" ]; then
   die "$PW_FILE ist ein Verzeichnis (von Docker angelegt, als die Datei fehlte). Verzeichnis löschen und erneut ausführen; liegt darin noch die alte Passwortdatei, diese vorher herausnehmen."
fi
new_password=""
if [ ! -s "$PW_FILE" ]; then
   umask 077
   mkdir -p secrets
   new_password="$(LC_ALL=C tr -dc 'A-Za-z0-9' </dev/urandom | head -c 20)"
   printf '%s\n' "$new_password" > "$PW_FILE"
fi

volume_exists=""
if [ -n "$(docker volume ls -q --filter "label=com.docker.compose.project=$PROJECT" \
                                --filter "label=com.docker.compose.volume=telerix-data")" ]; then
   volume_exists=1
fi

# 3. Update-Prüfung auf das GitHub-Repository dieses Klons einstellen
if [ ! -f .env ]; then
   repo="$(git remote get-url origin 2>/dev/null \
           | sed -E 's#\.git$##' \
           | sed -nE 's#^(https://github\.com/|git@github\.com:)([^/]+/[^/]+)$#\2#p' || true)"
   if [ -n "$repo" ]; then
      echo "TELERIX_UPDATE_REPO=$repo" > .env
   fi
fi

# 4. Bauen und starten
echo "Baue und starte Telerix (beim ersten Mal dauert das einige Minuten)..."
docker compose up -d --build

echo -n "Warte auf Start"
status=""
for _ in $(seq 1 40); do
   status="$(docker inspect -f '{{.State.Health.Status}}' "$(docker compose ps -q telerix)" 2>/dev/null || true)"
   [ "$status" = "healthy" ] && break
   echo -n "."
   sleep 3
done
echo
[ "$status" = "healthy" ] || die "Telerix ist nicht richtig gestartet. Protokoll ansehen: docker compose logs --tail 100"

# 5. Zugangsdaten anzeigen
echo
echo "Telerix läuft:  http://127.0.0.1:8080"
echo "Benutzer:       admin"
if [ -n "$new_password" ] && [ -z "$volume_exists" ]; then
   echo "Passwort:       $new_password"
   echo
   echo "Bitte nach dem ersten Login unter Konfiguration -> Zugangsdaten ändern ein eigenes Passwort setzen."
elif [ -n "$new_password" ]; then
   echo "Passwort:       das Passwort der bereits vorhandenen Installation"
   echo "                (das neu erzeugte Passwort in $PW_FILE gilt erst nach einem Zurücksetzen,"
   echo "                 siehe docs/konfiguration.md, 'Passwort vergessen')"
else
   echo "Passwort:       wie bisher (beim ersten Start stand es in $PW_FILE)"
fi
echo
echo "Wichtig: $PW_FILE nicht löschen, sonst startet der Container nicht mehr."
