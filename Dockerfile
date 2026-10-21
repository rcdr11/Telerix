# syntax=docker/dockerfile:1
#
# Telerix (veränderte Version) – Asterisk 22 + nginx + PHP-FPM 8.4 auf Alpine.
#
# Basis-Image und Paketversionen sind fest gepinnt. Alpine hält in einem
# Release-Zweig nur die jeweils neueste Paketrevision vor: Wird ein Paket
# aktualisiert, schlägt der Build bewusst fehl, statt still eine andere
# Version einzubauen. Dann die Versionen unten anpassen (siehe README).

FROM alpine:3.24.2@sha256:294b683cb724975bec92580e1e685676bd4b50bda910ddb8c51d4cabeaec77e6

ARG ASTERISK_VERSION=22.9.0-r0
ARG PHP_VERSION=8.4.26-r0

RUN apk add --no-cache \
      asterisk=${ASTERISK_VERSION} \
      asterisk-sounds-en=${ASTERISK_VERSION} \
      php84=${PHP_VERSION} \
      php84-fpm=${PHP_VERSION} \
      php84-session=${PHP_VERSION} \
      php84-fileinfo=${PHP_VERSION} \
      php84-mbstring=${PHP_VERSION} \
      nginx=1.30.4-r1 \
      supervisor=4.3.0-r1 \
      mpg123=1.33.5-r0 \
      sox=14.4.2-r14 \
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

ENV TELERIX_COOKIE_SECURE=0

VOLUME ["/var/lib/telerix"]
EXPOSE 8080/tcp 5060/udp 10000-10099/udp

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s \
  CMD wget -q -O /dev/null http://127.0.0.1:8080/index.php \
   && /usr/sbin/asterisk -rx "core show uptime" >/dev/null || exit 1

ENTRYPOINT ["/usr/local/bin/telerix-entrypoint"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
