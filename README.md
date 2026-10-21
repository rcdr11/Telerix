# Telerix – veränderte Version

> **Dies ist eine veränderte Version von Telerix.**
> Grundlage ist [Telerix 0.02 von Benjamin Hedert](https://github.com/beni1993/Telerix) (Stand 2022).
> Gegenüber dem Original wurden Sicherheitslücken geschlossen, Asterisk von chan_sip auf PJSIP umgestellt
> und ein Docker-Betrieb ergänzt (Einzelheiten unter [Was wurde geändert?](#was-wurde-geändert)).
> Fehler, die nur in dieser Version auftreten, bitte nicht im Original-Repository melden.
> Version dieser Fassung: **0.03-mod**.

Ein Telefonstreaming-System auf Basis von Asterisk.

## Wozu Telerix?

Telerix macht einen Icecast-Internetradio-Stream, zum Beispiel einen Gottesdienst, per Telefon hörbar.
Wer die Nummer anruft, hört eine Begrüßungsansage und danach den Stream. Über ein Webinterface werden
Stream-Adresse und SIP-Zugang eingestellt, die aktuellen Zuhörer angezeigt und bei Bedarf alle Anrufer aufgelegt.

Der Name setzt sich aus „Telefon“ und „Asterisk“ zusammen, das wegen der Ähnlichkeit oft „Asterix“ genannt wurde.

## Aufbau

```text
Anrufer ──SIP──> Asterisk (PJSIP, Kontext sip-in, Extension 200)
                   ├─ Playback: Begrüßungsansage (eigene oder mitgelieferte)
                   └─ MusicOnHold "stream": telerix-stream ─> mpg123 ─> Icecast-Stream

Browser ──HTTP (127.0.0.1:8080)──> nginx ──> PHP-FPM (Benutzer telerix)
                                              ├─ schreibt Konfiguration nach /var/lib/telerix
                                              └─ steuert Asterisk per "asterisk -rx" (Control-Socket)
```

| Pfad im Repository | Inhalt |
| --- | --- |
| `telerix/public/` | Webroot: die direkt aufrufbaren Seiten, CSS, JavaScript |
| `telerix/lib/` | Login, Session, CSRF, Asterisk-Steuerung, SIP, Stream, Uploads, Layout (nicht per URL erreichbar) |
| `telerix/bin/init-admin.php` | legt beim ersten Start die Admin-Zugangsdaten an |
| `asterisk/` | Asterisk-Konfiguration (`pjsip.conf`, `extensions.conf`, `musiconhold.conf` …) und `stream.sh` |
| `ansagen/` | mitgelieferte Begrüßungsansage |
| `docker/` | nginx-, PHP-FPM- und supervisord-Konfiguration, Entrypoint |
| `tests/smoke-test.sh` | automatischer Test gegen den laufenden Container |

Im Container laufen drei Dienste unter supervisord: Asterisk (als `asterisk`), PHP-FPM (als `telerix`) und
nginx (Worker als `nginx`).

## Voraussetzungen

- Docker mit Compose-Plugin (unter Windows/macOS: Docker Desktop; unter Windows wird dafür WSL 2 benötigt)
- Für einen echten Betrieb: SIP-Zugangsdaten eines Anbieters und die Adresse eines Icecast-MP3-Streams

## Bauen und starten

1. Admin-Passwort anlegen (mindestens 10 Zeichen). Die Datei liegt im Ordner `secrets/`,
   der per `.gitignore` nie eingecheckt wird:

   ```sh
   mkdir secrets
   printf '%s\n' 'ein-langes-geheimes-passwort' > secrets/admin_password.txt
   ```

2. Image bauen und starten:

   ```sh
   docker compose up -d --build
   ```

3. Webinterface öffnen: <http://127.0.0.1:8080> – Benutzer `admin`, Passwort aus Schritt 1.

Das Passwort wird nur beim **ersten Start** übernommen und als Hash in `config/admin.json` im Datenvolume
gespeichert. Danach wird es im Webinterface unter *Konfiguration → Zugangsdaten ändern* geändert;
die Datei in `secrets/` kann dann gelöscht werden.

Weitere Befehle:

```sh
docker compose logs -f              # Protokolle von Asterisk, PHP und nginx
docker compose ps                   # Status inkl. Healthcheck
docker compose exec telerix asterisk -rx "pjsip show registrations"
docker compose down                 # stoppen (Daten bleiben im Volume)
```

## Konfiguration

### Im Webinterface

| Seite | Einstellung |
| --- | --- |
| Konfiguration → Internetstream | Adresse des Icecast-MP3-Streams, z. B. `http://www.beispiel.de:8000/gottesdienst`. Fehlt `http://`, wird es ergänzt. Die neue Adresse gilt nach wenigen Sekunden, laufende Anrufe bleiben bestehen. |
| Konfiguration → SIP-Konfiguration | SIP-ID, Passwort und Registrar des Anbieters. Wird ohne Neustart von Asterisk geladen. Das gespeicherte Passwort wird nicht wieder angezeigt, ein leeres Feld behält es bei. Alle Felder leeren schaltet die Registrierung ab. |
| Konfiguration → Zugangsdaten ändern | Benutzername und Passwort für das Webinterface |
| Ansage | Eigene Begrüßungsansage hochladen (wav, mp3, ogg, flac bis 20 MB). Sie wird in 8 kHz Mono umgewandelt. Ohne eigene Ansage wird `ansagen/willkommen-gottesdienst.gsm` gespielt. |
| Nummerverwaltung | Liste „Name Nummer“ (txt/csv, max. 1 MB) hochladen, damit statt der Nummer der Name des Anrufers angezeigt wird |
| Sicherung | Konfiguration verschlüsselt herunterladen und wieder einspielen (siehe [Sicherung](#sicherung)) |
| Updates | Prüfen, ob es eine neue Version gibt (siehe [Updates](#updates)) |

Eingehende Anrufe des Anbieters landen wie im Original auf Extension 200 im Kontext `sip-in`.

### Umgebungsvariablen (`docker-compose.yml`)

| Variable | Bedeutung |
| --- | --- |
| `TELERIX_ADMIN_USER` | Benutzername beim ersten Start (Standard `admin`) |
| `TELERIX_ADMIN_PASSWORD_FILE` | Datei mit dem Admin-Passwort, z. B. ein Docker-Secret (empfohlen) |
| `TELERIX_ADMIN_PASSWORD` | Alternativ das Passwort direkt. Achtung: Umgebungsvariablen sind z. B. über `docker inspect` sichtbar. |
| `TELERIX_ADMIN_RESET` | Auf `1` setzen, um die Zugangsdaten beim nächsten Start aus der Passwortdatei neu anzulegen („Passwort vergessen“). Danach wieder entfernen. |
| `TELERIX_TESTPHONE_PASSWORD` | Legt ein Testtelefon `testphone` an (mind. 12 Zeichen aus `A-Z a-z 0-9 . _ + -`). Es landet im Kontext `from-internal`, Extension 100 spielt Piepton und Stream. Ohne Variable gibt es kein internes Telefon. |
| `TELERIX_COOKIE_SECURE` | Auf `1` setzen, wenn das Webinterface hinter einem HTTPS-Reverse-Proxy läuft (Session-Cookie mit `Secure`) |
| `TELERIX_UPDATE_REPO` | GitHub-Repository für die Update-Prüfung, z. B. `meinname/Telerix`. Wird aus der Umgebung bzw. einer `.env`-Datei neben `docker-compose.yml` gelesen. Leer = Prüfung aus. |

### Netzwerk, SIP und NAT

- Das Webinterface ist nur auf `127.0.0.1:8080` freigegeben, also nur vom eigenen Rechner aus erreichbar.
- Für die Registrierung beim Anbieter müssen in der Regel **keine** Ports freigegeben werden: Asterisk baut die
  Verbindung von innen auf, eingehende Anrufe kommen über diese Verbindung zurück (`rtp_symmetric`,
  `force_rport`, `rewrite_contact`, `qualify_frequency=60` halten die NAT-Zuordnung offen).
- Kommt beim Anrufer kein Ton an, in `asterisk/pjsip.conf` `external_media_address` und
  `external_signaling_address` auf die öffentliche Adresse setzen und SIP/RTP freigeben.
- Für ein Testtelefon im lokalen Netz die auskommentierten Zeilen für `5060/udp` und `10000-10099/udp` in
  `docker-compose.yml` aktivieren. Diese Ports nie ungeschützt ins Internet freigeben.
- Eingehende Anrufe werden nur angenommen, wenn sie vom Registrar kommen (`identify`) oder zur Registrierung
  gehören (`line=yes`). Schickt der Anbieter Anrufe von weiteren IP-Adressen, greift `line=yes`.

### Daten

Alle veränderlichen Daten liegen im Volume `telerix-data` (`/var/lib/telerix` im Container):

| Verzeichnis | Inhalt | Rechte |
| --- | --- | --- |
| `config/` | `admin.json` (Passwort-Hash), `sip.json` (SIP-Zugang) | `0700`, Dateien `0600`, nur `telerix` |
| `state/` | Zähler der Login-Drosselung | `0700`, nur `telerix` |
| `uploads/lists`, `uploads/audio` | hochgeladene Listen und Ansage, zufällige Dateinamen | `0700`, Dateien `0600` |
| `asterisk/` | von PHP erzeugt, von Asterisk gelesen: `pjsip_trunk.conf`, `pjsip_testphone.conf`, `stream.url` | `2750 telerix:asterisk`, Dateien `0640` |
| `sounds/` | umgewandelte Ansage `ansage.wav` | `2750 telerix:asterisk`, Dateien `0640` |

## Sicherung

**Im Webinterface (Seite „Sicherung“):** Admin-Zugang, SIP-Zugang, Stream-Adresse, Nummernliste und die eigene
Ansage werden als eine Datei `telerix-backup-….tlxbak` heruntergeladen. Die Datei ist mit einem selbst gewählten
Backup-Passwort (mindestens 10 Zeichen) verschlüsselt (libsodium: Argon2id, XChaCha20-Poly1305). **Ohne dieses
Passwort lässt sich die Sicherung nicht wieder einspielen**, also gut aufbewahren.

Beim Einspielen wird die aktuelle Konfiguration vollständig ersetzt, auch der Admin-Zugang. Danach gelten
Benutzername und Passwort aus der Sicherung. Die Werte werden dabei geprüft wie eine Eingabe im Webinterface,
manipulierte oder fremde Dateien werden abgewiesen.

Nicht enthalten: das Original der hochgeladenen Ansage (nur die umgewandelte Fassung), Login-Sperren und das
Testtelefon (kommt aus `TELERIX_TESTPHONE_PASSWORD`).

**Komplettes Datenvolume (auf dem Rechner):** Das macht `update.sh` vor jedem Update automatisch nach
`backups/`. Von Hand geht es so:

```sh
docker run --rm -v telerix_telerix-data:/data -v "$PWD":/backup alpine:3.24.2 \
  tar czf /backup/telerix-backup.tgz -C /data .
```

Diese Archive sind **nicht verschlüsselt** und enthalten das SIP-Passwort im Klartext.

## Updates

Das Webinterface **prüft** nur, ob es eine neue Version gibt. **Aktualisiert** wird mit `update.sh` auf dem
Rechner. Der Grund: Damit sich der Container selbst ersetzen könnte, bräuchte er Zugriff auf Docker, und das
entspricht Root-Rechten auf dem Rechner. Jede Lücke im Webinterface würde dann wieder zum Vollzugriff führen.

### Einrichten (einmalig)

1. Ein eigenes GitHub-Repository für diese Fassung anlegen (z. B. einen Fork von `beni1993/Telerix`).
   Es muss öffentlich sein, damit das Webinterface die Releases ohne Zugangsdaten abfragen kann.
2. Das lokale Repository damit verbinden: `git remote set-url origin https://github.com/meinname/Telerix.git`
   (oder als weiteres Remote; dann `TELERIX_UPDATE_REMOTE=meinremote ./update.sh`).
3. Neben `docker-compose.yml` eine Datei `.env` mit `TELERIX_UPDATE_REPO=meinname/Telerix` anlegen und
   `docker compose up -d` ausführen.

### Neue Version veröffentlichen

1. Änderungen committen, z. B. neue Paketversionen im `Dockerfile` nach einem Sicherheitsupdate.
2. `TELERIX_VERSION` in `telerix/lib/bootstrap.php` erhöhen (z. B. `0.04-mod`).
3. Bauen und `tests/smoke-test.sh` laufen lassen (auf einer Testinstallation, der Test überschreibt die Konfiguration).
4. Tag setzen und pushen: `git tag v0.04-mod && git push origin --tags`. Tags beginnen immer mit `v`.
5. Auf GitHub aus dem Tag ein Release erstellen. Der Text erscheint im Webinterface als Änderungsliste.

### Aktualisieren

Wenn die Seite „Updates“ eine neue Version meldet, im Telerix-Ordner (unter Windows in Git Bash):

```sh
./update.sh        # zeigt die Änderungen und fragt nach
./update.sh -y     # ohne Rückfrage
```

Das Skript:

1. bricht ab, wenn es lokale, nicht eingecheckte Änderungen gibt,
2. sichert das Datenvolume nach `backups/`,
3. übernimmt das neueste Tag `v…` per Fast-Forward,
4. baut das Image neu. Schlägt der Build fehl, wird der Code zurückgesetzt und der laufende Container bleibt
   unverändert.
5. startet den Container neu (laufende Anrufe werden getrennt) und wartet auf den Healthcheck. Wird er nicht
   „healthy“, nennt das Skript die Befehle zum Zurückgehen auf die alte Version.

## Nachvollziehbar und gleich neu bauen

- Das Basis-Image ist per Tag und Digest festgelegt:
  `alpine:3.24.2@sha256:294b683cb724975bec92580e1e685676bd4b50bda910ddb8c51d4cabeaec77e6`.
- Alle Pakete sind auf exakte Versionen gepinnt (u. a. Asterisk `22.9.0-r0`, PHP `8.4.26-r0`, nginx `1.30.4-r1`).
- Alpine hält in einem Release-Zweig nur die jeweils neueste Paketrevision vor. Wird ein Paket aktualisiert
  (meist Sicherheitsupdates), **schlägt der Build fehl**, statt still eine andere Version einzubauen.
  Dann die Versionsnummern im `Dockerfile` auf den neuen Stand setzen, bauen und `tests/smoke-test.sh`
  ausführen. Aktuelle Versionen zeigt z. B.
  `docker run --rm alpine:3.24.2 sh -c "apk update -q && apk policy asterisk php84"`.
- Wer ein bestimmtes, getestetes Image aufbewahren will: `docker save telerix:local | gzip > telerix-0.03-mod.tar.gz`.

Warum diese Versionen? Asterisk 22 ist die aktuelle LTS-Linie (Sicherheitsupdates bis voraussichtlich 2029)
und ist in Alpine 3.24 enthalten; chan_sip gibt es dort nicht mehr. nginx und PHP-FPM sind getrennt
konfigurierbar und lassen PHP unter einem eigenen, unprivilegierten Benutzer laufen.

## Testen

Bei laufendem Container:

```sh
tests/smoke-test.sh
```

Der Test meldet sich mit dem Passwort aus `secrets/admin_password.txt` an und prüft Zugriffsschutz,
Sicherheitsheader, Login (CSRF, Session-Erneuerung, Cookie-Flags, Drosselung), Stream- und SIP-Konfiguration
inklusive Laden in Asterisk, Upload-Whitelist, Ansage-Umwandlung, Asterisk-Neustart und die Dateirechte.
**Achtung:** Er überschreibt Stream-Adresse, SIP-Daten, Ansage und Nummernliste mit Testwerten.

## Was wurde geändert?

### Sicherheit

| Problem im Original | Lösung |
| --- | --- |
| `Install.sh` trug `www-data ALL = NOPASSWD` für `/bin/systemctl`, `/usr/sbin/asterisk` und `/sbin/shutdown` in `/etc/sudoers` ein. Jede Lücke im Webinterface wurde damit zu Root-Zugriff. | sudo ist vollständig entfernt (auch nicht im Image installiert). PHP steuert Asterisk über `asterisk -rx`; der Control-Socket gehört der Gruppe `astctl` mit Rechten `0660`. |
| `chmod -R 777` auf `/etc/asterisk`, `/var/log/asterisk`, `/var/www/html` und die Sounds | Minimale Rechte: Programmcode gehört root und ist nur lesbar, Asterisk-Konfiguration `0640 root:asterisk`, Daten `0600`/`0640` (siehe oben). |
| `upload.php` prüfte den Typ nur bei gesetztem `submit` und speicherte unter dem Originalnamen im Webordner. PHP-Dateien ließen sich hochladen und ausführen. `listupload.php` prüfte gar nicht. | Whitelist von Endung **und** per `finfo` erkanntem MIME-Typ, Größenlimit, zufälliger Dateiname, Ablage außerhalb des Webroots. Audio wird mit sox umgewandelt; aus Listen wird nur die bereinigte Form gespeichert. |
| `system()`-Aufrufe mit Benutzereingaben (`set_stream.php`, `set_sipconfig.php`), nur per Regex gefiltert | Keine Shell mehr im Webinterface. Externe Programme (asterisk, sox) laufen über `proc_open` mit Argument-Array. Eingaben werden validiert und bei Fehlern abgelehnt. `exec`, `system`, `shell_exec`, `passthru`, `popen` sind in PHP abgeschaltet. |
| Login: keine neue Session-ID, kein CSRF-Schutz, keine Bremse gegen Brute-Force, Benutzer und Passwort-Hash in `userdata.php` im Repository, passwortloser Modus über `nopassword.php` | `session_regenerate_id()` nach Login, Cookies `HttpOnly` und `SameSite=Strict` (`Secure` bei HTTPS), strict mode. CSRF-Token in allen Formularen; Aktionen nur per POST. Drosselung: 5 Fehlversuche je Client bzw. 30 insgesamt in 15 Minuten führen zu 15 Minuten Sperre. Zugangsdaten nur noch im Datenvolume, gesetzt beim ersten Start. |
| `reboot.php`, `shutdown.php` fuhren den ganzen Rechner herunter | entfernt, ebenso der Neustart über systemctl. „Asterisk neu starten“ bleibt. |
| Fest eingetragenes internes Telefon `6001` mit Passwort im Repository | entfernt. Optionales Testtelefon nur mit selbst gesetztem Passwort. |

Weitere Härtungen: Webroot getrennt von Bibliotheken; nginx führt nur existierende `.php` im Webroot aus;
Sicherheitsheader (Content-Security-Policy ohne Inline-Skripte, `X-Frame-Options`, `nosniff`); keine externen
CDNs; Ausgaben werden HTML-maskiert; `open_basedir`; Asterisk lädt nur die nötigen Module, AMI ist abgeschaltet;
der Container läuft mit `cap_drop: ALL` (plus den zum Start nötigen Fähigkeiten) und `no-new-privileges`.

### Asterisk: chan_sip → PJSIP

- `sip.conf` ist durch `pjsip.conf` ersetzt. Die Anbieter-Registrierung (auth, aor, endpoint, identify,
  registration) erzeugt das Webinterface als `pjsip_trunk.conf` im Datenvolume. `pjsip.conf` bindet sie per
  `#include` ein.
- Die Skripte `set_sipconfig.sh`, `get_sip_id.sh`, `get_sip_pw.sh` und `get_sip_reg.sh`, die per `sed` Zeile 10
  von `sip.conf` umschrieben, sind durch `telerix/lib/sip.php` ersetzt.
- Anrufer werden über `core show channels concise` statt `sip show channels` ermittelt.
- Der Stream wird mit `mpg123` statt `madplay` dekodiert (madplay ist in Alpine nicht mehr enthalten).

### Sonstiges

- Die Ubuntu-Skripte `Install.sh`, `Update.sh` und `Remove_Telerix.sh` sind durch das Docker-Setup ersetzt.
- Neue Seite „Ansage“ für eine eigene Begrüßungsansage. Sie ersetzt den ungenutzten Bild-Upload `upload.php`.
- Rückmeldungen (Erfolg/Fehler) nach jedem Speichern
- Neue Seiten „Sicherung“ (verschlüsseltes Backup und Wiederherstellung) und „Updates“ (Prüfung auf neue
  Releases) sowie `update.sh` für das Update auf dem Rechner.
- Zeilenenden sind per `.gitattributes` auf LF festgelegt (wichtig beim Auschecken unter Windows).

## Bekannte Einschränkungen

- Die Login-Drosselung zählt je Client-IP. Über die Docker-Portweiterleitung sehen alle Zugriffe gleich aus,
  die Sperre wirkt dann für alle Clients gemeinsam.
- Asterisk meldet beim Start `There are no local system nameservers configured, resorting to system resolution`.
  Das liegt am Docker-internen DNS (127.0.0.11) und ist unkritisch, die Namensauflösung funktioniert.
- Unterstützt werden nur MP3-Streams (wie im Original).
- Eine echte Registrierung bei einem Anbieter und echte Anrufe über das Telefonnetz sind noch nicht getestet.
  Geprüft sind das fehlerfreie Laden der PJSIP-Konfiguration und ein interner Testanruf bis zur Wartemusik.

## Lizenz

Telerix steht unter der zlib-Lizenz. Diese veränderte Version ist gemäß Punkt 2 der Lizenz als solche
gekennzeichnet (dieser Abschnitt, der Hinweis am Anfang, die Info-Seite und die Fußzeile des Webinterface).
Der ursprüngliche Lizenzhinweis steht unverändert in [LICENSE](LICENSE):

```text
Copyright (c) 2020 Benjamin Hedert

This software is provided 'as-is', without any express or implied warranty.
In no event will the authors be held liable for any damages arising from
the use of this software.

Permission is granted to anyone to use this software for any purpose,
including commercial applications, and to alter it and redistribute it freely,
subject to the following restrictions:

    1. The origin of this software must not be misrepresented; you must not
       claim that you wrote the original software. If you use this software
       in a product, an acknowledgment in the product documentation would be
       appreciated but is not required.

    2. Altered source versions must be plainly marked as such, and must not
       be misrepresented as being the original software.

    3. This notice may not be removed or altered from any source distribution.
```
