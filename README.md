# Telerix – veränderte Version

> **Veränderte Version** von [Telerix 0.02 von Benjamin Hedert](https://github.com/beni1993/Telerix).
> Sicherheitslücken geschlossen, Asterisk auf PJSIP umgestellt, Betrieb in Docker.
> Fehler dieser Version bitte nicht im Original-Repository melden. Version: **0.04-mod**.

Telerix macht einen Internetradio-Stream (Icecast, MP3), zum Beispiel einen Gottesdienst, **per Telefon hörbar**.
Wer die Nummer anruft, hört eine Begrüßungsansage und danach den Stream. Eingestellt wird alles in einem
Webinterface im Browser.

## Installation

Du brauchst **Docker** (unter Windows und macOS: [Docker Desktop](https://www.docker.com/products/docker-desktop/))
und **Git** (unter Windows: [Git for Windows](https://git-scm.com/download/win), die Befehle dann in „Git Bash“ eingeben).

```sh
git clone https://github.com/rcdr11/Telerix.git
cd Telerix
./install.sh
```

Das Skript baut und startet Telerix und zeigt am Ende Adresse, Benutzer und Passwort an:

```text
Telerix läuft:  http://127.0.0.1:8080
Benutzer:       admin
Passwort:       Xy7…
```

## Einrichten

Im Browser <http://127.0.0.1:8080> öffnen, anmelden und dann:

1. **Eigenes Passwort setzen:** *Konfiguration → Zugangsdaten ändern*
2. **Stream eintragen:** *Konfiguration → Internetstream*, z. B. `http://www.beispiel.de:8000/gottesdienst`
3. **SIP-Zugang eintragen:** *Konfiguration → SIP-Konfiguration*, mit SIP-ID, Passwort und Registrar deines
   Telefonanbieters
4. Optional: eigene **Begrüßungsansage** hochladen (*Ansage*) und eine **Namensliste** für die Anruferanzeige
   (*Nummerverwaltung*)

Danach die Nummer anrufen: Nach der Ansage läuft der Stream.

## Gut zu wissen

- **Nur auf diesem Rechner erreichbar:** Das Webinterface hört nur auf `127.0.0.1`. Für den Zugriff von anderen
  Geräten oder aus dem Internet gehört ein HTTPS-Reverse-Proxy davor, siehe [Betrieb auf einem Server](docs/konfiguration.md#betrieb-auf-einem-server).
- **`secrets/admin_password.txt` nicht löschen.** Ohne diese Datei startet der Container nicht mehr. Das Passwort
  darin gilt nur für den allerersten Start. Danach gilt das im Webinterface gesetzte Passwort.
- **Daten bleiben erhalten** beim Stoppen, Neustarten und bei Updates. Sie liegen im Docker-Volume
  `telerix_telerix-data`. Nur `docker compose down -v` löscht sie.
- **Sicherung:** Auf der Seite *Sicherung* die Konfiguration als verschlüsselte Datei herunterladen. Das
  Backup-Passwort gut aufbewahren, ohne es lässt sich die Sicherung nicht einspielen.
- **Updates:** Die Seite *Updates* zeigt, ob es eine neue Version gibt. Aktualisiert wird mit `./update.sh`.
- **Kein Ton beim Anrufer?** Auf einem Server hinter NAT muss die öffentliche Adresse eingetragen werden,
  siehe [Kein Ton beim Anrufer](docs/konfiguration.md#kein-ton-beim-anrufer).

## Wichtige Befehle

Im Ordner `Telerix` ausführen:

| Was | Befehl |
| --- | --- |
| Starten | `docker compose up -d` |
| Stoppen | `docker compose down` |
| Status | `docker compose ps` |
| Protokoll ansehen | `docker compose logs -f` |
| Aktualisieren | `./update.sh` |
| Ist der SIP-Zugang angemeldet? | `docker compose exec telerix asterisk -rx "pjsip show registrations"` |

## Läuft das auch ohne Docker?

Vorbereitet und getestet ist nur der Betrieb mit Docker. Die alten Installationsskripte für Ubuntu wurden
entfernt, weil sie unsichere Rechte (sudo, chmod 777) eingerichtet haben. Grundsätzlich läuft der Code auch auf
einem normalen Linux mit Asterisk 22, nginx und PHP 8.4. Benutzer, Verzeichnisse und Rechte müsste man dann aber
von Hand so einrichten, wie es das [Dockerfile](Dockerfile) und [docker/entrypoint.sh](docker/entrypoint.sh) tun.

## Weitere Dokumentation

- [Konfiguration und Betrieb auf einem Server](docs/konfiguration.md): Einstellungen, Umgebungsvariablen,
  Reverse-Proxy, SIP/NAT, Passwort vergessen
- [Sicherung und Updates](docs/sicherung-und-updates.md)
- [Tonqualität am Telefon](docs/tonqualitaet.md): Aufbereitung des Streams, Messwerte, Nachjustieren
- [Was wurde gegenüber dem Original geändert?](docs/aenderungen.md): Sicherheit, PJSIP, bekannte Einschränkungen
- [Entwicklung](docs/entwicklung.md): Aufbau, Tests, neue Version veröffentlichen

## Lizenz

zlib-Lizenz. Diese Fassung ist gemäß Punkt 2 der Lizenz als veränderte Version gekennzeichnet (Hinweis oben,
Info-Seite und Fußzeile des Webinterface). Der ursprüngliche Lizenzhinweis von Benjamin Hedert steht unverändert
in [LICENSE](LICENSE).
