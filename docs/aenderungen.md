# Was wurde gegenüber dem Original geändert?

[← zurück zur README](../README.md)

Grundlage ist [Telerix 0.02 von Benjamin Hedert](https://github.com/beni1993/Telerix) (Stand 2022, geschrieben für
Ubuntu 18.04). Diese Fassung ist gemäß zlib-Lizenz als veränderte Version gekennzeichnet.

## Sicherheit

| Problem im Original | Lösung |
| --- | --- |
| `Install.sh` trug `www-data ALL = NOPASSWD` für `/bin/systemctl`, `/usr/sbin/asterisk` und `/sbin/shutdown` in `/etc/sudoers` ein. Jede Lücke im Webinterface wurde damit zu Root-Zugriff. | sudo ist vollständig entfernt (auch nicht im Image installiert). PHP steuert Asterisk über `asterisk -rx`; der Control-Socket gehört der Gruppe `astctl` mit Rechten `0660`. |
| `chmod -R 777` auf `/etc/asterisk`, `/var/log/asterisk`, `/var/www/html` und die Sounds | Minimale Rechte: Programmcode gehört root und ist nur lesbar, Asterisk-Konfiguration `0640 root:asterisk`, Daten `0600`/`0640`. |
| `upload.php` prüfte den Typ nur bei gesetztem `submit` und speicherte unter dem Originalnamen im Webordner. PHP-Dateien ließen sich hochladen und ausführen. `listupload.php` prüfte gar nicht. | Whitelist von Endung **und** per `finfo` erkanntem MIME-Typ, Größenlimit, zufälliger Dateiname, Ablage außerhalb des Webroots. Audio wird mit sox umgewandelt; aus Listen wird nur die bereinigte Form gespeichert. |
| `system()`-Aufrufe mit Benutzereingaben (`set_stream.php`, `set_sipconfig.php`), nur per Regex gefiltert | Keine Shell mehr im Webinterface. Externe Programme (asterisk, sox) laufen über `proc_open` mit Argument-Array. Eingaben werden geprüft und bei Fehlern abgelehnt. `exec`, `system`, `shell_exec`, `passthru`, `popen` sind in PHP abgeschaltet. |
| Login: keine neue Session-ID, kein CSRF-Schutz, keine Bremse gegen Brute-Force, Benutzer und Passwort-Hash in `userdata.php` im Repository, passwortloser Modus über `nopassword.php` | `session_regenerate_id()` nach Login, Cookies `HttpOnly` und `SameSite=Strict` (`Secure` bei HTTPS), strict mode. CSRF-Token in allen Formularen; Aktionen nur per POST. Drosselung: 5 Fehlversuche je Client bzw. 30 insgesamt in 15 Minuten führen zu 15 Minuten Sperre. Zugangsdaten nur noch im Datenvolume, gesetzt beim ersten Start. |
| `reboot.php`, `shutdown.php` fuhren den ganzen Rechner herunter | entfernt, ebenso der Neustart über systemctl. „Asterisk neu starten“ bleibt. |
| Fest eingetragenes internes Telefon `6001` mit Passwort im Repository | entfernt. Optionales Testtelefon nur mit selbst gesetztem Passwort. |

| PHP 8.4.26 und älter: CVE-2026-109618 und CVE-2026-109619 („PHP2Shell“, Speicherfehler in PHP selbst, Codeausführung ohne Anmeldung möglich) | ab 0.06-mod PHP 8.4.27. Zusätzlich ist `session.upload_progress` im PHP-FPM-Pool abgeschaltet (wird nicht gebraucht, empfohlene Gegenmaßnahme zu CVE-2026-109619). |

Weitere Härtungen: Webroot getrennt von Bibliotheken; nginx führt nur existierende `.php` im Webroot aus;
Sicherheitsheader (Content-Security-Policy ohne Inline-Skripte, `X-Frame-Options`, `nosniff`); keine externen
CDNs; Ausgaben werden HTML-maskiert; `open_basedir`; Asterisk lädt nur die nötigen Module, AMI ist abgeschaltet;
der Container läuft mit `cap_drop: ALL` (plus den zum Start nötigen Fähigkeiten) und `no-new-privileges`.

## Asterisk: chan_sip → PJSIP

- `sip.conf` ist durch `pjsip.conf` ersetzt (chan_sip gibt es in aktuellen Asterisk-Versionen nicht mehr).
  Die Anbieter-Registrierung erzeugt das Webinterface als `pjsip_trunk.conf` im Datenvolume.
- Die Skripte `set_sipconfig.sh`, `get_sip_id.sh`, `get_sip_pw.sh` und `get_sip_reg.sh`, die per `sed` Zeile 10
  von `sip.conf` umschrieben, sind durch `telerix/lib/sip.php` ersetzt.
- Anrufer werden über `core show channels concise` statt `sip show channels` ermittelt.
- Der Stream wird mit `mpg123` statt `madplay` dekodiert (madplay ist in Alpine nicht mehr enthalten).

