#!/bin/sh
# Wartemusik-Quelle für Asterisk (musiconhold.conf, mode=custom).
# Liest die Stream-Adresse aus der vom Webinterface geschriebenen Datei,
# dekodiert den MP3-Stream und gibt 8 kHz Mono signed linear auf stdout aus.
# Früher wurde die URL per sed in Zeile 3 dieses Skripts geschrieben.
#
# Asterisk startet das Skript neu, sobald es endet. Ändert sich die Adresse
# im Webinterface, beendet sich das Skript innerhalb weniger Sekunden und
# übernimmt so die neue Adresse. Nach einem Fehler wird gewartet, damit ein
# nicht erreichbarer Stream nicht in einer Endlosschleife das Log flutet.

urlfile=/var/lib/telerix/asterisk/stream.url
current_url() { head -n 1 "$urlfile" 2>/dev/null; }
streamurl=$(current_url)

# Bis zu $1 Sekunden warten, bei geänderter Adresse sofort beenden
wait_or_change() {
   i=0
   while [ "$i" -lt "$1" ]; do
      sleep 2
      i=$((i + 2))
      [ "$(current_url)" = "$streamurl" ] || exit 0
   done
}

case "$streamurl" in
   http://*|https://*) ;;
   *)
      # Kein Stream eingerichtet
      wait_or_change 30
      exit 0
      ;;
esac

# -q leise, -s Ausgabe auf stdout, -m mono, -r 8000 Hz,
# -f 13000 entspricht etwa -8 dB (wie früher "madplay -a -8")
mpg123 -q -s -m -r 8000 -f 13000 -- "$streamurl" 2>/dev/null &
pid=$!
trap 'kill "$pid" 2>/dev/null' EXIT TERM INT
while kill -0 "$pid" 2>/dev/null; do
   sleep 2
   if [ "$(current_url)" != "$streamurl" ]; then
      exit 0
   fi
done

echo "telerix-stream: Stream $streamurl nicht erreichbar oder beendet, neuer Versuch in 30 s" >&2
wait_or_change 30
