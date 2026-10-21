#!/bin/bash
# Rauchtest für das laufende Telerix-Webinterface.
#
# Aufruf (Container muss laufen, Passwort aus secrets/admin_password.txt):
#   tests/smoke-test.sh [http://127.0.0.1:8080] [admin]
#
# Benötigt: bash, curl, docker (compose) – prüft Login, CSRF, Uploads,
# Stream- und SIP-Konfiguration sowie die Asterisk-Anbindung.
# ACHTUNG: Setzt Stream-Adresse und SIP-Daten auf Testwerte.

set -u
BASE="${1:-http://127.0.0.1:8080}"
USER="${2:-admin}"
cd "$(dirname "$0")/.." || exit 1
PASS="$(tr -d '\r\n' < secrets/admin_password.txt)"
DC="docker compose"
# Unter Git-Bash (Windows) keine Pfadumwandlung für docker-Argumente
export MSYS_NO_PATHCONV=1

TMP="$(mktemp -d)"
# Natives curl unter Windows braucht Windows-Pfade
command -v cygpath >/dev/null && TMP="$(cygpath -m "$TMP")"
trap 'rm -rf "$TMP"' EXIT
JAR="$TMP/cookies"
fails=0

ok()   { echo "  OK   $*"; }
bad()  { echo "  FAIL $*"; fails=$((fails + 1)); }
check() { if eval "$2"; then ok "$1"; else bad "$1"; fi; }

