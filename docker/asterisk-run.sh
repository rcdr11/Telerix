#!/bin/sh
# Startet Asterisk im Vordergrund (von supervisord aufgerufen) und beendet es
# beim Stoppen des Containers sauber.
#
# Asterisk meldet sich bei SIGTERM und auch bei "core stop now" NICHT beim
# SIP-Anbieter ab. Der Anbieter behält die alte Registrierung dann bis zu
# ihrem Ablauf und verzweigt Anrufe zusätzlich dorthin (doppelte Anrufe nach
# Neustart/Update). Deshalb hier: erst abmelden (REGISTER mit Expires: 0),
# kurz warten, dann sofort beenden.

ASTERISK=/usr/sbin/asterisk
# Asterisk zeigt nach "pjsip send unregister" sofort "Unregistered" an, auch
# wenn die Antwort des Anbieters noch aussteht (mit Authentifizierung zwei
# Hin- und Rückwege). Daher eine feste Wartezeit, die auch eine
# SIP-Wiederholung nach einem verlorenen Paket abdeckt (0,5 s und 1,5 s).
UNREGISTER_WAIT=3

"$ASTERISK" -f -n -U asterisk &
pid=$!
stopping=0

stop() {
   stopping=1
   if "$ASTERISK" -rx "pjsip show registrations" 2>/dev/null | grep -q " Registered "; then
      "$ASTERISK" -rx "pjsip send unregister *all" >/dev/null 2>&1
      sleep "$UNREGISTER_WAIT"
      echo "telerix: Abmeldung beim SIP-Anbieter gesendet"
   fi
   "$ASTERISK" -rx "core stop now" >/dev/null 2>&1 || kill -TERM "$pid" 2>/dev/null
}
trap stop TERM INT

# wait kehrt beim Signal zurück; danach auf das Ende von Asterisk warten.
# "core restart now" startet Asterisk unter derselben PID neu.
status=0
while kill -0 "$pid" 2>/dev/null; do
   wait "$pid"
   status=$?
done
[ "$stopping" = 1 ] && exit 0
exit "$status"
