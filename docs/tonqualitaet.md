# Tonqualität am Telefon

[← zurück zur README](../README.md)

Ab Version 0.05-mod bereitet `asterisk/stream.sh` (im Container `/usr/local/bin/telerix-stream`) den
Internetradio-Stream für das Telefon auf.

## Was passiert mit dem Stream?

```text
Icecast (MP3) ─> mpg123 ─> sox ──────────────────────────────────────────> mbuffer ─> Asterisk
                 dekodiert  Mono · 16 kHz                                   4 s Vorlauf   (slin16)
                 in Original- nur wenn eingestellt: Hochpass · Leveler
                 rate         (Klangprofil) · Lautstärke (bei Anhebung
                              mit Begrenzer auf ca. -3,5 dBFS)
```

**Ab 0.06-mod ist der Standard unbearbeitet:** Lautstärke 0 dB, Klangprofil aus, Hochpass aus. Telerix reicht den
Stream dann unverändert weiter, nur in Mono und 16 kHz (im Test: −25,92 dBFS gegenüber −25,93 dBFS im Stream).
Grund: Gemeinde-Streams sind oft schon gut ausgesteuert. An einem echten Stream mit Liedern (−22 LUFS, natürliche
Dynamik mit 9,9 LU Lautheitsschwankung) drückte die bisherige Standardeinstellung „Sprache“ mit +5 dB die
Schwankung auf 4,9 LU zusammen, und die Lieder klangen am Telefon gepresst. Bis 0.05-mod galt „Sprache“, +5 dB,
Hochpass 180 Hz; wer das zurückhaben will, stellt es unter *Konfiguration → Ton am Telefon* ein.

- **16 kHz statt 8 kHz:** Mit sipgate wird G.722 (16 kHz) ausgehandelt. Bisher kam der Ton nur in 8 kHz an.
  Für Anrufer mit G.711 rechnet Asterisk selbst auf 8 kHz herunter.
- **Saubere Umrechnung:** sox `rate -v` statt des einfachen Verfahrens in mpg123.
- **Hochpass (wählbar):** entfernt Brummen, Trittschall und Rumpeln, die am Telefon nur stören, siehe unten.
- **Leveler (Klangprofil, wählbar):** hebt leise Sprache an und dämpft laute Passagen, damit Predigt und Lesung
  gleichmäßig verständlich sind, siehe unten.
- **Lautstärke (−24 bis +24 dB):** Absenkung einfach um den eingestellten Wert. Eine Anhebung läuft über einen
  Begrenzer, der Spitzen unter etwa −3,5 dBFS hält; +5 dB bringt leise Sprache (−26 dBFS) auf etwa −20 dBFS.
  Die frühere Absenkung um 8 dB (`mpg123 -f 13000`) entfällt.
- **4 s Vorlauf:** Ein Livestream kommt oft schubweise an. Asterisk liest in Echtzeit, ohne Vorrat gäbe es bei
  jeder Stockung einen Aussetzer. Der Ton kommt dadurch 4 s später beim Anrufer an.
- **Neuversuch nach Abbruch (ab 0.06-mod):** Bricht der Stream ab, verbindet Telerix nach 2 s neu, bei weiteren
  Fehlschlägen nach 5, 10 und dann alle 30 s. Bis 0.05-mod wartete es immer 30 s.

## HD oder Telefonqualität?

Wer vom Festnetz oder Handy anruft, hört fast immer Telefonqualität: G.711 mit 8 kHz, nutzbar etwa 300 bis
3400 Hz. Das gilt meist auch für Anrufe mit der App eines anderen Anbieters, weil zwischen den Netzen in der Regel
auf G.711 umgesetzt wird. HD (G.722, etwa 50 bis 7000 Hz) kommt nur an, wenn die ganze Strecke es kann. Die
Einstellungen sollten deshalb vor allem in Telefonqualität gut klingen; dafür gibt es die Hörprobe.

