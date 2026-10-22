#!/bin/sh
# Wartemusik-Quelle für Asterisk (musiconhold.conf, mode=custom, format=slin16).
#
# Liest die Stream-Adresse aus der vom Webinterface geschriebenen Datei und
# bereitet den MP3-Stream für das Telefon auf:
#
#   mpg123   dekodiert in der Original-Abtastrate (AU-Format, Länge offen)
#   sox      Mono, saubere Umrechnung auf 16 kHz; wenn eingestellt: Hochpass
#            gegen Brummen und Rumpeln, Leveler je nach Klangprofil,
#            Anhebung mit Begrenzer gegen Übersteuerung. Standard ist
#            unbearbeitet: nur Mono und 16 kHz.
#   mbuffer  einige Sekunden Vorlauf, damit kurze Stockungen des Streams
#            nicht als Aussetzer beim Anrufer ankommen
#
# Asterisk liest die Ausgabe (signed 16 bit, mono) in Echtzeit und rechnet
# für Anrufer mit G.711 (8 kHz) selbst herunter. Bricht der Stream ab,
# verbindet das Skript nach kurzer, dann wachsender Pause neu.
#
#   telerix-stream --hoerprobe <sekunden> <anhebung> <hochpass> <profil>
#      gibt einmalig so viele Sekunden mit den angegebenen Werten aus, ohne
#      Vorlauf (Hörprobe im Webinterface)
#
# Messwerte vorher/nachher: docs/tonqualitaet.md

# ---- Einstellungen ------------------------------------------------------
# Abtastrate der Ausgabe, muss zu format= in musiconhold.conf passen
AUSGABE_RATE=16000
# Frequenzen darunter werden abgeschnitten (Brummen, Trittschall, Rumpeln);
# 0 = kein Hochpass
HOCHPASS_HZ=0
# Klangprofil: aus (unbearbeitet), sprache oder musik ("Sprache und Musik")
PROFIL=aus
# Leveler (sox compand): Ansprech-,Rückkehrzeit in s, dann Kennlinie
# Eingangspegel -> Ausgangspegel in dB.
# sprache: hebt leise Sprache kräftig an und dämpft laute Passagen.
LEVELER_SPRACHE="0.01,0.50 6:-80,-80,-60,-52,-45,-30,-30,-21,-20,-17,-10,-13,0,-10"
# musik: sanfter und langsamer, leise und laute Stellen von Gesang und Orgel
# bleiben unterscheidbar; Sprache kommt etwa gleich laut an wie bei sprache.
LEVELER_MUSIK="0.2,2.5 6:-80,-80,-60,-55,-45,-36,-30,-25,-20,-19,-10,-14,0,-10"
# Lautstärke in dB (-24 bis +24), 0 = unverändert. Negativ: einfache
# Absenkung. Positiv: Anhebung mit Begrenzer; +5 ergibt bei leiser Sprache
# (-26 dBFS) etwa -20 dBFS RMS, jedes dB mehr hebt den Pegel um etwa 1 dB.
ANHEBUNG_DB=0
# Obergrenze für Spitzen in dBFS (Begrenzer mit 0,1 ms Ansprechzeit); der
# Begrenzer läuft nur bei einer Anhebung, ohne sie kann nichts übersteuern
OBERGRENZE_DB=-3.5
# Vorlauf in Sekunden gegen Stockungen; um diese Zeit verzögert sich der Ton
PUFFER_SEKUNDEN=4
# Pausen in Sekunden vor den Verbindungsversuchen nach einem Abbruch; die
# letzte gilt für alle weiteren. Nach STABIL_SEKUNDEN ohne Abbruch beginnen
# sie wieder von vorn.
NEUVERSUCH_SEKUNDEN="2 5 10 30"
STABIL_SEKUNDEN=60
# -------------------------------------------------------------------------

# Die Werte zu Ton am Telefon kommen aus dem Webinterface (Konfiguration ->
# Ton am Telefon) bzw. für Anhebung und Vorlauf aus .env
# (TELERIX_AUDIO_ANHEBUNG_DB, TELERIX_AUDIO_PUFFER_SEKUNDEN, Vorrang). Das
# Webinterface und der Containerstart schreiben die geltenden Werte nach
# audio.conf. Ungültige Werte werden ignoriert.
ganzzahl_im_bereich() {
   case "$1" in
      ''|*[!0-9-]*|-|*-*-*|?*-*) return 1 ;;
   esac
   [ "$1" -ge "$2" ] && [ "$1" -le "$3" ]
}
gueltiger_hochpass() { case "$1" in 0|50|80|120|180|250) return 0 ;; esac; return 1; }
gueltiges_profil() { case "$1" in sprache|musik|aus) return 0 ;; esac; return 1; }

audiofile=/var/lib/telerix/asterisk/audio.conf
audio_wert() { sed -n "s/^$1=//p" "$audiofile" 2>/dev/null | head -n 1; }
wert=$(audio_wert ANHEBUNG_DB)
ganzzahl_im_bereich "$wert" -24 24 && ANHEBUNG_DB=$wert
wert=$(audio_wert PUFFER_SEKUNDEN)
ganzzahl_im_bereich "$wert" 0 10 && PUFFER_SEKUNDEN=$wert
wert=$(audio_wert HOCHPASS_HZ)
gueltiger_hochpass "$wert" && HOCHPASS_HZ=$wert
wert=$(audio_wert PROFIL)
gueltiges_profil "$wert" && PROFIL=$wert

