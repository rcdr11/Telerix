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

## Versionen und Build

- Basis-Image per Tag und Prüfsumme festgelegt:
  `alpine:3.24.2@sha256:294b683cb724975bec92580e1e685676bd4b50bda910ddb8c51d4cabeaec77e6`.
  Alpine 3.24 bekommt Sicherheitsupdates voraussichtlich bis 2028.
- Pakete sind nur auf ihre **Versionsreihe** festgelegt, z. B. `nginx~1.30` (jede 1.30.x) und `asterisk~22`
  (LTS-Reihe, Sicherheitsupdates bis voraussichtlich 2029). Für PHP gilt eine **Mindestversion**, `php84>=8.4.27`,
  weil ältere Versionen CVE-2026-109618 und CVE-2026-109619 enthalten.
- **Warum nicht exakt?** Alpine hält in einem Release-Zweig nur die neueste Version eines Pakets vor. Bis
  0.05-mod waren die Versionen exakt festgelegt; jedes Paket-Update bei Alpine machte Telerix dann unbaubar, bis das
  Dockerfile angepasst und ein Release erstellt war (so geschehen mit nginx `1.30.4-r1` → `1.30.6-r0`). Jetzt
  kommen Korrektur- und Sicherheitsupdates beim nächsten Neubau (`./update.sh` oder `docker compose up -d --build`)
  automatisch mit. Größere Sprünge, etwa Asterisk 23 oder PHP 8.5, gibt es nur durch eine Änderung im Dockerfile.
- Welche Versionen gerade aktuell sind, zeigt:
  `docker run --rm alpine:3.24.2 sh -c "apk update -q && apk policy asterisk php84 nginx"`

## Monatliche Prüfung auf GitHub

`.github/workflows/build.yml` baut das Image am 1. jedes Monats ohne Cache, startet es und lässt
`tests/smoke-test.sh` laufen. Schlägt das fehl, schickt GitHub eine Mail an die Person, die den Workflow zuletzt
geändert hat, und das Protokoll steht unter *Actions*. So fällt ein Problem mit den Alpine-Paketen auf, bevor ein
Update oder eine Neuinstallation bei Nutzern scheitert.

- Geplante Läufe prüfen immer den Standard-Branch (`modernisierung`). Die Datei muss dort liegen, damit sie läuft.
- Von Hand starten: *Actions → Build und Rauchtest → Run workflow*, dabei lässt sich auch ein anderer Branch wählen.
- Ohne Aktivität im Repository schaltet GitHub geplante Läufe nach 60 Tagen ab und kündigt das vorher per Mail an.
  Dann unter *Actions* wieder einschalten.

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

1. Änderungen committen.
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
