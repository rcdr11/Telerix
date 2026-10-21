#!/bin/sh
# Wartemusik-Quelle für Asterisk (musiconhold.conf, mode=custom, format=slin16).
#
# Liest die Stream-Adresse aus der vom Webinterface geschriebenen Datei und
# bereitet den MP3-Stream für das Telefon auf:
#
#   mpg123   dekodiert in der Original-Abtastrate (AU-Format, Länge offen)
#   sox      Mono, saubere Umrechnung auf 16 kHz, Hochpass gegen Brummen und
#            Rumpeln, Leveler für gleichmäßig verständliche Sprache,
#            Endverstärkung mit Begrenzer gegen Übersteuerung
#   mbuffer  einige Sekunden Vorlauf, damit kurze Stockungen des Streams
#            nicht als Aussetzer beim Anrufer ankommen
#
# Asterisk liest die Ausgabe (signed 16 bit, mono) in Echtzeit und rechnet
# für Anrufer mit G.711 (8 kHz) selbst herunter.
#
# Messwerte vorher/nachher: docs/tonqualitaet.md

# ---- Einstellungen ------------------------------------------------------
# Abtastrate der Ausgabe, muss zu format= in musiconhold.conf passen
AUSGABE_RATE=16000
# Frequenzen darunter werden abgeschnitten (Brummen, Trittschall, Rumpeln)
HOCHPASS_HZ=180
# Leveler (sox compand): Eingangspegel -> Ausgangspegel in dB. Hebt leise
# Sprache an und dämpft laute Passagen. Reagiert in 10 ms mit 50 ms Vorschau,
# kehrt in 0,5 s zurück.
LEVELER_KURVE="6:-80,-80,-60,-52,-45,-30,-30,-21,-20,-17,-10,-13,0,-10"
# Endverstärkung in dB. +5 ergibt bei Sprache etwa -20 dBFS RMS;
# jedes dB mehr hebt den Pegel um etwa 1 dB.
ANHEBUNG_DB=5
# Obergrenze für Spitzen in dBFS (Begrenzer mit 0,1 ms Ansprechzeit)
OBERGRENZE_DB=-3.5
# Vorlauf in Sekunden gegen Stockungen; um diese Zeit verzögert sich der Ton
PUFFER_SEKUNDEN=4
# -------------------------------------------------------------------------

# Anhebung und Vorlauf lassen sich ohne Änderung dieses Skripts über .env
# setzen (TELERIX_AUDIO_ANHEBUNG_DB, TELERIX_AUDIO_PUFFER_SEKUNDEN), damit
# update.sh nicht an lokalen Änderungen scheitert. Ungültige Werte werden
# ignoriert.
ganzzahl_im_bereich() {
   case "$1" in
      ''|*[!0-9-]*|-|*-*-*|?*-*) return 1 ;;
   esac
   [ "$1" -ge "$2" ] && [ "$1" -le "$3" ]
}
if ganzzahl_im_bereich "${TELERIX_AUDIO_ANHEBUNG_DB:-}" 0 15; then
   ANHEBUNG_DB=$TELERIX_AUDIO_ANHEBUNG_DB
fi
if ganzzahl_im_bereich "${TELERIX_AUDIO_PUFFER_SEKUNDEN:-}" 0 10; then
   PUFFER_SEKUNDEN=$TELERIX_AUDIO_PUFFER_SEKUNDEN
fi

urlfile=/var/lib/telerix/asterisk/stream.url
current_url() { head -n 1 "$urlfile" 2>/dev/null; }
streamurl=$(current_url)

# Alle Kindprozesse beenden (Pipeline, sleep). Asterisk beendet beim Neuladen
# ohnehin die ganze Prozessgruppe; das hier gilt für alle anderen Fälle.
cleanup() { pkill -P $$ 2>/dev/null; }
trap 'cleanup; exit 0' TERM INT HUP
trap cleanup EXIT

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

# Kennlinie des Begrenzers: volle Anhebung bis 3 dB unter der Obergrenze,
# darüber weicher Übergang zur Obergrenze (monoton für 0 bis 15 dB)
begrenzer_kurve=$(awk -v g="$ANHEBUNG_DB" -v o="$OBERGRENZE_DB" \
   'BEGIN {printf "-80,%g,%g,%g,0,%g", -80 + g, o - g - 3, o - 3, o}')

# Puffer: Vorlauf plus 2 s Reserve für Schübe nach einer Stockung.
# Der Vorlauf entsteht einmalig beim Start: Die letzte Stufe wartet
# PUFFER_SEKUNDEN und reicht danach alles durch, mbuffer sammelt solange.
# (mbuffer -P eignet sich nicht: Es hält die Ausgabe jedes Mal an, wenn sein
# interner Puffer leer ist, bis die Schwelle wieder erreicht ist – das
# erzeugte im Test lange Lücken.)
puffer_bytes=$((AUSGABE_RATE * 2 * (PUFFER_SEKUNDEN + 2)))

mpg123 -q --au - -- "$streamurl" 2>/dev/null \
   | sox -q -V1 -t au - -t raw -r "$AUSGABE_RATE" -e signed-integer -b 16 -c 1 - \
        remix - \
        rate -v "$AUSGABE_RATE" \
        highpass "$HOCHPASS_HZ" \
        compand 0.01,0.50 "$LEVELER_KURVE" 0 -90 0.05 \
        compand 0.0001,0.10 "$begrenzer_kurve" 0 -90 0.002 \
   | mbuffer -q -m "$puffer_bytes" -s 640 2>/dev/null \
   | { sleep "$PUFFER_SEKUNDEN"; exec cat; } &
pid=$!

# Solange die Pipeline läuft: bei geänderter Adresse beenden, Asterisk
# startet das Skript dann mit der neuen Adresse neu
while kill -0 "$pid" 2>/dev/null; do
   sleep 2
   if [ "$(current_url)" != "$streamurl" ]; then
      exit 0
   fi
done

echo "telerix-stream: Stream $streamurl nicht erreichbar oder beendet, neuer Versuch in 30 s" >&2
wait_or_change 30
