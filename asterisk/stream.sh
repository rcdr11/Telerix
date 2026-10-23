#!/bin/sh
# Wartemusik-Quelle für Asterisk (musiconhold.conf, mode=custom, format=slin16).
#
# Liest die Stream-Adresse aus der vom Webinterface geschriebenen Datei und
# bereitet den MP3-Stream für das Telefon auf:
#
#   mpg123   dekodiert in der Original-Abtastrate (AU-Format, Länge offen);
#            wenn eingestellt, schon hier leiser (Eingangspegel), bevor
#            Werte über 0 dBFS bei der Ausgabe in 16 Bit abgeschnitten werden
#   sox      Mono, saubere Umrechnung auf 16 kHz, dann in dieser Reihenfolge:
#            Hochpass gegen Brummen und Rumpeln, Präsenzanhebung für die
#            Sprachverständlichkeit, Leveler je nach Klangprofil, zuletzt
#            Lautstärke und Begrenzer. Der Begrenzer läuft immer und hält
#            Spitzen unter etwa -3,5 dBFS. Ist die Tonaufbereitung
#            abgeschaltet, nur Mono und 16 kHz.
#   mbuffer  einige Sekunden Vorlauf, damit kurze Stockungen des Streams
#            nicht als Aussetzer beim Anrufer ankommen
#
# Asterisk liest die Ausgabe (signed 16 bit, mono) in Echtzeit und rechnet
# für Anrufer mit G.711 (8 kHz) selbst herunter. Bricht der Stream ab,
# verbindet das Skript nach kurzer, dann wachsender Pause neu.
#
#   telerix-stream --hoerprobe <sekunden> <an|aus> <eingangspegel> <hochpass>
#                  <praesenz> <profil> <lautstaerke> [<rohdatei>]
#      gibt einmalig so viele Sekunden mit den angegebenen Werten aus, ohne
#      Vorlauf (Hörprobe im Webinterface). Mit <rohdatei> (nur
#      /var/lib/telerix/state/.hoerprobe-<hex>.au) landet dort zusätzlich
#      der dekodierte Stream vor der Aufbereitung, zum Messen der Pegel.
#
# Messwerte vorher/nachher: docs/tonqualitaet.md

