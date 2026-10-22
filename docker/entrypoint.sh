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
for f in pjsip_trunk.conf stream.url; do
   if [ ! -e "$D/asterisk/$f" ]; then
      : > "$D/asterisk/$f"
   fi
done

# Von Asterisk gelesene Dateien neu erzeugen: SIP-Registrierung aus den
# gespeicherten Daten, Ton, Ansage an/aus, öffentliche Adresse für SIP/RTP
# und Testtelefon aus den Einstellungen des Webinterface bzw. aus .env
# (Vorrang). So werden Änderungen an den Vorlagen nach einem Update und
# Änderungen an .env nach "docker compose up -d" wirksam. Ungültige Werte
# für die öffentliche Adresse oder das Testtelefon verhindern den Start.
php84 /opt/telerix/bin/render-config.php || exit 1
find "$D/asterisk" -type f -exec chown telerix:asterisk {} + -exec chmod 0640 {} +

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

# Webinterface zusätzlich über IPv6, falls im Container verfügbar. Ein
# Reverse-Proxy in einem Docker-Netz mit IPv6 versucht den Dienstnamen
# sonst zuerst über IPv6 und bekommt "Connection refused". Geprüft wird mit
# einem echten Socket, weil "nginx -t" keine Ports belegt.
listen6=/etc/nginx/telerix-listen-ipv6.conf
echo "# Automatisch erzeugt: IPv6 im Container verfügbar?" > "$listen6"
if php84 -r 'exit(@stream_socket_server("tcp://[::]:0") ? 0 : 1);' 2>/dev/null; then
   echo "listen [::]:8080 default_server;" >> "$listen6"
else
   echo "telerix: IPv6 im Container nicht verfügbar, Webinterface nur über IPv4"
fi
chmod 0644 "$listen6"

# Admin-Zugangsdaten beim ersten Start anlegen
php84 /opt/telerix/bin/init-admin.php
chown -R telerix:telerix "$D/config"

exec "$@"