## Neu

- Betrieb in Docker (Alpine 3.24, Asterisk 22, nginx, PHP 8.4) mit `install.sh`. Die Ubuntu-Skripte `Install.sh`,
  `Update.sh` und `Remove_Telerix.sh` sind entfernt.
- Seite „Ansage“ für eine eigene Begrüßungsansage. Sie ersetzt den ungenutzten Bild-Upload `upload.php`.
- Seiten „Sicherung“ (verschlüsseltes Backup) und „Updates“ (Prüfung auf neue Releases), dazu `update.sh`
- Betrieb hinter einem Reverse-Proxy (`TELERIX_TRUSTED_PROXIES`) und hinter NAT (`TELERIX_EXTERNAL_ADDRESS`)
- Aufbereitung des Streams für das Telefon (16 kHz, Leveler, Begrenzer, Puffer), siehe [Tonqualität](tonqualitaet.md)
- Saubere Abmeldung beim SIP-Anbieter beim Stoppen des Containers (keine doppelten Anrufe nach einem Neustart)
- Rückmeldungen (Erfolg/Fehler) nach jedem Speichern
- Ab 0.06-mod: Lautstärke, Vorlauf, öffentliche Adresse und Testtelefon im Webinterface (`.env` hat Vorrang),
  Begrüßungsansage abschaltbar, diese Einstellungen auch in der Sicherung
- Ab 0.06-mod: Klangprofil und Hochpass einstellbar, Lautstärke als Schieberegler von −24 bis +24 dB, Hörprobe im
  Browser (Telefonnetz und HD, mit Ladebalken statt Neuladen), Codec je Anrufer auf der Startseite, Neuversuch nach
  2 s statt 30 s, wenn der Stream abbricht
