#!/bin/sh
# Startet Asterisk im Vordergrund (von supervisord aufgerufen) und beendet es
# beim Stoppen des Containers sauber.
#
# Asterisk meldet sich bei SIGTERM und auch bei "core stop now" NICHT beim
# SIP-Anbieter ab. Der Anbieter behält die alte Registrierung dann bis zu
# ihrem Ablauf und verzweigt Anrufe zusätzlich dorthin (doppelte Anrufe nach
# Neustart/Update). Deshalb hier: erst abmelden (REGISTER mit Expires: 0),
# kurz warten, dann sofort beenden.
#
# Die Ausgabe von Asterisk (und der Wartemusik telerix-stream) läuft über
# einen Filter ins Container-Log, der die Meldungen von SIP-Scannern
# stündlich zusammenfasst (docker/asterisk-log.awk). Der Filter hängt an
# einer FIFO, damit $pid weiterhin Asterisk selbst ist.

ASTERISK=/usr/sbin/asterisk
LOGFILTER=/usr/local/lib/telerix/asterisk-log.awk
FIFO=/run/telerix-asterisk-log
# Asterisk zeigt nach "pjsip send unregister" sofort "Unregistered" an, auch
# wenn die Antwort des Anbieters noch aussteht (mit Authentifizierung zwei
# Hin- und Rückwege). Daher eine feste Wartezeit, die auch eine
# SIP-Wiederholung nach einem verlorenen Paket abdeckt (0,5 s und 1,5 s).
UNREGISTER_WAIT=3

if [ -r "$LOGFILTER" ] && rm -f "$FIFO" && mkfifo -m 0600 "$FIFO"; then
   # Filter läuft, bis Asterisk die FIFO schließt (auch über "core restart
   # now" hinweg, das Asterisk unter derselben PID neu startet)
   awk -f "$LOGFILTER" < "$FIFO" &
   filter=$!
   "$ASTERISK" -f -n -U asterisk > "$FIFO" 2>&1 &
else
   echo "telerix: Log-Filter nicht verfügbar, Asterisk-Ausgabe ungefiltert"
   "$ASTERISK" -f -n -U asterisk &
fi
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
# Filter die letzte Zusammenfassung schreiben lassen: Er endet, sobald die
# FIFO keinen Schreiber mehr hat. Hält ein übrig gebliebener Kindprozess
# von Asterisk sie offen, nach 2 s beenden, damit supervisord neu starten kann.
if [ -n "${filter:-}" ]; then
   i=0
   while [ "$i" -lt 10 ] && kill -0 "$filter" 2>/dev/null; do
      sleep 0.2
      i=$((i + 1))
   done
   kill "$filter" 2>/dev/null
fi
rm -f "$FIFO"
[ "$stopping" = 1 ] && exit 0
exit "$status"
