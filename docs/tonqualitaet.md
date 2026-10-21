# Tonqualität am Telefon

[← zurück zur README](../README.md)

Ab Version 0.05-mod bereitet `asterisk/stream.sh` (im Container `/usr/local/bin/telerix-stream`) den
Internetradio-Stream für das Telefon auf.

## Was passiert mit dem Stream?

```text
Icecast (MP3) ─> mpg123 ─> sox ──────────────────────────────────────────> mbuffer ─> Asterisk
                 dekodiert  Mono · 16 kHz · Hochpass 180 Hz ·              4 s Vorlauf   (slin16)
                 in Original- Leveler · Anhebung +5 dB mit Begrenzer
                 rate         auf ca. -3,5 dBFS
```

- **16 kHz statt 8 kHz:** Mit sipgate wird G.722 (16 kHz) ausgehandelt. Bisher kam der Ton nur in 8 kHz an.
  Für Anrufer mit G.711 rechnet Asterisk selbst auf 8 kHz herunter.
- **Saubere Umrechnung:** sox `rate -v` statt des einfachen Verfahrens in mpg123.
- **Hochpass 180 Hz:** entfernt Brummen, Trittschall und Rumpeln, die am Telefon nur stören.
- **Leveler:** hebt leise Sprache an und dämpft laute Passagen, damit Predigt und Lesung gleichmäßig verständlich
  sind.
- **Anhebung mit Begrenzer:** bringt den Pegel auf etwa −20 dBFS und hält Spitzen unter etwa −3,5 dBFS.
  Die frühere Absenkung um 8 dB (`mpg123 -f 13000`) entfällt.
- **4 s Vorlauf:** Ein Livestream kommt oft schubweise an. Asterisk liest in Echtzeit, ohne Vorrat gäbe es bei
  jeder Stockung einen Aussetzer. Der Ton kommt dadurch 4 s später beim Anrufer an.

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

Offline an der ganzen Testaufnahme (2:48 min) je nach Anhebung:

| Anhebung | RMS | Spitze |
| --- | --- | --- |
| 0 dB | −25,3 dBFS | −5,2 dBFS |
| **5 dB (Standard)** | **−20,3 dBFS** | **−3,6 dBFS** |
| 10 dB | −17,2 dBFS | −3,1 dBFS |
| 15 dB | −15,8 dBFS | −2,7 dBFS |

## Nachjustieren

Am einfachsten über `.env` im Telerix-Ordner, danach `docker compose up -d`:

```sh
# Lauter oder leiser (0 bis 15, Standard 5)
TELERIX_AUDIO_ANHEBUNG_DB=7
# Mehr Vorlauf bei sehr unruhiger Leitung (0 bis 10 s, Standard 4)
TELERIX_AUDIO_PUFFER_SEKUNDEN=6
```

Weitere Werte (Hochpass, Leveler-Kennlinie, Obergrenze) stehen kommentiert oben in `asterisk/stream.sh`.
Änderungen dort gelten erst nach einem Neubau (`docker compose up -d --build`), und `update.sh` bricht danach wegen
lokaler Änderungen ab.

## Hinweise

- **Comfort Noise:** sipgate sendet Comfort Noise (RFC 3389). Asterisk meldet dazu „Comfort noise support
  incomplete“. Das ist harmlos.
- **Eigene Messung:** Die Skriptausgabe lässt sich im Container so mitschneiden (30 s, ohne Asterisk zu stören):

  ```sh
  docker compose exec telerix sh -c 'timeout 30 su -s /bin/sh asterisk -c /usr/local/bin/telerix-stream > /tmp/mitschnitt.raw; sox -t raw -r 16000 -e signed -b 16 -c 1 /tmp/mitschnitt.raw -n stats 2>&1 | grep -E "RMS lev|Pk lev"'
  ```
