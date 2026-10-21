#!/bin/sh
# Telerix-Containerstart: Datenverzeichnis mit minimalen Rechten anlegen,
# Admin-Zugang beim ersten Start einrichten, dann Dienste starten.
set -eu

D=/var/lib/telerix

# Verzeichnisse und Rechte (bei jedem Start, falls das Volume neu ist oder
# Rechte verändert wurden)
#   config, state, uploads: nur PHP (telerix)
#   asterisk, sounds:       von PHP geschrieben, von Asterisk gelesen (setgid)
mkdir -p "$D/config" "$D/state" "$D/uploads/lists" "$D/uploads/audio" "$D/asterisk" "$D/sounds"
chown root:root "$D"
chmod 0755 "$D"
chown -R telerix:telerix "$D/config" "$D/state" "$D/uploads"
chown -R telerix:asterisk "$D/asterisk" "$D/sounds"
# Rechte erst nach chown setzen: chown löscht das setgid-Bit. Ohne setgid
# bekämen vom Webinterface geschriebene Dateien die Gruppe telerix und wären
# für Asterisk nicht lesbar.
find "$D/config" "$D/state" "$D/uploads" -type d -exec chmod 0700 {} +
chmod 2750 "$D/asterisk" "$D/sounds"
find "$D/config" "$D/state" "$D/uploads" -type f -exec chmod 0600 {} +
find "$D/asterisk" "$D/sounds" -type f -exec chmod 0640 {} +

# Laufzeitverzeichnisse
install -d -o telerix -g telerix -m 0700 /run/telerix /run/telerix/sessions
install -d -o asterisk -g asterisk -m 0755 /run/asterisk
install -d -o root -g root -m 0755 /run/php-fpm

# Von pjsip.conf/musiconhold eingebundene Dateien müssen existieren
for f in pjsip_trunk.conf pjsip_testphone.conf stream.url; do
   if [ ! -e "$D/asterisk/$f" ]; then
      : > "$D/asterisk/$f"
      chown telerix:asterisk "$D/asterisk/$f"
      chmod 0640 "$D/asterisk/$f"
   fi
done

# Optionales Testtelefon (Benutzer "testphone", Kontext from-internal,
# Extension 100). Ohne Passwort wird kein Endpunkt angelegt.
tp="$D/asterisk/pjsip_testphone.conf"
pw="${TELERIX_TESTPHONE_PASSWORD:-}"
if [ -n "$pw" ]; then
   if [ "${#pw}" -lt 12 ] || ! printf '%s' "$pw" | grep -Eq '^[A-Za-z0-9._+-]+$'; then
      echo "telerix: TELERIX_TESTPHONE_PASSWORD muss mind. 12 Zeichen aus A-Z, a-z, 0-9, . _ + - haben." >&2
      exit 1
   fi
   cat > "$tp" <<EOF
; Automatisch erzeugt aus TELERIX_TESTPHONE_PASSWORD
[testphone]
type=auth
auth_type=userpass
username=testphone
password=$pw

[testphone]
type=aor
max_contacts=1

[testphone]
type=endpoint
transport=transport-udp
context=from-internal
disallow=all
allow=g722,alaw,ulaw,gsm
auth=testphone
aors=testphone
direct_media=no
rtp_symmetric=yes
force_rport=yes
rewrite_contact=yes
EOF
else
   echo "; Kein Testtelefon (TELERIX_TESTPHONE_PASSWORD nicht gesetzt)" > "$tp"
fi
chown telerix:asterisk "$tp"
chmod 0640 "$tp"

# Reverse-Proxy: X-Forwarded-For nur von den eingetragenen Adressen auswerten
# (nginx realip). Leer = Header wird ignoriert, REMOTE_ADDR bleibt der
# direkte Absender.
realip=/etc/nginx/telerix-realip.conf
proxies="$(php84 /opt/telerix/bin/check-address.php proxies "${TELERIX_TRUSTED_PROXIES:-}")" || exit 1
{
   echo "# Automatisch erzeugt aus TELERIX_TRUSTED_PROXIES"
   if [ -n "$proxies" ]; then
      for p in $proxies; do
         echo "set_real_ip_from $p;"
      done
      echo "real_ip_header X-Forwarded-For;"
      echo "real_ip_recursive on;"
   fi
} > "$realip"
chmod 0644 "$realip"
if [ -n "$proxies" ]; then
   echo "telerix: X-Forwarded-For wird ausgewertet von: $(echo $proxies)"
fi

# Admin-Zugangsdaten beim ersten Start anlegen
php84 /opt/telerix/bin/init-admin.php
chown -R telerix:telerix "$D/config"

exec "$@"
