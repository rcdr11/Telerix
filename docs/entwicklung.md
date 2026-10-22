# Entwicklung

[← zurück zur README](../README.md)

## Aufbau

```text
Anrufer ──SIP──> Asterisk (PJSIP, Kontext sip-in, Extension 200)
                   ├─ Playback: Begrüßungsansage (eigene oder mitgelieferte)
                   └─ MusicOnHold "stream": telerix-stream ─> mpg123 ─> Icecast-Stream

Browser ──HTTP (127.0.0.1:8080)──> nginx ──> PHP-FPM (Benutzer telerix)
                                              ├─ schreibt Konfiguration nach /var/lib/telerix
                                              └─ steuert Asterisk per "asterisk -rx" (Control-Socket)
```

| Pfad | Inhalt |
| --- | --- |
| `telerix/public/` | Webroot: die direkt aufrufbaren Seiten, CSS, JavaScript |
| `telerix/lib/` | Login, Session, CSRF, Asterisk-Steuerung, SIP, Stream, Einstellungen, Uploads, Sicherung, Layout (nicht per URL erreichbar) |
| `telerix/bin/` | Hilfsskripte für den Containerstart (Admin-Zugang, erzeugte Asterisk-Dateien, Adressprüfung) |
| `asterisk/` | Asterisk-Konfiguration und `stream.sh` |
| `ansagen/` | mitgelieferte Begrüßungsansage |
| `docker/` | nginx-, PHP-FPM- und supervisord-Konfiguration, Entrypoint |
| `tests/` | automatische Tests gegen den laufenden Container |

Im Container laufen unter supervisord: Asterisk (als `asterisk`), PHP-FPM (als `telerix`) und nginx
(Worker als `nginx`).

## Versionen und reproduzierbarer Build

- Basis-Image per Tag und Digest festgelegt:
  `alpine:3.24.2@sha256:294b683cb724975bec92580e1e685676bd4b50bda910ddb8c51d4cabeaec77e6`
- Alle Pakete sind auf exakte Versionen gepinnt (u. a. Asterisk `22.9.0-r0`, PHP `8.4.27-r0`, nginx `1.30.4-r1`).
  Asterisk 22 ist die aktuelle LTS-Linie (Sicherheitsupdates bis voraussichtlich 2029).
- Alpine hält in einem Release-Zweig nur die neueste Paketrevision vor. Wird ein Paket aktualisiert, **schlägt der
  Build fehl**, statt still eine andere Version einzubauen. Dann die Versionen im `Dockerfile` anpassen.
  Aktuelle Versionen zeigt:
  `docker run --rm alpine:3.24.2 sh -c "apk update -q && apk policy asterisk php84"`

## Tests

Bei laufendem Container, im Telerix-Ordner:

```sh
tests/smoke-test.sh    # Login, CSRF, Uploads, Stream, SIP, Sicherung, Neustart, Dateirechte
tests/proxy-test.sh    # Betrieb hinter einem Reverse-Proxy (startet Telerix mehrmals neu)
tests/sip-test.sh      # nachgebildeter SIP-Anbieter: Ansage, Anrufe, Abmeldung, Tonpegel, Aussetzer
```

**Achtung:** `smoke-test.sh` und `sip-test.sh` überschreiben Stream-Adresse, SIP-Daten, Ansage und Nummernliste
mit Testwerten.
Vorher eine Sicherung herunterladen oder auf einer Testinstallation laufen lassen.

## Neue Version veröffentlichen

1. Änderungen committen, z. B. neue Paketversionen im `Dockerfile`.
2. `TELERIX_VERSION` in `telerix/lib/bootstrap.php` und `user_agent` in `asterisk/pjsip.conf` erhöhen
   (z. B. auf `0.06-mod`), ebenso die Versionsangabe oben in der README.
3. Neu bauen (`docker compose up -d --build`) und die Tests laufen lassen.
4. Pushen und Tag setzen. Tags beginnen immer mit `v`:

   ```sh
   git push
   git tag -a v0.06-mod -m "Telerix 0.06-mod"
   git push origin v0.06-mod
   ```

5. Release erstellen. Der Text erscheint im Webinterface als Änderungsliste:
   - im Browser: Repository → *Releases* → *Draft a new release* → Tag wählen → *Publish release*
   - oder: `gh release create v0.06-mod --title "Telerix 0.06-mod" --notes "…"`