- **Ab 0.06-mod geänderter Standard für den Ton: unbearbeitet** (Lautstärke 0 dB, Klangprofil und Hochpass aus).
  Bis 0.05-mod galt Sprache, +5 dB, Hochpass 180 Hz. Nach dem Update klingt es deshalb anders, wer die
  Einstellungen nicht selbst gespeichert hat; Werte aus `.env` gelten unverändert. Begründung in
  [Tonqualität](tonqualitaet.md#was-passiert-mit-dem-stream).
- Ab 0.06-mod: Tonkette in fester Reihenfolge (Eingangspegel, Hochpass, Präsenz, Leveler, Lautstärke, Begrenzer).
  Der Begrenzer läuft immer und spricht sofort an (bisher nur bei Anhebung, mit 0,1 ms Ansprechzeit und bis zu
  0,8 dB Überschwingen); bei normal ausgesteuertem Stream ändert sich nichts. Interne Reserve, damit Umrechnung und
  Präsenz in sox nicht abschneiden. Hauptschalter *Tonaufbereitung*, der alles abschaltet (nur Mono und 16 kHz).
  Siehe [Tonqualität](tonqualitaet.md#begrenzer).
- Ab 0.06-mod: *Sprachverständlichkeit (Präsenz)*, +2 bis +4 dB bei 2,8 kHz, Standard aus
- Ab 0.06-mod: *Eingangspegel* für zu laut ausgesteuerte Streams (−12 bis 0 dB, wirkt im Decoder, bevor mpg123
  abschneidet), Standard 0 dB
- Ab 0.06-mod: Hörprobe zeigt RMS und Spitze des Streams und der aufbereiteten Fassung, mit Hinweis bei einem
  übersteuerten Stream
- Ab 0.06-mod: eigene Begrüßungsansage in 16 kHz für HD-Anrufer und 8 kHz für G.711, beide direkt kodiert, mit
  Hochpass und Präsenz wie beim Stream und auf einen festen Sprachpegel (−23 dBFS) gebracht; wird bei geänderten
  Toneinstellungen und nach dem Update aus dem Original neu erzeugt. Die Sicherung enthält jetzt die Quelle (16 kHz),
  sie darf deshalb bis 32 MB groß sein.
- Ab 0.06-mod: Einstellung *Codec*: „HD bevorzugen (G.722)“ (Standard, wie bisher) oder „Telefonqualität bevorzugen
  (G.711)“, gegen die zusätzliche Umrechnung bei sipgate für Anrufer ohne HD; gilt ohne Neustart. Dazu eine
  Codec-Diagnose je Anruf im Protokoll. Siehe [HD oder Telefonqualität?](tonqualitaet.md#hd-oder-telefonqualität)
- Ab 0.06-mod: QoS-Markierung: Sprache DSCP EF, SIP DSCP CS3
- Ab 0.06-mod: Bitrate, Abtastrate und Kanäle des Streams bei *Internetstream* und auf der Startseite, mit Hinweis
  unter 128 kbit/s
- Ab 0.06-mod: Anrufer und Icecast-Status auf der Startseite werden jede Minute neu geladen
- Ab 0.06-mod: Pakete nur noch auf ihre Versionsreihe festgelegt (PHP mit Mindestversion), damit ein Paket-Update
  bei Alpine den Build nicht mehr scheitern lässt; monatlicher Build mit Rauchtest auf GitHub
- Ab 0.06-mod: Icecast-Status auf der Startseite mit Grund bei Fehlern, eigener Statusadresse, Erkennung von
  AzuraCast, nur dem eigenen Mountpoint und Ausblenden; 30 s zwischengespeichert, damit die Startseite nicht bei
  jedem Aufruf auf den Server wartet
- **Ab 0.07-mod: Wächter für die Wartemusik.** Liefert der Stream 15 s lang keine Daten mehr (auch wenn der Server
  die Verbindung offen hält), beendet `telerix-stream` die ganze Kette hart und verbindet neu. Bisher konnte ein
  hängender Stream Anrufer tagelang Stille hören lassen, bis Asterisk neu gestartet wurde; `mpg123 --timeout`
  greift in diesem Fall nicht und mpg123 übergeht sogar SIGTERM. Siehe
  [Wenn der Stream hängt](tonqualitaet.md#wenn-der-stream-hängt).
- Ab 0.07-mod: Jeder Abbruch und jede Neuverbindung der Wartemusik mit Grund und Zeit im Protokoll; Warnung bei
  2 Minuten digitaler Stille (ohne Neuverbindung); bei langem Ausfall nur noch jeder zehnte Fehlversuch im Protokoll
- Ab 0.07-mod: Startseite zeigt unter *Wartemusik*, wann zuletzt Daten vom Stream ankamen, mit Warnung nach 30 s
  ohne Daten oder bei Stille, und eine Anrufstatistik der letzten 24 Stunden (Anzahl, Durchschnittsdauer, Anteil
  unter 30 s, Hinweis bei auffällig vielen kurzen Anrufen)
- Ab 0.07-mod: Beim Auflegen eine Zeile im Protokoll mit Kennung, Dauer, Grund (`HANGUPCAUSE`) und wer aufgelegt hat,
  ohne Rufnummer; die Codec-Zeile beim Annehmen trägt dieselbe Kennung
- **Ab 0.07-mod: Protokolle begrenzt.** Docker-Log des Containers höchstens 5 × 10 MB (`docker-compose.yml`), keine
  `messages.log` und `queue_log` von Asterisk mehr (alles doppelt und ohne Rotation, bis 2,8 GB). Meldungen von
  SIP-Scannern (abgewiesene Anfragen, ungültige Pakete) werden stündlich zusammengefasst
  (`docker/asterisk-log.awk`), Fehler beim eigenen Anbieter bleiben einzeln sichtbar. pjproject schreibt den Inhalt
  verworfener Pakete nicht mehr als NOTICE ins Log, und Fehler von pjproject (Stufe 1) erscheinen wieder. nginx
  protokolliert den Healthcheck nicht mehr. Reste abgebrochener Hörproben und Temp-Dateien werden beim Start entfernt.
  Siehe [Protokolle](konfiguration.md#protokolle).
- Ab 0.07-mod: Optionale ACL `TELERIX_SIP_ALLOW` (SIP nur von den Netzen des Anbieters), Standard aus; Doku zu Ports
  und Firewall, siehe [Ports und Firewall](konfiguration.md#ports-und-firewall)
- Ab 0.07-mod: Die harmlose Meldung „no local system nameservers configured“ erscheint als Hinweis statt als ERROR
- Ab 0.07-mod: Das Webinterface wartet bei einem hängenden Stream nicht mehr ewig auf mpg123 (Qualität des
  Streams, Hörprobe): nach der Zeitüberschreitung wird hart beendet
- Ab 0.07-mod: Neue Tests `tests/stream-test.sh` (hängender und stiller Stream) und `tests/log-test.sh`

## Bekannte Einschränkungen

- Unterstützt werden nur MP3-Streams (wie im Original).
- Automatisch getestet wird gegen einen nachgebildeten SIP-Anbieter (`tests/sip-test.sh`): Registrierung,
  Abmeldung, eingehende Anrufe, Ansage, Wartemusik und Tonpegel. Echte Anrufe über das Telefonnetz deckt dieser
  Test nicht ab.
- Die Login-Sperre zählt je Client-IP. Mit Docker Desktop oder hinter einem Proxy ohne `TELERIX_TRUSTED_PROXIES`
  sehen alle Zugriffe gleich aus, die Sperre trifft dann alle gemeinsam.
- Asterisk ermittelt unter Alpine (musl) die DNS-Server nicht selbst und nutzt den Resolver des Systems. Das ist
  unkritisch; ab 0.07-mod steht dazu beim Start ein Hinweis im Protokoll statt der Fehlermeldung
  `There are no local system nameservers configured`. Abschalten lässt sich die Prüfung in Asterisk nicht.
- Anrufstatistik und Zustand der Wartemusik liegen nicht im Datenvolume und beginnen nach einem Update von vorn.
