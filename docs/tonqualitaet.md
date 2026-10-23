# Tonqualität am Telefon

[← zurück zur README](../README.md)

Ab Version 0.05-mod bereitet `asterisk/stream.sh` (im Container `/usr/local/bin/telerix-stream`) den
Internetradio-Stream für das Telefon auf.

## Was passiert mit dem Stream?

```text
Icecast (MP3) ─> mpg123 ──────> sox ─────────────────────────────────────────────────> mbuffer ─> Asterisk
                 dekodiert      Mono · 16 kHz, dann in dieser Reihenfolge:             4 s Vorlauf   (slin16)
                 [Eingangs-     Hochpass · Präsenz · Leveler (Klangprofil) ·
                  pegel]        Lautstärke · Begrenzer (immer, ca. -3,5 dBFS)
```

Die Reihenfolge folgt der üblichen Regel für eine Tonkette: erst der Eingangspegel, dann der Klang (Hochpass,
Präsenz), dann die Dynamik (Leveler), dann die Gesamtlautstärke und ganz am Schluss der Begrenzer. Danach kommen nur
noch Umrechnung und Kodierung für den Anrufer, die nichts mehr lauter machen.

**Standard (ab 0.06-mod):** Alles aus bis auf den Begrenzer. Lautstärke 0 dB, Klangprofil, Hochpass, Präsenz und
Eingangspegel aus. Telerix reicht den Stream dann in Mono und 16 kHz weiter, der Begrenzer kappt nur seltene
Spitzen über etwa −3,5 dBFS (gemessen: Sprach-Testsignal unverändert, siehe [Begrenzer](#begrenzer)). Grund:
Gemeinde-Streams sind oft schon gut ausgesteuert. An einem echten Stream mit Liedern (−22 LUFS, natürliche Dynamik
mit 9,9 LU Lautheitsschwankung) drückte die bisherige Standardeinstellung „Sprache“ mit +5 dB die Schwankung auf
4,9 LU zusammen, und die Lieder klangen am Telefon gepresst. Bis 0.05-mod galt „Sprache“, +5 dB, Hochpass 180 Hz;
wer das zurückhaben will, stellt es unter *Konfiguration → Ton am Telefon* ein.

**Tonaufbereitung ganz aus:** Mit dem Hauptschalter *Tonaufbereitung* (Häkchen weg) entfällt alles, auch der
Begrenzer und der Eingangspegel. Telerix reicht den Stream dann wie mit dem bisherigen Standard „unbearbeitet“
weiter, nur in Mono und 16 kHz (im Test: −25,93 dBFS wie im Stream), und die eigene Begrüßungsansage wird nur umgewandelt. Die
übrigen Einstellungen bleiben gespeichert und gelten wieder, sobald das Häkchen gesetzt ist.

- **16 kHz statt 8 kHz:** Mit sipgate wird G.722 (16 kHz) ausgehandelt. Bisher kam der Ton nur in 8 kHz an.
  Für Anrufer mit G.711 rechnet Asterisk selbst auf 8 kHz herunter, im unkomprimierten Signal und danach mit genau
  einer Kodierung (siehe [Prüfung im SIP-Test](#prüfung-im-sip-test)).
- **Saubere Umrechnung:** sox `rate -v` statt des einfachen Verfahrens in mpg123.
- **Eingangspegel (wählbar, nur Absenkung):** für zu laut ausgesteuerte Streams, siehe
  [Eingangspegel](#eingangspegel).
- **Hochpass (wählbar):** entfernt Brummen, Trittschall und Rumpeln, die am Telefon nur stören, siehe unten.
- **Präsenz (wählbar):** hebt den Bereich um 2,8 kHz an, in dem die Konsonanten liegen, siehe
  [Präsenz](#präsenz-sprachverständlichkeit).
- **Leveler (Klangprofil, wählbar):** hebt leise Sprache an und dämpft laute Passagen, damit Predigt und Lesung
  gleichmäßig verständlich sind, siehe unten.
- **Lautstärke (−24 bis +24 dB):** die Gesamtlautstärke, direkt vor dem Begrenzer. +5 dB bringen leise Sprache
  (−26 dBFS) auf etwa −20 dBFS. Die frühere Absenkung um 8 dB (`mpg123 -f 13000`) entfällt.
- **Begrenzer (immer):** harte Obergrenze für Spitzen, ein Sicherheitsnetz, siehe [Begrenzer](#begrenzer).
- **4 s Vorlauf:** Ein Livestream kommt oft schubweise an. Asterisk liest in Echtzeit, ohne Vorrat gäbe es bei
  jeder Stockung einen Aussetzer. Der Ton kommt dadurch 4 s später beim Anrufer an.
- **Neuversuch nach Abbruch (ab 0.06-mod):** Bricht der Stream ab, verbindet Telerix nach 2 s neu, bei weiteren
  Fehlschlägen nach 5, 10 und dann alle 30 s. Bis 0.05-mod wartete es immer 30 s.

**Leveler, Begrenzer und Lautstärke sind drei verschiedene Dinge.** Der Leveler (Klangprofil) verringert die
Dynamik innerhalb des Signals: Leise Stellen werden lauter, laute leiser. Der Begrenzer ist nur eine harte Obergrenze
für Spitzen. Die Lautstärke verschiebt alles gleichmäßig. Sie sitzt am Ende, damit sie nicht verändert, wie stark der
Leveler arbeitet: Der Leveler bewertet den Pegel nach festen Schwellen, und wer vorne lauter dreht, bekommt mehr
Kompression und einen anderen Klang. Deshalb ist auch der Eingangspegel kein zweiter Lautstärke-Regler.

## Empfehlungen für Gottesdienste

Kurz zusammengefasst, was sich für Predigt, Lieder, Orgel und Chor bewährt. Alles lässt sich vor dem Speichern mit
der Hörprobe anhören; maßgeblich ist die Fassung „wie im Telefonnetz“, denn so hören es die meisten Anrufer.

- **Klangprofil:** Bei Gottesdiensten mit Orgel, Chor oder leisen Passagen „Sprache und Musik“ statt „Aus“
  ausprobieren. Mobilfunk und manche Netze erkennen sehr leise Musik als Pause und ersetzen sie durch Rauschen oder
  Stille. „Sprache und Musik“ hebt leise Stellen etwas an, ohne Lieder zu pressen.
- **Hochpass:** Für reine Sprache etwa 120 Hz, mit Musik eher 80 Hz.
- **Sprachverständlichkeit (Präsenz):** +2 bis +3 dB, wenn die Predigt dumpf klingt. Bei Geigen, Bläsern und Chor
  kann es scharf klingen, deshalb mit der Hörprobe prüfen.
- **Lautstärke:** Lieber mit der Hörprobe prüfen als nach Gefühl anheben. Sie zeigt die gemessenen Pegel: Sprache
  ist gut, wenn sie etwa zwischen −26 und −20 dBFS RMS liegt, Spitzen sollten unter etwa −3 dBFS bleiben. Lauter ist
  am Telefon nicht besser: G.711 verzerrt bei Übersteuerung, und viele Telefone regeln selbst herunter.
- **Was die Regler tun:** Der Leveler (Klangprofil) gleicht leise und laute Stellen aneinander an. Der Begrenzer
  fängt nur die höchsten Spitzen ab. Die Lautstärke macht alles gleichmäßig lauter oder leiser und sitzt am Ende der
  Kette, damit sie nicht verändert, wie der Leveler arbeitet.
- **Gleichmäßige Lautheit:** Predigt, Lieder und Ansage sollen etwa gleich laut ankommen, damit niemand am Telefon
  nachregeln muss. Die eigene Ansage bringt Telerix deshalb auf einen festen Sprachpegel (−23 dBFS), der zu einem
  normal ausgesteuerten Stream passt. Klingt die Ansage deutlich lauter oder leiser als die Predigt, mit der
  Lautstärke den Stream angleichen.
- **Eingangspegel:** Nur anfassen, wenn die Hörprobe beim Stream eine Spitze von 0 dBFS meldet.
- **Testanrufe vor dem ersten Einsatz:** Vom Festnetz anrufen und von Handys im Telekom-, Vodafone- und o2-Netz, mit
  und ohne VoLTE bzw. WLAN-Call. Auf der Startseite steht dabei der Codec jedes Anrufers. Mit sipgate kommt HD meist
  nur bei Anrufen aus dem sipgate-Netz wirklich beim Anrufer an; die Einstellungen müssen deshalb in
  Telefonqualität gut klingen.

### Was auf der Senderseite hilft

Viel lässt sich schon beim Stream selbst verbessern, bevor Telerix ihn bekommt:

- **Ton elektrisch abnehmen:** vom Mischpult, nicht über ein Mikrofon vor dem Lautsprecher.
- **Encoder ohne Automatik:** automatische Pegelregelung und Rauschunterdrückung im Streaming-Encoder ausschalten.
  Sie arbeiten gegen den Leveler und machen Musik unruhig.
- **Mono-taugliche Mischung:** Am Telefon kommt nur Mono an. Was nur auf einer Seite liegt, wird leiser, und Effekte,
  die mit Stereo-Phasen arbeiten, können sich auslöschen.
- **Wenig Hall:** Hall macht Sprache am Telefon schnell undeutlich.
- **MP3 mit mindestens 128 kbit/s:** Der MP3-Stream ist die erste verlustbehaftete Stufe, zum Anrufer kommen G.722
  oder G.711 und im Mobilfunk oft noch eine weitere Kodierung dazu. Telerix zeigt Bitrate, Abtastrate und Kanäle bei
  *Konfiguration → Internetstream* und auf der Startseite und weist unter 128 kbit/s darauf hin.

## HD oder Telefonqualität?

Wer vom Festnetz oder Handy anruft, hört fast immer Telefonqualität: G.711 mit 8 kHz, nutzbar etwa 300 bis
3400 Hz. Das gilt meist auch für Anrufe mit der App eines anderen Anbieters, weil zwischen den Netzen in der Regel
auf G.711 umgesetzt wird. HD (G.722, etwa 50 bis 7000 Hz) kommt nur an, wenn die ganze Strecke es kann. Die
Einstellungen sollten deshalb vor allem in Telefonqualität gut klingen; dafür gibt es die Hörprobe.

Die Startseite zeigt je Anrufer den Codec zwischen Anbieter und Telerix. „Telefonqualität (G.711)“ heißt: Der
Anrufer hört sicher schmalbandig. „HD (G.722)“ heißt: Breitband kommt bis zum Anbieter an. Ob der Anrufer selbst HD
hört, kann Telerix nicht erkennen, denn der Anbieter kann für ihn wieder auf G.711 umsetzen.

**Testergebnis (Oktober 2026):** Ein Anruf von einem Handy mit ausgeschaltetem VoLTE (also über 2G, ohne HD) erschien
auf der Startseite als „HD (G.722)“. sipgate bietet G.722 also auch für Anrufer an, die kein HD haben, und rechnet für
sie selbst auf G.711 um. Dann wird zweimal verlustbehaftet kodiert: Telerix kodiert G.722, sipgate dekodiert und
kodiert G.711. Bei G.722 mit 64 kbit/s kostet das wenig, ist aber vermeidbar.

**Einstellung *Codec* (ab 0.06-mod)** unter *Konfiguration → Ton am Telefon*:

| Codec | Wirkung |
| --- | --- |
| **HD bevorzugen (G.722)** (Standard) | wie bisher. Bietet sipgate G.722 an, wird G.722 genommen. Anrufer, bei denen HD bis zum Telefon durchkommt, hören HD; für alle anderen rechnet sipgate auf G.711 um. |
| **Telefonqualität bevorzugen (G.711)** | Telerix antwortet mit G.711 (A-law vor µ-law), auch wenn sipgate G.722 zuerst anbietet. Die Umrechnung bei sipgate entfällt. Anrufer, bei denen HD wirklich durchkäme (meist nur aus dem sipgate-Netz), hören dann kein HD mehr. |

Bei sipgate kommen die meisten Anrufe aus Festnetz und Mobilfunk ohnehin nur in Telefonqualität an. „Telefonqualität
bevorzugen“ spart dort eine Umrechnung beim Anbieter. Die Hörprobe „wie im Telefonnetz“ entspricht dann genau dem, was
diese Anrufer hören; bei „HD bevorzugen“ kommt für Festnetz und Handy noch die Umrechnung bei sipgate dazu.

Die Einstellung gilt ohne Neustart ab dem nächsten Anruf: Telerix schreibt die Trunk-Datei neu und lädt PJSIP neu,
laufende Anrufe bleiben dabei bestehen (geprüft im SIP-Test, ohne Aussetzer). Bietet sipgate nur G.722 an, kommt der
Anruf auch bei „Telefonqualität bevorzugen“ mit G.722 zustande.

**Wie Asterisk den Codec wählt:** In Asterisk 22 entscheidet bei eingehenden Anrufen `incoming_call_offer_pref`. Der
Standard ist `local`: Die Reihenfolge in `allow=` von Telerix zählt, nicht die im Angebot von sipgate (Quelle: die
Konfigurationsdokumentation und `res_pjsip/pjsip_configuration.c` von Asterisk 22). Bei „HD bevorzugen“ steht G.722
vorn, deshalb wurde bisher immer G.722 gewählt, sobald sipgate es überhaupt anbietet. „Telefonqualität bevorzugen“
stellt A-law und µ-law nach vorn und setzt `incoming_call_offer_pref=local_first`, damit die Antwort nur den einen
gewählten Codec nennt. Eine Option `incoming_offer_codec_prefs` gibt es für Endpunkte nicht; die ähnlich klingenden
`codec_prefs_*`-Einträge in der Dokumentation sind „für künftige Funktionen reserviert“.

**Diagnose im Protokoll:** Bei jedem Anruf schreibt Telerix eine Zeile ins Protokoll (`docker compose logs telerix`),
ohne Rufnummer:

```text
NOTICE[…] Ext. 200: Codec: angeboten und unterstützt (g722|alaw), gewählt (g722)
```

„angeboten und unterstützt“ sind die Codecs, die sipgate angeboten hat und die Telerix ebenfalls kann, in der
Reihenfolge von Telerix. Die Reihenfolge im Angebot von sipgate selbst ist im Dialplan nicht zugänglich; für die Wahl
spielt sie wegen `local` auch keine Rolle. Bei „Telefonqualität bevorzugen“ steht dort wegen `local_first` nur der
eine gewählte Codec. Ein Anruf vom Handy ohne VoLTE sollte bei „HD bevorzugen“ `(g722|alaw)` und `gewählt (g722)`
zeigen, bei „Telefonqualität bevorzugen“ `gewählt (alaw)`.

**Opus:** Die Codec-Liste bleibt `g722,alaw,ulaw,gsm`. Die sipgate-Hilfe nennt Opus zwar unter den unterstützten
Codecs, ordnet ihn aber ausdrücklich satellite und der sipgate App zu, nicht SIP-Trunks
([Was sind Codecs?](https://help.sipgate.de/cloud-telefonanlage/sipgate-nutzen/telefonie/was-sind-codecs), Stand
Oktober 2026). Ohne klaren Beleg für den Trunk baut Telerix Opus nicht ein.

## Begrüßungsansage

Eine eigene Ansage (*Ansage*) speichert Telerix ab 0.06-mod in zwei Fassungen, beide mit sox `rate -v` aus dem
hochgeladenen Original:

| Datei | für | Weg in Asterisk |
| --- | --- | --- |
| `sounds/ansage.wav16` (16 kHz, Mono) | HD-Anrufer (G.722) | direkt kodiert, `(slin@16000)->(g722@16000)` |
| `sounds/ansage.wav` (8 kHz, Mono) | Anrufer mit G.711 | direkt kodiert, `(slin@8000)->(alaw@8000)` |

Asterisk wählt beim Abspielen von `ansage` je Anrufer die Datei mit dem kürzesten Umrechnungsweg; beide Wege sind im
SIP-Test belegt. Eine 16-kHz-Datei muss `.wav16` heißen, unter `.wav` erwartet Asterisk 8 kHz. Hat das Original
weniger als 16 kHz, entsteht nur die 8-kHz-Fassung; künstlich hochgerechnet wird nichts. Die mitgelieferte Ansage
`ansagen/willkommen-gottesdienst.gsm` ist GSM-kodiert, also schon verlustbehaftet und schmalbandig. Sie bleibt,
wie sie ist; hochgerechnet wird nichts. Beim Abspielen wird sie allerdings aus GSM dekodiert und neu in G.722 bzw.
G.711 kodiert, also zweimal verlustbehaftet. Besser wird das nur mit einer Originalaufnahme in voller Qualität, die
als eigene Ansage hochgeladen wird.

**Pegel:** Ist die Tonaufbereitung an, gelten Hochpass und Präsenz wie beim Stream. Dann bringt Telerix die Ansage
auf einen festen Sprachpegel von −23 dBFS RMS, gemessen ohne Pausen, und begrenzt Spitzen wie beim Stream auf etwa
−3,5 dBFS. Gemessen wird in Blöcken von 400 ms; Blöcke, die mehr als 10 dB unter dem Mittel liegen (Pausen), zählen
nicht. Das ist die Torschaltung der Lautheitsmessung nach EBU R 128, aber ohne Frequenzbewertung. **Warum −23 dBFS:**
Der echte Gemeinde-Stream aus der Messung oben lag bei −22 LUFS. Das Sprach-Testsignal des SIP-Tests (−26,5 dBFS
einschließlich Pausen) liegt auf dieselbe Weise gemessen bei −25,4 dBFS. −23 dBFS liegt dazwischen und in der Mitte
des Zielbereichs für Sprache am Telefon (−26 bis −20 dBFS). Die Lautstärke-Einstellung wirkt bewusst nicht auf die
Ansage; ein fester Pegel bleibt auch dann richtig, wenn jemand die Lautstärke für einen leisen Stream anhebt, nur
sollte sie dann zur Ansage passen.

Gemessen an Testaufnahmen (Sprachpegel ohne Pausen, RMS über die ganze Datei, Spitze):

| Original | Original | Ansage 16 kHz | Ansage 8 kHz |
| --- | --- | --- | --- |
| Sprach-Testsignal, MP3 44,1 kHz | −25,4 dBFS (RMS −26,5, Spitze −6,7) | −23,0 dBFS (RMS −24,0, Spitze −5,9) | −23,0 dBFS |
| dieselbe Sprache, bis −0,1 dBFS ausgesteuert | −19,4 dBFS (Spitze −0,7) | −23,0 dBFS (Spitze −5,4) | −23,0 dBFS |
| übersteuerter Stream mit Orgel (Sägezahn) | −11,0 dBFS (Spitze 0,0) | −23,0 dBFS (Spitze −12,0) | −23,0 dBFS |
| Sprache in 8 kHz (GSM-Quelle) | −19,2 dBFS (Spitze −0,3) | – (nur 8 kHz) | −23,0 dBFS (Spitze −5,3) |
| Tonaufbereitung aus, Sprach-Testsignal | −25,4 dBFS | −25,4 dBFS (unverändert) | −25,5 dBFS |

**Neu erzeugt** wird die Ansage beim Hochladen, beim Speichern der Toneinstellungen (Hauptschalter, Hochpass,
Präsenz) und beim Containerstart, aber nur wenn sich etwas geändert hat. Grundlage ist `uploads/audio/quelle.wav`:
das Original in Mono und 16 kHz (bzw. 8 kHz), sonst unbearbeitet. Sie steht auch in der Sicherung, damit nach dem
Einspielen wieder beide Fassungen entstehen.

**Ältere Installationen:** Bis 0.05-mod gab es nur eine 8-kHz-`ansage.wav`. Liegt das Original noch in
`uploads/audio`, erzeugt Telerix beim ersten Start nach dem Update beide Fassungen daraus. Fehlt es, bleibt die alte
Datei in Gebrauch. Sicherungen aus 0.05-mod enthalten die fertige 8-kHz-Ansage; beim Einspielen entsteht daraus nur
die 8-kHz-Fassung.

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
weiterhin misst. Offline an der ganzen Testaufnahme (2:48 min) je nach Anhebung, mit dem Begrenzer bis 0.05-mod
(Ansprechzeit 0,1 ms, siehe [Begrenzer](#begrenzer)):

| Anhebung | RMS | Spitze |
| --- | --- | --- |
| 0 dB | −25,3 dBFS | −5,2 dBFS |
| 5 dB | −20,3 dBFS | −3,6 dBFS |
| 10 dB | −17,2 dBFS | −3,1 dBFS |
| 15 dB | −15,8 dBFS | −2,7 dBFS |

## Begrenzer

> **Messumgebung der Abschnitte Begrenzer, Präsenz, Eingangspegel, Begrüßungsansage und Prüfung im SIP-Test:**
> Die Werte für 0.06-mod sind mit sox 14.4.2, mpg123 1.32.5 und Asterisk 20.6 außerhalb des Containers gemessen
> (Ubuntu 24.04), weil sich das Image in der Entwicklungsumgebung nicht bauen ließ. Im Container laufen sox 14.4,
> mpg123 1.33 und Asterisk 22; `tests/sip-test.sh` prüft dort dieselben Grenzen.

Ab 0.06-mod läuft der Begrenzer immer am Ende der Kette, nicht mehr nur bei einer Anhebung. Er ist ein
Sicherheitsnetz: Unterhalb von 3 dB unter der Obergrenze (also bis etwa −6,5 dBFS) ändert er nichts, darüber führt er
weich zur Obergrenze von etwa −3,5 dBFS. Ohne ihn gingen Spitzen eines heiß ausgesteuerten Streams bei 0 dB
unverändert bis 0 dBFS durch, und G.711 verzerrt bei Übersteuerung.

**Übersteuern in sox:** sox rechnet intern mit 32-Bit-Ganzzahlen und schneidet nach jedem Effekt bei 0 dBFS ab. Die
Warnungen dazu blendet `-V1` aus. Gemessen an 20 s eines übersteuerten Streams (Sprache mit Orgel, auf 0 dBFS
ausgesteuert): `rate -v` schnitt 48 Werte ab, eine Präsenzanhebung von +4 dB direkt danach 426. Deshalb senkt die
Kette den Ton gleich nach dem Dekodieren um 3 dB plus die Präsenzanhebung ab (Reserve). Die Kennlinien des Levelers
sind um denselben Wert verschoben, er arbeitet also wie ohne Reserve; der Begrenzer gleicht die Reserve am Ende wieder
aus. Mit eingeschalteter Aufbereitung wurde in keinem der Tests mehr ein Wert abgeschnitten.

**Ansprechzeit:** Bis 0.05-mod hatte der Begrenzer 0,1 ms Ansprechzeit. Bei 16 kHz ist das länger als ein
Abtastwert, steile Spitzen rutschten deshalb durch: Bei starker Anhebung lagen sie bis 0,8 dB über der Obergrenze
(Tabelle oben: −2,7 dBFS bei +15 dB). Jetzt spricht er sofort an. Gemessen offline an der ganzen Testaufnahme
(Sprache, Reserve 3 dB, Verstärkung g einschließlich Reserve):

| | Ansprechzeit 0,1 ms: RMS / Spitze | sofort: RMS / Spitze |
| --- | --- | --- |
| Sprach-Testsignal, g = 3 dB | −26,45 / −6,73 dBFS | −26,45 / −6,73 dBFS |
| Sprach-Testsignal, g = 8 dB | −21,55 / −4,39 dBFS | −21,58 / −5,41 dBFS |
| Sprach-Testsignal, g = 15 dB | −17,38 / −3,42 dBFS | −17,55 / −4,75 dBFS |
| übersteuerter Stream, g = 3 dB | −14,00 / −3,44 dBFS | −14,23 / −4,34 dBFS |
| übersteuerter Stream, g = 15 dB | −13,15 / −2,55 dBFS | −13,43 / −3,78 dBFS |

**Wirkung beim Standard** (0 dB, alles andere aus), an der ganzen Testaufnahme (2:48 min, 2,7 Mio. Abtastwerte):

| Material | ohne Begrenzer: RMS / Spitze | mit Begrenzer: RMS / Spitze | Werte über −6,5 dBFS |
| --- | --- | --- | --- |
| Sprach-Testsignal (MP3, −26,5 dBFS) | −26,45 / −6,73 dBFS | −26,45 / −6,73 dBFS | 0 |
| dieselbe Sprache, Spitzen auf −0,3 dBFS ausgesteuert | −20,62 / −0,92 dBFS | −20,85 / −4,65 dBFS | 4592 (0,17 %) |

Bei normal ausgesteuertem Material bleibt also alles, wie es ist. Bei einem heißen Stream fängt der Begrenzer die
Spitzen ab und senkt den RMS-Wert um 0,2 dB.

## Präsenz (Sprachverständlichkeit)

Neu in 0.06-mod: *Sprachverständlichkeit (Präsenz)* mit aus (Standard), +2, +3 oder +4 dB. Ein Glockenfilter
(`sox equalizer`) bei 2,8 kHz mit breiter Güte (Q 0,9) hebt den Bereich an, in dem Konsonanten liegen. Er liegt im
Telefonband (300 bis 3400 Hz) und wirkt deshalb auch bei G.711. In der Kette sitzt er nach dem Hochpass und vor dem
Leveler. Er hilft vor allem bei Sprache; Geigen, Bläser und Chor können dadurch scharf klingen, deshalb mit der
Hörprobe prüfen.

Übersteuern kann die Anhebung nicht, auch nicht bei 0 dB Lautstärke: Die Reserve wächst um die Präsenzanhebung, und
der Begrenzer läuft ohnehin immer. Gemessen am übersteuerten Stream (20 s, Hörprobe-Modus):

| Einstellung | Spitze | Werte mit Vollausschlag |
| --- | --- | --- |
| Tonaufbereitung aus | 0,00 dBFS | 23 |
| Standard (nur Begrenzer) | −4,30 dBFS | 0 |
| Präsenz +4 dB, Lautstärke 0 dB | −4,57 dBFS | 0 |
| Präsenz +4 dB, Sprache, Hochpass 180 Hz, +5 dB | −4,89 dBFS | 0 |
| Präsenz +4 dB, Lautstärke +24 dB | −3,85 dBFS | 0 |

## Eingangspegel

Neu in 0.06-mod: *Eingangspegel (nur bei zu lautem Stream)*, −12 bis 0 dB in 1-dB-Schritten, Standard 0 dB. Im
Webinterface steht er abgesetzt vom Lautstärke-Regler, denn er ist kein zweiter Lautstärke-Regler:

- Die Kennlinien des Levelers arbeiten mit festen Schwellen. Ist der Stream zu heiß, komprimieren sie stärker als
  vorgesehen; das kann die Lautstärke am Ende nicht reparieren.
- MP3 erzeugt beim Dekodieren Werte über 0 dBFS, die mpg123 bei der Ausgabe in 16 Bit hart abschneidet.

Deshalb wirkt er im Decoder: mpg123 skaliert mit `-f` (32768 = unverändert), bevor es in 16 Bit ausgibt. Geprüft
wurde auch die Gleitkomma-Ausgabe von mpg123. Sie ist für einen Stream nicht brauchbar: Das AU-Format kann kein
Gleitkomma, eine Gleitkomma-WAV über eine Pipe liest sox als leer (Länge 0 im Kopf), rohe Gleitkommawerte bräuchten
Abtastrate und Kanalzahl im Voraus, und sox `-v` wirkt erst, nachdem sox beim Einlesen bei 0 dBFS abgeschnitten hat.

Belegt mit dem übersteuerten Test-Stream (MP3 128 kbit/s, ganze Datei):

| Eingangspegel | Spitze nach mpg123 | Werte mit Vollausschlag |
| --- | --- | --- |
| 0 dB | 0,00 dBFS | 432 (abgeschnitten) |
| −3 dB | −2,39 dBFS | 2 (das ist die Spitze selbst) |
| −6 dB | −5,41 dBFS | 2 |

Die echte Spitze des Streams liegt also bei etwa +0,6 dBFS. Die Hörprobe zeigt die Spitze des Streams mit
herausgerechnetem Eingangspegel; erreicht sie bei 0 dB genau 0 dBFS, weist sie auf den Eingangspegel hin.

## Prüfung im SIP-Test

`tests/sip-test.sh` ruft über den nachgebildeten Anbieter an, der G.722 und G.711 (A-law) in dieser Reihenfolge
anbietet, und liest die Formate des Kanals bei Telerix aus (`core show channel`, Zeile `WriteTranscode`):

| Codec-Einstellung | Was läuft | gewählt | Weg in Asterisk |
| --- | --- | --- | --- |
| HD bevorzugen | eigene Ansage | G.722 | `(slin@16000)->(g722@16000)` |
| HD bevorzugen | Wartemusik (Stream) | G.722 | `(slin@16000)->(g722@16000)` |
| Telefonqualität bevorzugen | eigene Ansage | A-law | `(slin@8000)->(alaw@8000)` |
| Telefonqualität bevorzugen | Wartemusik (Stream) | A-law | `(slin@16000)->(slin@8000)->(alaw@8000)` |
| Telefonqualität bevorzugen, Angebot nur G.722 | Wartemusik | G.722 | `(slin@16000)->(g722@16000)` |
| HD bevorzugen | mitgelieferte Ansage (GSM) | G.722 | `(gsm@8000)->(slin@8000)->(g722@16000)` |

G.722-Anrufer bekommen den Stream also ohne Umweg über 8 kHz. Für G.711 rechnet Asterisk die Wartemusik einmal im
unkomprimierten Signal auf 8 kHz herunter (Speex-Resampler, Qualitätsstufe 5 laut `codecs/codec_resample.c` in
Asterisk 22) und kodiert dann genau einmal; zwei verlustbehaftete Kodierungen hintereinander gibt es in Telerix
nicht. Die eigene Ansage liegt für G.711 schon in 8 kHz vor (mit sox `rate -v` erzeugt) und braucht gar keine
Umrechnung. Nur die mitgelieferte GSM-Ansage wird dekodiert und neu kodiert, siehe
[Begrüßungsansage](#begrüßungsansage).

Weitere Ergebnisse:

- **Codec-Wechsel während eines Anrufs:** Der laufende Anruf blieb bestehen, die Aufnahme beim Anbieter (12,4 s ab
  dem Wechsel) hatte keine Aussetzer; der nächste Anruf kam mit dem neuen Codec.
- **Kein Comfort Noise:** Die Antwort von Telerix enthält kein `CN` im SDP.
- **QoS:** Endpunkt mit `tos_audio` 184 (EF), Transport mit `tos` 96 (CS3), ohne Warnung beim Setzen.
- **Diagnose:** Im Protokoll steht `Codec: angeboten und unterstützt (g722|alaw), gewählt (g722)`.
- **Übersteuerter Stream** (20 s): ohne Aufbereitung Spitze 0,00 dBFS mit 27 Werten Vollausschlag; Standard
  −4,31 dBFS, Präsenz +4 dB −4,69 dBFS, Präsenz +4 dB mit Sprache und +5 dB −4,86 dBFS, jeweils ohne Vollausschlag.
- **Bisherige Prüfungen unverändert:** Wartemusik beim Anruf −20,1 dBFS RMS, Spitze −5,3 dBFS (Sprache, +5 dB,
  Hochpass 180 Hz); Orgel leise/laut 8,7 / 12,1 / 18,0 dB für Sprache / Sprache und Musik / Aus, wie vor 0.06-mod.

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

Im Webinterface unter *Konfiguration → Ton am Telefon*, in der Reihenfolge der Kette: Hauptschalter
*Tonaufbereitung*, Hochpass, Sprachverständlichkeit (Präsenz), Klangprofil, Lautstärke (Schieberegler −24 bis
+24 dB), Vorlauf (0 bis 10 s) und Codec; abgesetzt darunter der Eingangspegel. Gespeicherte Werte gelten nach wenigen
Sekunden, auch für laufende Anrufe; beim Wechsel ist kurz kein Ton zu hören. Der Codec gilt ab dem nächsten Anruf.

**Hörprobe:** Der Knopf *Hörprobe mit diesen Werten* nimmt 20 s des laufenden Streams mit den gewählten Werten auf,
ohne sie zu speichern. Das dauert etwa 20 Sekunden, ein Ladebalken zeigt den Fortschritt, die Seite lädt dabei
nicht neu. Danach stehen zwei Fassungen zum Anhören bereit: *wie im
Telefonnetz* (300 bis 3400 Hz, 8 kHz, G.711 kodiert und wieder dekodiert) und *wie bei HD-Telefonie* (50 bis
7000 Hz, 16 kHz). Für die meisten Zuhörer zählt die erste; sie entspricht genau dem, was Anrufer bei „Telefonqualität
bevorzugen“ hören.

Daneben zeigt die Hörprobe ab 0.06-mod die gemessenen Pegel (`sox stats`, über die 20 s): RMS und Spitze in dBFS für
den Stream vor der Aufbereitung und für die aufbereitete Fassung, wie sie zum Anrufer geht. Gemessen wird der Stream
gleich hinter mpg123, ein eingestellter Eingangspegel ist herausgerechnet. Zur Einordnung: Sprache ist gut, wenn sie
etwa zwischen −26 und −20 dBFS RMS liegt; Spitzen sollten unter etwa −3 dBFS bleiben. Lauter ist am Telefon nicht
besser. Beispiele (Test-Streams):

| Stream | Einstellung | Stream RMS / Spitze | aufbereitet RMS / Spitze |
| --- | --- | --- | --- |
| Sprach-Testsignal | Standard | −25,9 / −8,4 dBFS | −25,9 / −8,4 dBFS |
| übersteuerter Stream | Standard | −10,8 / 0,0 dBFS (Hinweis: erreicht 0 dBFS) | −14,3 / −4,3 dBFS |
| übersteuerter Stream | Eingangspegel −3 dB | −10,8 / +0,3 dBFS | −15,3 / −5,3 dBFS |

Alternativ über `.env` im Telerix-Ordner, danach `docker compose up -d`. Werte aus `.env` haben Vorrang, die Felder
im Webinterface sind dann gesperrt:

```sh
# Leiser oder lauter in dB (-24 bis 24, Standard 0 = unverändert)
TELERIX_AUDIO_ANHEBUNG_DB=-6
# Mehr Vorlauf bei sehr unruhiger Leitung (0 bis 10 s, Standard 4)
TELERIX_AUDIO_PUFFER_SEKUNDEN=6
```

Weitere Werte (Leveler-Kennlinien, Obergrenze des Begrenzers, Reserve, Frequenz und Güte der Präsenz, Wartezeiten
beim Neuversuch) stehen kommentiert oben in `asterisk/stream.sh`.
Änderungen dort gelten erst nach einem Neubau (`docker compose up -d --build`), und `update.sh` bricht danach wegen
lokaler Änderungen ab.

## Hinweise

- **Comfort Noise:** sipgate sendet Comfort Noise (RFC 3389). Asterisk meldet dazu „Comfort noise support
  incomplete“. Das ist harmlos. Telerix selbst erzeugt weder Pausenerkennung noch Comfort Noise: Die Antwort an den
  Anbieter nennt nur G.722, G.711 und telephone-event, kein CN, und die Wartemusik sendet durchgehend Ton, solange
  der Stream läuft.
- **QoS-Markierung (ab 0.06-mod):** Sprachpakete (RTP) des Trunks und des Testtelefons tragen DSCP EF
  (`tos_audio=ef`), die SIP-Signalisierung DSCP CS3 (`tos=cs3` am Transport). Router wie die FritzBox können
  Sprache damit bevorzugen. Das braucht keine zusätzliche Capability: `IP_TOS` darf jeder Prozess setzen, die
  CoS-Priorität (`SO_PRIORITY`) bleibt auf 0; nur Werte über 6 bräuchten `CAP_NET_ADMIN`. Mit tcpdump geprüft:
  SIP mit `tos 0x60`, RTP mit `tos 0xb8`. Die RTCP-Berichte (alle paar Sekunden ein Paket) markiert Asterisk nicht.
  Die Markierung am Transport gilt erst nach einem Neustart von Asterisk bzw. des Containers.
- **Qualität des Streams:** *Konfiguration → Internetstream* und die Startseite zeigen Bitrate, Abtastrate und Kanäle
  des Streams, ermittelt mit mpg123 aus den ersten Frames und 15 Minuten zwischengespeichert. Jede Messung öffnet
  den Stream kurz, im Icecast-Status zählt Telerix dann für einen Moment als zweiter Zuhörer.
- **Eigene Messung:** Die Skriptausgabe lässt sich im Container so mitschneiden (30 s, ohne Asterisk zu stören):

  ```sh
  docker compose exec telerix sh -c 'timeout 30 su -s /bin/sh asterisk -c /usr/local/bin/telerix-stream > /tmp/mitschnitt.raw; sox -t raw -r 16000 -e signed -b 16 -c 1 /tmp/mitschnitt.raw -n stats 2>&1 | grep -E "RMS lev|Pk lev"'
  ```