hoerprobe=""
if [ "${1:-}" = "--hoerprobe" ]; then
   if ! ganzzahl_im_bereich "${2:-}" 1 60 || ! ganzzahl_im_bereich "${3:-}" -24 24 \
         || ! gueltiger_hochpass "${4:-}" || ! gueltiges_profil "${5:-}"; then
      echo "Aufruf: telerix-stream --hoerprobe <sekunden 1-60> <lautstaerke -24 bis 24> <hochpass 0|50|80|120|180|250> <sprache|musik|aus>" >&2
      exit 2
   fi
   hoerprobe=$2 ANHEBUNG_DB=$3 HOCHPASS_HZ=$4 PROFIL=$5
fi

urlfile=/var/lib/telerix/asterisk/stream.url
current_url() { head -n 1 "$urlfile" 2>/dev/null; }
streamurl=$(current_url)

case "$streamurl" in
   http://*|https://*) stream_ok=1 ;;
   *) stream_ok="" ;;
esac

# Lautstärke. Anhebung über die Kennlinie des Begrenzers: volle Anhebung bis
# 3 dB unter der Obergrenze, darüber weicher Übergang zur Obergrenze
# (monoton für 1 bis 24 dB). Absenkung ohne Begrenzer, dabei kann nichts
# übersteuern.
begrenzer=""
if [ "$ANHEBUNG_DB" -lt 0 ]; then
   begrenzer="gain $ANHEBUNG_DB"
elif [ "$ANHEBUNG_DB" -gt 0 ]; then
   begrenzer_kurve=$(awk -v g="$ANHEBUNG_DB" -v o="$OBERGRENZE_DB" \
      'BEGIN {printf "-80,%g,%g,%g,0,%g", -80 + g, o - g - 3, o - 3, o}')
   begrenzer="compand 0.0001,0.10 $begrenzer_kurve 0 -90 0.002"
fi

# Hochpass und Leveler als sox-Effekte (Wörter durch Leerzeichen getrennt;
# set -f, damit nichts als Dateimuster gelesen wird)
set -f
effekte=""
[ "$HOCHPASS_HZ" -gt 0 ] && effekte="highpass $HOCHPASS_HZ"
case "$PROFIL" in
   sprache) effekte="$effekte compand $LEVELER_SPRACHE 0 -90 0.05" ;;
   musik)   effekte="$effekte compand $LEVELER_MUSIK 0 -90 0.05" ;;
esac

aufbereiten() {
   mpg123 -q --au - -- "$streamurl" 2>/dev/null \
      | sox -q -V1 -t au - -t raw -r "$AUSGABE_RATE" -e signed-integer -b 16 -c 1 - \
           remix - \
           rate -v "$AUSGABE_RATE" \
           $effekte $begrenzer
}

# Alle Kindprozesse beenden (Pipeline, sleep). Asterisk beendet beim Neuladen
# ohnehin die ganze Prozessgruppe; das hier gilt für alle anderen Fälle,
# auch für eine abgebrochene Hörprobe.
cleanup() { pkill -P $$ 2>/dev/null; }
trap 'cleanup; exit 0' TERM INT HUP
trap cleanup EXIT

if [ -n "$hoerprobe" ]; then
   if [ -z "$stream_ok" ]; then
      echo "Keine Stream-Adresse eingerichtet" >&2
      exit 1
   fi
   aufbereiten | head -c $((AUSGABE_RATE * 2 * hoerprobe))
   exit 0
fi

# Ändern sich Stream-Adresse oder Tonaufbereitung, beendet sich das Skript;
# Asterisk startet es dann mit den neuen Werten neu
zustand() { cat "$urlfile" "$audiofile" 2>/dev/null; }
startzustand=$(zustand)
geaendert() { [ "$(zustand)" != "$startzustand" ]; }

# Bis zu $1 Sekunden warten, bei geänderten Einstellungen sofort beenden
wait_or_change() {
   i=0
   while [ "$i" -lt "$1" ]; do
      sleep 1
      i=$((i + 1))
      geaendert && exit 0
   done
}

if [ -z "$stream_ok" ]; then
   # Kein Stream eingerichtet
   wait_or_change 30
   exit 0
fi

# Puffer: Vorlauf plus 2 s Reserve für Schübe nach einer Stockung.
# Der Vorlauf entsteht beim Verbinden: Die letzte Stufe wartet
# PUFFER_SEKUNDEN und reicht danach alles durch, mbuffer sammelt solange.
# (mbuffer -P eignet sich nicht: Es hält die Ausgabe jedes Mal an, wenn sein
# interner Puffer leer ist, bis die Schwelle wieder erreicht ist – das
# erzeugte im Test lange Lücken.)
puffer_bytes=$((AUSGABE_RATE * 2 * (PUFFER_SEKUNDEN + 2)))

# Neu verbinden im Skript selbst statt sich zu beenden: Asterisk startet ein
# Wartemusik-Programm, das kurz nach dem Start endet, erst verzögert neu.
versuch=0
while :; do
   start=$(date +%s)
   aufbereiten \
      | mbuffer -q -m "$puffer_bytes" -s 640 2>/dev/null \
      | { sleep "$PUFFER_SEKUNDEN"; exec cat; } &
   pid=$!

   # Solange die Pipeline läuft: bei geänderten Einstellungen beenden
   while kill -0 "$pid" 2>/dev/null; do
      sleep 2
      if geaendert; then
         exit 0
      fi
   done

   [ $(($(date +%s) - start)) -ge "$STABIL_SEKUNDEN" ] && versuch=0
   versuch=$((versuch + 1))
   pause=$(echo $NEUVERSUCH_SEKUNDEN | awk -v n="$versuch" '{print (n <= NF) ? $n : $NF}')
   echo "telerix-stream: Stream $streamurl nicht erreichbar oder beendet, neuer Versuch in $pause s" >&2
   wait_or_change "$pause"
done
