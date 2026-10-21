#!/usr/bin/env bash
# Telerix auf das neueste Release aktualisieren.
#
#   ./update.sh        mit Rückfrage
#   ./update.sh -y     ohne Rückfrage
#
# Ablauf: Sicherung des Datenvolumes nach backups/, neueste Version
# (höchstes Tag v*) aus dem Git-Remote holen, Image neu bauen, Container
# neu starten, auf "healthy" warten. Schlägt der Build fehl, wird der Code
# auf den vorherigen Stand zurückgesetzt; der laufende Container bleibt
# dann unverändert.
#
# Läuft auf dem Rechner, nicht im Container (unter Windows in Git Bash).
# Das Webinterface hat bewusst keinen Zugriff auf Docker.

set -euo pipefail
cd "$(dirname "$0")"
# Git Bash: keine Pfadumwandlung für docker-Argumente
export MSYS_NO_PATHCONV=1

REMOTE="${TELERIX_UPDATE_REMOTE:-origin}"
PROJECT="telerix"
BACKUP_IMAGE="alpine:3.24.2@sha256:294b683cb724975bec92580e1e685676bd4b50bda910ddb8c51d4cabeaec77e6"
YES=0
[ "${1:-}" = "-y" ] && YES=1

die() { echo "Fehler: $*" >&2; exit 1; }

command -v git >/dev/null || die "git ist nicht installiert."
command -v docker >/dev/null || die "docker ist nicht installiert oder nicht im PATH."

# 1. Nur mit sauberem Arbeitsverzeichnis, sonst gingen lokale Änderungen verloren
if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
   die "Es gibt nicht eingecheckte Änderungen. Bitte erst committen oder verwerfen."
fi

echo "Hole Versionen von '$REMOTE' ($(git remote get-url "$REMOTE"))..."
git fetch --tags --quiet "$REMOTE"
latest="$(git tag --list 'v*' --sort=-v:refname | head -n 1)"
[ -n "$latest" ] || die "Keine Release-Tags (v...) gefunden."
current="$(git describe --tags --always)"

if git merge-base --is-ancestor "$latest" HEAD; then
   echo "Telerix ist aktuell ($current, neuestes Release: $latest)."
   exit 0
fi

echo
echo "Installiert: $current"
echo "Neu:         $latest"
echo
echo "Änderungen:"
git log --oneline --no-decorate HEAD.."$latest"
echo
if [ "$YES" -ne 1 ]; then
   read -r -p "Jetzt aktualisieren? Laufende Anrufe werden getrennt. [j/N] " answer
   case "$answer" in j|J|y|Y) ;; *) echo "Abgebrochen."; exit 0 ;; esac
fi

# 2. Sicherung des Datenvolumes (unverschlüsselt, nur lokal; enthält Zugangsdaten)
volume="$(docker volume ls -q --filter "label=com.docker.compose.project=$PROJECT" \
          --filter "label=com.docker.compose.volume=telerix-data")"
if [ -n "$volume" ]; then
   umask 077
   mkdir -p backups
   backup_dir="$(pwd -W 2>/dev/null || pwd)/backups"
   backup_file="telerix-data-$(date +%Y%m%d-%H%M%S)-${current//\//_}.tgz"
   echo "Sichere Datenvolume nach backups/$backup_file ..."
   docker run --rm -v "$volume:/data:ro" -v "$backup_dir:/backup" "$BACKUP_IMAGE" \
      tar czf "/backup/$backup_file" -C /data . || die "Sicherung fehlgeschlagen, Update abgebrochen."
else
   echo "Kein Datenvolume gefunden (Erstinstallation?), keine Sicherung nötig."
fi

# 3. Neue Version holen (nur Fast-Forward, keine Merges)
previous="$(git rev-parse HEAD)"
git merge --ff-only --quiet "$latest" || die "Die neue Version lässt sich nicht per Fast-Forward übernehmen (eigene Commits?)."

# 4. Bauen – bei Fehler Code zurücksetzen, laufender Container bleibt unverändert
echo "Baue Image..."
if ! docker compose build; then
   git reset --quiet --hard "$previous"
   die "Build fehlgeschlagen. Code auf den vorherigen Stand zurückgesetzt, der Container läuft unverändert weiter."
fi

# 5. Starten und auf Healthcheck warten
docker compose up -d
echo -n "Warte auf Start"
status=""
for _ in $(seq 1 40); do
   cid="$(docker compose ps -q telerix)"
   status="$(docker inspect -f '{{.State.Health.Status}}' "$cid" 2>/dev/null || true)"
   [ "$status" = "healthy" ] && break
   echo -n "."
   sleep 3
done
echo

if [ "$status" != "healthy" ]; then
   echo "Der Container ist nach dem Update nicht 'healthy' (Status: ${status:-unbekannt})." >&2
   echo "Protokoll ansehen:       docker compose logs --tail 100" >&2
   echo "Zurück zur alten Version: git reset --hard $previous && docker compose up -d --build" >&2
   [ -n "${backup_file:-}" ] && echo "Datensicherung:          backups/$backup_file" >&2
   exit 1
fi

echo "Telerix wurde auf $latest aktualisiert."