# HTTP-Status einer Anfrage
status() { curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" "$@"; }
# Seite laden und CSRF-Token daraus lesen
token() { curl -s -b "$JAR" -c "$JAR" "$BASE/$1" | grep -o 'name="csrf" value="[0-9a-f]*"' | head -1 | sed 's/.*value="//;s/"//'; }
page() { curl -s -b "$JAR" -c "$JAR" "$BASE/$1"; }
in_container() { $DC exec -T telerix sh -c "$1"; }

# Login-Drosselung zurücksetzen, damit der Test wiederholbar ist
in_container 'rm -f /var/lib/telerix/state/login_attempts.json'

echo "== Zugriffsschutz"
check "Login-Seite erreichbar" '[ "$(status "$BASE/")" = 200 ]'
check "start.php ohne Login leitet um (303)" '[ "$(status "$BASE/start.php")" = 303 ]'
check "lib/ nicht erreichbar" '[ "$(status "$BASE/lib/auth.php")" = 404 ]'
check "../lib nicht erreichbar" '[ "$(status --path-as-is "$BASE/../lib/auth.php")" = 404 ] || [ "$(status --path-as-is "$BASE/../lib/auth.php")" = 400 ]'
check "Datenverzeichnis nicht erreichbar" '[ "$(status "$BASE/uploads/")" = 404 ]'
check "alte reboot.php entfernt" '[ "$(status "$BASE/reboot.php")" = 404 ]'
check "alte upload.php entfernt" '[ "$(status "$BASE/upload.php")" = 404 ]'
hdr="$(curl -sI "$BASE/")"
check "CSP-Header gesetzt" 'grep -qi "^content-security-policy: default-src" <<<"$hdr"'
check "X-Frame-Options gesetzt" 'grep -qi "^x-frame-options: DENY" <<<"$hdr"'

echo "== Login"
check "Login ohne CSRF-Token abgelehnt (403)" '[ "$(status -d "tuser=$USER&tpass=x" "$BASE/login.php")" = 403 ]'
t="$(token index.php)"
check "Login per GET abgelehnt (405)" '[ "$(status "$BASE/login.php")" = 405 ]'
curl -s -o /dev/null -b "$JAR" -c "$JAR" --data-urlencode "csrf=$t" --data-urlencode "tuser=$USER" --data-urlencode "tpass=falsch" "$BASE/login.php"
check "Falsches Passwort wird gemeldet" 'page index.php | grep -q "Benutzername oder Passwort falsch"'
sid_before="$(grep TELERIXSID "$JAR" | awk '{print $7}')"
t="$(token index.php)"
login_hdr="$(curl -s -D - -o /dev/null -b "$JAR" -c "$JAR" --data-urlencode "csrf=$t" --data-urlencode "tuser=$USER" --data-urlencode "tpass=$PASS" "$BASE/login.php")"
check "Login erfolgreich (303 auf start.php)" 'grep -qi "^location: start.php" <<<"$login_hdr"'
check "Session-Cookie HttpOnly + SameSite=Strict" 'grep -i "^set-cookie: TELERIXSID" <<<"$login_hdr" | grep -qi "httponly" && grep -i "^set-cookie: TELERIXSID" <<<"$login_hdr" | grep -qi "samesite=strict"'
sid_after="$(grep TELERIXSID "$JAR" | awk '{print $7}')"
check "Session-ID nach Login erneuert" '[ -n "$sid_after" ] && [ "$sid_before" != "$sid_after" ]'
check "Startseite zeigt laufenden Asterisk" 'page start.php | grep -q "Asterisk: l&auml;uft"'

echo "== CSRF bei Aktionen"
check "Auflegen per GET abgelehnt (405)" '[ "$(status "$BASE/hangup_all.php")" = 405 ]'
check "Auflegen ohne Token abgelehnt (403)" '[ "$(status -X POST "$BASE/hangup_all.php")" = 403 ]'
t="$(token start.php)"
check "Auflegen mit Token (303)" '[ "$(status --data-urlencode "csrf=$t" "$BASE/hangup_all.php")" = 303 ]'

echo "== Stream-Adresse"
t="$(token configuration.php)"
curl -s -o /dev/null -b "$JAR" -c "$JAR" --data-urlencode "csrf=$t" --data-urlencode 'inetstream=x;$(id)' "$BASE/set_stream.php"
check "Ungültige Adresse abgelehnt" 'page configuration.php | grep -q "Ungültige Stream-Adresse"'
curl -s -o /dev/null -b "$JAR" -c "$JAR" --data-urlencode "csrf=$t" --data-urlencode 'inetstream=127.0.0.1:8099/test.mp3' "$BASE/set_stream.php"
check "Adresse gespeichert (http:// ergänzt)" '[ "$(in_container "cat /var/lib/telerix/asterisk/stream.url")" = "http://127.0.0.1:8099/test.mp3" ]'

echo "== SIP (PJSIP)"
t="$(token configuration.php)"
curl -s -o /dev/null -b "$JAR" -c "$JAR" --data-urlencode "csrf=$t" --data-urlencode 'sip_id=4711 e0' --data-urlencode 'sip_pw=x' --data-urlencode 'sip_reg=192.0.2.10' "$BASE/set_sipconfig.php"
check "Ungültige SIP-ID abgelehnt" 'page configuration.php | grep -q "SIP-ID: erlaubt"'
curl -s -o /dev/null -b "$JAR" -c "$JAR" --data-urlencode "csrf=$t" --data-urlencode 'sip_id=4711000e0' --data-urlencode 'sip_pw=Geheim;123' --data-urlencode 'sip_reg=192.0.2.10' "$BASE/set_sipconfig.php"
check "SIP-Daten gespeichert und geladen" 'page configuration.php | grep -q "SIP-Konfiguration gespeichert und geladen"'
check "Passwort wird nicht ins Formular zurückgegeben" '! page configuration.php | grep -q "Geheim"'
check "Registrierung in Asterisk vorhanden" 'in_container "asterisk -rx \"pjsip show registrations\"" | grep -q "telerix-trunk-reg/sip:192.0.2.10"'
check "Endpunkt in Asterisk vorhanden" 'in_container "asterisk -rx \"pjsip show endpoint telerix-trunk\"" | grep -q "Endpoint:  telerix-trunk"'
check "Passwort mit ; korrekt übernommen" 'in_container "asterisk -rx \"pjsip show auth telerix-trunk-auth\"" | grep -q "password *: Geheim;123"'

echo "== Uploads"
printf '<?php system($_GET["c"]); ?>\n' > "$TMP/shell.php"
cp "$TMP/shell.php" "$TMP/shell.mp3"
in_container 'sox -n -r 16000 -c 2 /tmp/ton.wav synth 2 sine 440 && sox /tmp/ton.wav /tmp/ton.mp3' 2>/dev/null
$DC cp telerix:/tmp/ton.wav "$TMP/ton.wav" >/dev/null 2>&1
$DC cp telerix:/tmp/ton.mp3 "$TMP/ton.mp3" >/dev/null 2>&1
t="$(token ansage.php)"
curl -s -o /dev/null -b "$JAR" -c "$JAR" -F "csrf=$t" -F "ansage=@$TMP/shell.php" "$BASE/ansage.php"
check "PHP-Datei als Ansage abgelehnt (Endung)" 'page ansage.php | grep -q "Dateityp nicht erlaubt"'
curl -s -o /dev/null -b "$JAR" -c "$JAR" -F "csrf=$t" -F "ansage=@$TMP/shell.mp3" "$BASE/ansage.php"
check "PHP-Code mit .mp3-Endung abgelehnt (MIME)" 'page ansage.php | grep -q "Der Inhalt passt nicht"'
check "Ansage-Upload ohne Token abgelehnt (403)" '[ "$(status -F "ansage=@$TMP/ton.wav" "$BASE/ansage.php")" = 403 ]'
curl -s -o /dev/null -b "$JAR" -c "$JAR" -F "csrf=$t" -F "ansage=@$TMP/ton.mp3" "$BASE/ansage.php"
check "MP3-Ansage angenommen" 'page ansage.php | grep -q "eigene Ansage"'
check "Ansage in 8 kHz Mono umgewandelt" 'in_container "soxi /var/lib/telerix/sounds/ansage.wav" | grep -q "Sample Rate *: 8000" && in_container "soxi /var/lib/telerix/sounds/ansage.wav" | grep -q "Channels *: 1"'
check "Original unter zufälligem Namen gespeichert" 'in_container "ls /var/lib/telerix/uploads/audio" | grep -Eq "^[0-9a-f]{32}\.mp3$"'
check "Upload nicht über Web erreichbar" '[ "$(status "$BASE/uploads/audio/")" = 404 ]'

printf 'Max Mustermann 06011123\nErika Muster;+49 6011 2345\n<b>Fett</b> 0232142312\n' > "$TMP/liste.txt"
t="$(token listupload.php)"
curl -s -o /dev/null -b "$JAR" -c "$JAR" -F "csrf=$t" -F "uploaddatei=@$TMP/shell.php" "$BASE/listupload.php"
check "PHP-Datei als Liste abgelehnt" 'page listupload.php | grep -q "Dateityp nicht erlaubt"'
curl -s -o /dev/null -b "$JAR" -c "$JAR" -F "csrf=$t" -F "uploaddatei=@$TMP/liste.txt" "$BASE/listupload.php"
check "Nummernliste angenommen (3 Einträge)" 'page listupload.php | grep -q "3 Einträge übernommen"'
check "Liste angezeigt, HTML maskiert" 'page settings.php | grep -q "&lt;b&gt;Fett&lt;/b&gt;"'
check "+49 normalisiert" 'page settings.php | grep -q "060112345"'

echo "== Sicherung"
t="$(token backup.php)"
curl -s -o /dev/null -b "$JAR" -c "$JAR" --data-urlencode "csrf=$t" -d action=create -d backup_pw=kurz -d backup_pw2=kurz "$BASE/backup.php"
check "Zu kurzes Backup-Passwort abgelehnt" 'page backup.php | grep -q "mindestens 10 Zeichen"'
check "Sicherung ohne Token abgelehnt (403)" '[ "$(status -d action=create -d backup_pw=TestBackup123 -d backup_pw2=TestBackup123 "$BASE/backup.php")" = 403 ]'
bk_hdr="$(curl -s -D - -o "$TMP/sicherung.tlxbak" -b "$JAR" -c "$JAR" --data-urlencode "csrf=$t" -d action=create -d backup_pw=TestBackup123 -d backup_pw2=TestBackup123 "$BASE/backup.php")"
check "Sicherung als Download" 'grep -qi "^content-disposition: attachment; filename=\"telerix-backup-" <<<"$bk_hdr"'
check "Sicherung verschlüsselt (kein Klartext)" 'head -c 17 "$TMP/sicherung.tlxbak" | grep -q "TELERIX-BACKUP-1" && ! grep -aq "Geheim;123\|4711000e0\|stream.url" "$TMP/sicherung.tlxbak"'
# Konfiguration ändern, dann Sicherung einspielen
t="$(token configuration.php)"
curl -s -o /dev/null -b "$JAR" -c "$JAR" --data-urlencode "csrf=$t" --data-urlencode 'inetstream=http://anders.example/x' "$BASE/set_stream.php"
t="$(token backup.php)"
curl -s -o /dev/null -b "$JAR" -c "$JAR" -F "csrf=$t" -F action=restore -F restore_pw=FalschesPasswort -F "backup=@$TMP/sicherung.tlxbak" "$BASE/backup.php"
check "Falsches Backup-Passwort abgelehnt" 'page backup.php | grep -q "Backup-Passwort falsch"'
head -c 2000 "$TMP/sicherung.tlxbak" > "$TMP/kaputt.tlxbak"; printf 'X' >> "$TMP/kaputt.tlxbak"
curl -s -o /dev/null -b "$JAR" -c "$JAR" -F "csrf=$t" -F action=restore -F restore_pw=TestBackup123 -F "backup=@$TMP/kaputt.tlxbak" "$BASE/backup.php"
check "Manipulierte Sicherung abgelehnt" 'page backup.php | grep -q "Backup-Passwort falsch oder Datei beschädigt"'
curl -s -o /dev/null -b "$JAR" -c "$JAR" -F "csrf=$t" -F action=restore -F restore_pw=TestBackup123 -F "backup=@$TMP/shell.php" "$BASE/backup.php"
check "Fremde Datei als Sicherung abgelehnt" 'page backup.php | grep -q "keine Telerix-Sicherungsdatei"'
curl -s -o /dev/null -b "$JAR" -c "$JAR" -F "csrf=$t" -F action=restore -F restore_pw=TestBackup123 -F "backup=@$TMP/sicherung.tlxbak" "$BASE/backup.php"
check "Sicherung eingespielt" 'page backup.php | grep -q "Sicherung eingespielt"'
check "Stream-Adresse wiederhergestellt" '[ "$(in_container "cat /var/lib/telerix/asterisk/stream.url")" = "http://127.0.0.1:8099/test.mp3" ]'
check "SIP-Zugang wiederhergestellt" 'in_container "asterisk -rx \"pjsip show auth telerix-trunk-auth\"" | grep -q "password *: Geheim;123"'
check "Ansage und Nummernliste wiederhergestellt" 'page ansage.php | grep -q "eigene Ansage" && page settings.php | grep -q "Max Mustermann"'
check "Nach dem Einspielen weiter angemeldet" 'page start.php | grep -q "Asterisk: l&auml;uft"'

echo "== Updates"
check "Update-Seite erreichbar" 'page update.php | grep -q "Installierte Version"'

echo "== Asterisk-Neustart über das Webinterface"
t="$(token start.php)"
check "Neustart angestoßen" '[ "$(status --data-urlencode "csrf=$t" "$BASE/asterisk_restart.php")" = 303 ]'
up=0
for i in $(seq 1 15); do
   sleep 2
   if page start.php | grep -q "Asterisk: l&auml;uft"; then up=1; break; fi
done
check "Asterisk nach Neustart wieder erreichbar" '[ "$up" = 1 ]'
reg=0
for i in $(seq 1 10); do
   if in_container "asterisk -rx \"pjsip show registrations\"" | grep -q "telerix-trunk-reg"; then reg=1; break; fi
   sleep 2
done
check "PJSIP-Konfiguration nach Neustart geladen" '[ "$reg" = 1 ]'

echo "== Logout und Drosselung"
t="$(token start.php)"
curl -s -o /dev/null -b "$JAR" -c "$JAR" --data-urlencode "csrf=$t" "$BASE/logout.php"
check "Nach Logout kein Zugriff" '[ "$(status "$BASE/start.php")" = 303 ]'
for i in 1 2 3 4 5; do
   t="$(token index.php)"
   curl -s -o /dev/null -b "$JAR" -c "$JAR" --data-urlencode "csrf=$t" --data-urlencode "tuser=$USER" --data-urlencode "tpass=falsch$i" "$BASE/login.php"
done
t="$(token index.php)"
curl -s -o /dev/null -b "$JAR" -c "$JAR" --data-urlencode "csrf=$t" --data-urlencode "tuser=$USER" --data-urlencode "tpass=$PASS" "$BASE/login.php"
check "Nach 5 Fehlversuchen gesperrt, auch mit richtigem Passwort" 'page index.php | grep -q "Zu viele Fehlversuche"'
in_container 'rm -f /var/lib/telerix/state/login_attempts.json'

echo "== Rechte im Container"
check "Kein sudo installiert" '! in_container "command -v sudo" >/dev/null'
check "Keine Dateien mit 777" '[ -z "$(in_container "find /etc/asterisk /var/lib/telerix /opt/telerix -perm -o+w 2>/dev/null")" ]'
check "Asterisk-Datenverzeichnisse mit setgid (2750)" '[ "$(in_container "stat -c %a /var/lib/telerix/asterisk /var/lib/telerix/sounds" | tr "\n" " ")" = "2750 2750 " ]'
check "Vom Webinterface geschriebene Dateien für Asterisk lesbar" '[ -z "$(in_container "find /var/lib/telerix/asterisk /var/lib/telerix/sounds -type f ! -group asterisk")" ]'
check "admin.json nur für telerix lesbar (600)" '[ "$(in_container "stat -c %a:%U /var/lib/telerix/config/admin.json")" = "600:telerix" ]'
check "PHP läuft als telerix" 'in_container "ps -o user,args" | grep "php-fpm: pool" | grep -q "^telerix"'
check "Asterisk läuft als asterisk" 'in_container "ps -o user,args" | grep "asterisk -f" | grep -vq "^root"'

echo
if [ "$fails" -eq 0 ]; then echo "Alle Tests bestanden."; else echo "$fails Test(s) fehlgeschlagen."; fi
exit $((fails > 0))
