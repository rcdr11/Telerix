#!/bin/sh
# Wartemusik-Quelle für Asterisk (musiconhold.conf, mode=custom).
# Liest die Stream-Adresse aus der vom Webinterface geschriebenen Datei,
# dekodiert den MP3-Stream und gibt 8 kHz Mono signed linear auf stdout aus.
# Früher wurde die URL per sed in Zeile 3 dieses Skripts geschrieben.

urlfile=/var/lib/telerix/asterisk/stream.url
streamurl=$(head -n 1 "$urlfile" 2>/dev/null)

case "$streamurl" in
   http://*|https://*) ;;
   *)
      # Kein Stream eingerichtet: kurz warten, Asterisk startet das Skript neu
      exec sleep 10
      ;;
esac

# -q leise, -s Ausgabe auf stdout, -m mono, -r 8000 Hz,
# -f 13000 entspricht etwa -8 dB (wie früher "madplay -a -8")
exec mpg123 -q -s -m -r 8000 -f 13000 -- "$streamurl"