# ---- Einstellungen ------------------------------------------------------
# Abtastrate der Ausgabe, muss zu format= in musiconhold.conf passen
AUSGABE_RATE=16000
# Tonaufbereitung insgesamt: an, oder aus = nur Mono und 16 kHz; dann gelten
# alle folgenden Einstellungen außer dem Vorlauf nicht, auch der Begrenzer
# nicht
AUFBEREITUNG=an
# Eingangspegel in dB (-12 bis 0): nur Absenkung, für zu laut ausgesteuerte
# Streams. Wirkt schon im Decoder (mpg123 -f), also bevor Werte über 0 dBFS
# abgeschnitten werden, und bevor der Leveler den Pegel bewertet.
EINGANG_DB=0
# Frequenzen darunter werden abgeschnitten (Brummen, Trittschall, Rumpeln);
# 0 = kein Hochpass
HOCHPASS_HZ=0
# Präsenzanhebung für die Sprachverständlichkeit in dB (0 = aus, 2, 3, 4):
# Glockenfilter mit breiter Güte; liegt im Telefonband (300 bis 3400 Hz)
PRAESENZ_DB=0
PRAESENZ_HZ=2800
PRAESENZ_Q=0.9
# Klangprofil: aus (unbearbeitet), sprache oder musik ("Sprache und Musik")
PROFIL=aus
# Leveler (sox compand): Ansprech-,Rückkehrzeit in s, dann Kennlinie
# Eingangspegel -> Ausgangspegel in dB.
# sprache: hebt leise Sprache kräftig an und dämpft laute Passagen.
LEVELER_SPRACHE="0.01,0.50 6:-80,-80,-60,-52,-45,-30,-30,-21,-20,-17,-10,-13,0,-10"
# musik: sanfter und langsamer, leise und laute Stellen von Gesang und Orgel
# bleiben unterscheidbar; Sprache kommt etwa gleich laut an wie bei sprache.
LEVELER_MUSIK="0.2,2.5 6:-80,-80,-60,-55,-45,-36,-30,-25,-20,-19,-10,-14,0,-10"
# Lautstärke in dB (-24 bis +24), 0 = unverändert. Sitzt am Ende der Kette
# direkt vor dem Begrenzer; +5 ergibt bei leiser Sprache (-26 dBFS) etwa
# -20 dBFS RMS, jedes dB mehr hebt den Pegel um etwa 1 dB.
ANHEBUNG_DB=0
# Obergrenze für Spitzen in dBFS (Begrenzer, spricht sofort an). Er
# läuft immer am Ende; unterhalb von 3 dB unter der Obergrenze ändert er
# nichts, bei normal ausgesteuertem Material greift er also nur selten.
# (Mit 0,1 ms Ansprechzeit wie bisher lagen Spitzen bei starker
# Anhebung bis 0,8 dB über der Obergrenze, siehe docs/tonqualitaet.md.)
OBERGRENZE_DB=-3.5
# Reserve in dB: Das Signal wird gleich nach dem Dekodieren um so viel (plus
# die Präsenzanhebung) abgesenkt, damit Umrechnung und Präsenzanhebung in sox
# nicht über 0 dBFS kommen und abgeschnitten werden. Die Leveler-Kennlinien
# werden um denselben Wert verschoben (sie arbeiten also wie ohne Reserve),
# der Begrenzer am Ende gleicht ihn wieder aus.
RESERVE_DB=3
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
# Muss zu telerix/lib/settings.php passen (AUDIO_HIGHPASS_CHOICES,
# AUDIO_PRESENCE_CHOICES, AUDIO_PROFILES, AUDIO_INPUT_MIN/MAX)
gueltiger_hochpass() { case "$1" in 0|50|80|120|180|250) return 0 ;; esac; return 1; }
gueltige_praesenz() { case "$1" in 0|2|3|4) return 0 ;; esac; return 1; }
gueltiges_profil() { case "$1" in sprache|musik|aus) return 0 ;; esac; return 1; }
gueltige_aufbereitung() { case "$1" in an|aus) return 0 ;; esac; return 1; }

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
wert=$(audio_wert PRAESENZ_DB)
gueltige_praesenz "$wert" && PRAESENZ_DB=$wert
wert=$(audio_wert EINGANG_DB)
ganzzahl_im_bereich "$wert" -12 0 && EINGANG_DB=$wert
wert=$(audio_wert AUFBEREITUNG)
gueltige_aufbereitung "$wert" && AUFBEREITUNG=$wert

hoerprobe=""
rohdatei=""
if [ "${1:-}" = "--hoerprobe" ]; then
   if ! ganzzahl_im_bereich "${2:-}" 1 60 || ! gueltige_aufbereitung "${3:-}" \
         || ! ganzzahl_im_bereich "${4:-}" -12 0 || ! gueltiger_hochpass "${5:-}" \
         || ! gueltige_praesenz "${6:-}" || ! gueltiges_profil "${7:-}" \
         || ! ganzzahl_im_bereich "${8:-}" -24 24; then
      echo "Aufruf: telerix-stream --hoerprobe <sekunden 1-60> <an|aus> <eingangspegel -12 bis 0> <hochpass 0|50|80|120|180|250> <praesenz 0|2|3|4> <sprache|musik|aus> <lautstaerke -24 bis 24> [<rohdatei>]" >&2
      exit 2
   fi
   hoerprobe=$2 AUFBEREITUNG=$3 EINGANG_DB=$4 HOCHPASS_HZ=$5 PRAESENZ_DB=$6 PROFIL=$7 ANHEBUNG_DB=$8
   # Rohdatei nur im Arbeitsverzeichnis der Hörprobe, mit zufälligem Namen
   if [ -n "${9:-}" ]; then
      name=${9#/var/lib/telerix/state/.hoerprobe-}
      name=${name%.au}
      case "$9" in
         /var/lib/telerix/state/.hoerprobe-*.au) ;;
         *) name="" ;;
      esac
      case "$name" in
         ''|*[!0-9a-f]*) echo "Ungültige Rohdatei für die Hörprobe" >&2; exit 2 ;;
      esac
      rohdatei=$9
   fi
fi

