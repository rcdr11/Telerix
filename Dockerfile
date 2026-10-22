# syntax=docker/dockerfile:1
#
# Telerix (veränderte Version) – Asterisk 22 + nginx + PHP-FPM 8.4 auf Alpine.
#
# Das Basis-Image ist mit Prüfsumme festgelegt, die Pakete nur auf ihre
# Versionsreihe (z. B. nginx~1.30 = jede 1.30.x). Alpine hält in einem
# Release-Zweig nur die jeweils neueste Paketversion vor; exakte Versionen
# ließen den Build bei jedem Paket-Update von Alpine scheitern. So kommen
# Korrektur- und Sicherheitsupdates beim nächsten Neubau automatisch mit,
# größere Sprünge (z. B. Asterisk 23, PHP 8.5) nur durch eine Änderung hier.
# Ein monatlicher Build auf GitHub prüft, dass das Image baubar bleibt
# (.github/workflows/build.yml).

FROM alpine:3.24.2@sha256:294b683cb724975bec92580e1e685676bd4b50bda910ddb8c51d4cabeaec77e6

# Asterisk: LTS-Reihe 22
ARG ASTERISK_SERIES=22
# Paketname php84 = immer PHP 8.4. Mindestversion statt Reihe: 8.4.27 behebt
# CVE-2026-109618 und CVE-2026-109619 ("PHP2Shell"), älter darf es nie sein.
ARG PHP_MIN=8.4.27

RUN apk add --no-cache \
      "asterisk~${ASTERISK_SERIES}" \
      "asterisk-sounds-en~${ASTERISK_SERIES}" \
      "php84>=${PHP_MIN}" \
      "php84-fpm>=${PHP_MIN}" \
      "php84-session>=${PHP_MIN}" \
      "php84-fileinfo>=${PHP_MIN}" \
      "php84-mbstring>=${PHP_MIN}" \
      "php84-openssl>=${PHP_MIN}" \
      "php84-sodium>=${PHP_MIN}" \
      "nginx~1.30" \
      "supervisor~4.3" \
      "mpg123~1.33" \
      "sox~14.4" \
      # Version ist ein Datum, daher Mindestversion statt Reihe
      "mbuffer>=20251025" \
 # Benutzer: PHP läuft als "telerix". Die Gruppe "astctl" darf den
 # Asterisk-Control-Socket benutzen (asterisk -rx) – sonst nichts.
 && addgroup -S telerix \
 && adduser -S -D -H -h /nonexistent -s /sbin/nologin -G telerix telerix \
 && addgroup -S astctl \
 && addgroup asterisk astctl \
 && addgroup telerix astctl \
 # Mitgelieferte Beispielkonfigurationen entfernen
 && rm -rf /etc/asterisk/* /etc/nginx/http.d/* /etc/php84/php-fpm.d/*

# Asterisk-Konfiguration: lesbar für Asterisk, nicht schreibbar.
# asterisk.conf muss auch für "asterisk -rx" (telerix) lesbar sein; dafür
# darf jeder das Verzeichnis durchqueren (0751), aber nicht auflisten.
COPY --chown=root:asterisk --chmod=0640 asterisk/*.conf /etc/asterisk/
COPY --chmod=0755 asterisk/stream.sh /usr/local/bin/telerix-stream
COPY --chmod=0644 ansagen/willkommen-gottesdienst.gsm /usr/share/asterisk/sounds/tx/
RUN chmod 0644 /etc/asterisk/asterisk.conf \
 && chmod 0751 /etc/asterisk && chown root:asterisk /etc/asterisk \
 && chmod 0755 /usr/share/asterisk/sounds/tx

# Webinterface: gehört root, für PHP nur lesbar. Nur public/ ist Webroot.
COPY --chown=root:root telerix/ /opt/telerix/
RUN find /opt/telerix -type d -exec chmod 0755 {} + \
 && find /opt/telerix -type f -exec chmod 0644 {} +

COPY --chmod=0644 docker/nginx.conf /etc/nginx/nginx.conf
COPY --chmod=0644 docker/php-fpm.conf /etc/php84/php-fpm.conf
COPY --chmod=0644 docker/php-fpm-telerix.conf /etc/php84/php-fpm.d/telerix.conf
COPY --chmod=0644 docker/supervisord.conf /etc/supervisord.conf
COPY --chmod=0755 docker/entrypoint.sh /usr/local/bin/telerix-entrypoint
COPY --chmod=0755 docker/asterisk-run.sh /usr/local/bin/telerix-asterisk

ENV TELERIX_COOKIE_SECURE=0 \
    TELERIX_UPDATE_REPO=""

VOLUME ["/var/lib/telerix"]
EXPOSE 8080/tcp 5060/udp 10000-10099/udp

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s \
  CMD wget -q -O /dev/null http://127.0.0.1:8080/index.php \
   && /usr/sbin/asterisk -rx "core show uptime" >/dev/null || exit 1

ENTRYPOINT ["/usr/local/bin/telerix-entrypoint"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
