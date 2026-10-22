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
- Ab 0.06-mod: Anrufer und Icecast-Status auf der Startseite werden jede Minute neu geladen
- Ab 0.06-mod: Pakete nur noch auf ihre Versionsreihe festgelegt (PHP mit Mindestversion), damit ein Paket-Update
  bei Alpine den Build nicht mehr scheitern lässt; monatlicher Build mit Rauchtest auf GitHub
- Ab 0.06-mod: Icecast-Status auf der Startseite mit Grund bei Fehlern, eigener Statusadresse, Erkennung von
  AzuraCast, nur dem eigenen Mountpoint und Ausblenden; 30 s zwischengespeichert, damit die Startseite nicht bei
  jedem Aufruf auf den Server wartet

## Bekannte Einschränkungen

- Unterstützt werden nur MP3-Streams (wie im Original).
- Automatisch getestet wird gegen einen nachgebildeten SIP-Anbieter (`tests/sip-test.sh`): Registrierung,
  Abmeldung, eingehende Anrufe, Ansage, Wartemusik und Tonpegel. Echte Anrufe über das Telefonnetz deckt dieser
  Test nicht ab.
- Die Login-Sperre zählt je Client-IP. Mit Docker Desktop oder hinter einem Proxy ohne `TELERIX_TRUSTED_PROXIES`
  sehen alle Zugriffe gleich aus, die Sperre trifft dann alle gemeinsam.
- Asterisk meldet beim Start `There are no local system nameservers configured, resorting to system resolution`.
  Das liegt am Docker-internen DNS und ist unkritisch.