urlfile=/var/lib/telerix/asterisk/stream.url
current_url() { head -n 1 "$urlfile" 2>/dev/null; }
streamurl=$(current_url)

case "$streamurl" in
   http://*|https://*) stream_ok=1 ;;
   *) stream_ok="" ;;
esac

# Aufbereitung als sox-Effekte (Wörter durch Leerzeichen getrennt; set -f,
# damit nichts als Dateimuster gelesen wird). "vorher" steht vor der
# Umrechnung auf 16 kHz, "effekte" danach.
set -f
skala=""
vorher=""
effekte=""
if [ "$AUFBEREITUNG" = an ]; then
   # Eingangspegel: Skalierung im Decoder (32768 = unverändert)
   [ "$EINGANG_DB" -lt 0 ] && skala="-f $(awk -v e="$EINGANG_DB" 'BEGIN {printf "%d", 32768 * 10 ^ (e / 20) + 0.5}')"

   reserve=$((RESERVE_DB + PRAESENZ_DB))
   vorher="gain -$reserve"
   [ "$HOCHPASS_HZ" -gt 0 ] && effekte="highpass $HOCHPASS_HZ"
   [ "$PRAESENZ_DB" -gt 0 ] && effekte="$effekte equalizer $PRAESENZ_HZ ${PRAESENZ_Q}q +$PRAESENZ_DB"

   # Leveler-Kennlinie um die Reserve verschoben (Ein- und Ausgangspegel),
   # damit er so stark komprimiert wie ohne Reserve
   leveler() {
      echo "$1" | awk -v r="$reserve" '{
         split($2, k, ":"); n = split(k[2], p, ","); s = ""
         for (i = 1; i <= n; i++) s = s (i > 1 ? "," : "") (p[i] - r)
         print $1, k[1] ":" s }'
   }
   case "$PROFIL" in
      sprache) effekte="$effekte compand $(leveler "$LEVELER_SPRACHE") 0 -90 0.05" ;;
      musik)   effekte="$effekte compand $(leveler "$LEVELER_MUSIK") 0 -90 0.05" ;;
   esac

   # Lautstärke und Begrenzer, gemeinsam mit dem Ausgleich der Reserve.
   # Kennlinie für eine Verstärkung g > 0: volle Verstärkung bis 3 dB unter
   # der Obergrenze, darüber weicher Übergang zur Obergrenze (monoton für
   # 1 bis 48 dB). Ist insgesamt abzusenken, erst einfach absenken, dann
   # der Begrenzer ohne Verstärkung (kann dann nur noch Spitzen kappen).
   kurve() {
      awk -v g="$1" -v o="$OBERGRENZE_DB" \
         'BEGIN {printf "-80,%g,%g,%g,0,%g", -80 + g, o - g - 3, o - 3, o}'
   }
   gesamt=$((ANHEBUNG_DB + reserve))
   if [ "$gesamt" -gt 0 ]; then
      effekte="$effekte compand 0,0.10 $(kurve "$gesamt") 0 -90 0.002"
   else
      [ "$gesamt" -lt 0 ] && effekte="$effekte gain $gesamt"
      effekte="$effekte compand 0,0.10 $(kurve 0) 0 -90 0.002"
   fi
fi

dekodieren() { mpg123 -q $skala --au - -- "$streamurl" 2>/dev/null; }

aufbereiten() {
   if [ -n "$rohdatei" ]; then
      dekodieren | { umask 077; tee "$rohdatei"; }
   else
      dekodieren
   fi \
      | sox -q -V1 -t au - -t raw -r "$AUSGABE_RATE" -e signed-integer -b 16 -c 1 - \
           remix - \
           $vorher \
           rate -v "$AUSGABE_RATE" \
           $effekte
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
   # Danach tee beenden: Er schreibt sonst weiter in die Rohdatei, solange der
   # Stream läuft, wenn SIGPIPE ignoriert wird (so startet PHP Programme);
   # mpg123 endet dann von selbst. Der Name der Rohdatei ist eindeutig.
   aufbereiten | {
      head -c $((AUSGABE_RATE * 2 * hoerprobe))
      [ -n "$rohdatei" ] && pkill -x -f "tee $rohdatei"
   }
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