Die Startseite zeigt je Anrufer den Codec zwischen Anbieter und Telerix. „Telefonqualität (G.711)“ heißt: Der
Anrufer hört sicher schmalbandig. „HD (G.722)“ heißt: Breitband kommt bis zum Anbieter an. Ob der Anrufer selbst HD
hört, kann Telerix nicht erkennen, denn der Anbieter kann für ihn wieder auf G.711 umsetzen. Getrennte Einstellungen
für HD und Telefonnetz gibt es deshalb nicht.

## Messwerte

Gemessen mit `tests/sip-test.sh`:

- **Testsignal:** leise, verhallte Sprache mit Grundrauschen, MP3 128 kbit/s, 44,1 kHz, RMS −26,5 dBFS, Spitze
  etwa −7 dBFS. Der Teststream stockt alle 10 s für 2,5 s und liefert danach schubweise nach.
- **Skriptausgabe:** wird wie von Asterisk in Echtzeit gelesen (20-ms-Pakete).
- **Anruf:** ein Testanruf über G.722 über den nachgebildeten Anbieter, der den Anruf aufnimmt.

| | vorher (v0.04-mod) | nachher (v0.05-mod) |
| --- | --- | --- |
| Abtastrate | 8 kHz | 16 kHz |
| Skriptausgabe RMS / Spitze | −34,1 / −15,4 dBFS | −20,1 / −5,1 dBFS |
| Anruf (G.722) RMS / Spitze | −33,7 / −15,0 dBFS | −19,5 / −4,0 dBFS |
| Aussetzer in 40 s bei Stockungen im Stream | 2 Lücken, 2,2 s | keine |
| Verzögerung bis zum Ton | 0 s | 4,0 s |
| 60 s mit 6 Stockungen à 2,5 s | – | keine Aussetzer |

Zum Vergleich eine Messung auf einem Server mit v0.04-mod: RMS −34 dBFS, Spitze −16 dBFS. Das entspricht dem
Vorher-Wert mit dem Testsignal.

Diese Werte gelten für die Aufbereitung von 0.05-mod (Sprache, +5 dB, Hochpass 180 Hz), mit der der SIP-Test
weiterhin misst. Offline an der ganzen Testaufnahme (2:48 min) je nach Anhebung:

| Anhebung | RMS | Spitze |
| --- | --- | --- |
| 0 dB | −25,3 dBFS | −5,2 dBFS |
| 5 dB | −20,3 dBFS | −3,6 dBFS |
| 10 dB | −17,2 dBFS | −3,1 dBFS |
| 15 dB | −15,8 dBFS | −2,7 dBFS |

## Klangprofile und Hochpass (ab 0.06-mod)

| Klangprofil | Wirkung |
| --- | --- |
| **Aus (unbearbeitet)** (Standard) | lässt die Dynamik des Streams unverändert |
| **Sprache** | gleicht stark aus: leise Sprache wird kräftig angehoben, laute gedämpft. Bei Liedern klingt das schnell gepresst. |
| **Sprache und Musik** | gleicht sanfter und langsamer aus, leise und laute Stellen von Gesang und Orgel bleiben unterscheidbar. Sprache kommt etwa gleich laut an. |

Gemessen mit `tests/sip-test.sh` (Anhebung +5 dB, Hochpass 180 Hz). Das Orgel-Testsignal wechselt zwischen
8 s leise (−42 dBFS) und 8 s laut (−24 dBFS), also 18 dB Unterschied wie zwischen piano und forte:

| Klangprofil | Sprache RMS | Orgel leise | Orgel laut | Unterschied leise/laut |
| --- | --- | --- | --- | --- |
| Sprache | −20,2 dBFS | −26,1 dBFS | −17,5 dBFS | 8,7 dB |
| Sprache und Musik | −20,4 dBFS | −30,3 dBFS | −18,2 dBFS | 12,1 dB |
| Aus | −21,9 dBFS | −37,9 dBFS | −19,9 dBFS | 18,0 dB |

„Sprache“ hebt also leise Orgelstellen samt Rauschen um 12 dB an. Ein „Pumpen“ nach kurzen lauten Stößen ist dagegen
bei allen Profilen gering: Der Pegel bricht danach um 1,4 bis 1,8 dB ein und ist nach einer Sekunde fast wieder da.

**Hochpass:** schneidet alles unterhalb der Frequenz ab (aus, 50, 80, 120, 180 oder 250 Hz). Im Telefonnetz kommt
unter etwa 300 Hz ohnehin nichts an. Ohne die tiefen Anteile reagieren Leveler und Begrenzer nur auf die Stimme
statt auf Brummen und Rumpeln. Bei HD-Telefonie (ab etwa 50 Hz) klingt ein niedrigerer Wert wärmer, vor allem bei
Männerstimmen. 250 Hz dämpft den Orgelakkord im Test (131 bis 262 Hz) von −16,9 auf −22,7 dBFS.

**Neuversuch:** Der Teststream bricht jede Verbindung nach 8 s ab. Der Ton fehlt danach 3,6 s (2 s Pause,
Verbinden, Vorlauf), bis 0.05-mod waren es mindestens 30 s.

## Nachjustieren

Im Webinterface unter *Konfiguration → Ton am Telefon*: Lautstärke (Schieberegler −24 bis +24 dB), Klangprofil,
Hochpass und Vorlauf (0 bis 10 s). Gespeicherte Werte gelten nach wenigen Sekunden, auch für laufende Anrufe; beim
Wechsel ist kurz kein Ton zu hören.

**Hörprobe:** Der Knopf *Hörprobe mit diesen Werten* nimmt 20 s des laufenden Streams mit den gewählten Werten auf,
ohne sie zu speichern. Das dauert etwa 20 Sekunden, ein Ladebalken zeigt den Fortschritt, die Seite lädt dabei
nicht neu. Danach stehen zwei Fassungen zum Anhören bereit: *wie im
Telefonnetz* (300 bis 3400 Hz, 8 kHz, G.711 kodiert und wieder dekodiert) und *wie bei HD-Telefonie* (50 bis
7000 Hz, 16 kHz). Für die meisten Zuhörer zählt die erste.

Alternativ über `.env` im Telerix-Ordner, danach `docker compose up -d`. Werte aus `.env` haben Vorrang, die Felder
im Webinterface sind dann gesperrt:

```sh
# Leiser oder lauter in dB (-24 bis 24, Standard 0 = unverändert)
TELERIX_AUDIO_ANHEBUNG_DB=-6
# Mehr Vorlauf bei sehr unruhiger Leitung (0 bis 10 s, Standard 4)
TELERIX_AUDIO_PUFFER_SEKUNDEN=6
```

Weitere Werte (Leveler-Kennlinien, Obergrenze, Wartezeiten beim Neuversuch) stehen kommentiert oben in
`asterisk/stream.sh`.
Änderungen dort gelten erst nach einem Neubau (`docker compose up -d --build`), und `update.sh` bricht danach wegen
lokaler Änderungen ab.

## Hinweise

- **Comfort Noise:** sipgate sendet Comfort Noise (RFC 3389). Asterisk meldet dazu „Comfort noise support
  incomplete“. Das ist harmlos.
- **Eigene Messung:** Die Skriptausgabe lässt sich im Container so mitschneiden (30 s, ohne Asterisk zu stören):

  ```sh
  docker compose exec telerix sh -c 'timeout 30 su -s /bin/sh asterisk -c /usr/local/bin/telerix-stream > /tmp/mitschnitt.raw; sox -t raw -r 16000 -e signed -b 16 -c 1 /tmp/mitschnitt.raw -n stats 2>&1 | grep -E "RMS lev|Pk lev"'
  ```
