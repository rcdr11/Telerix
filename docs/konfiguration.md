# Konfiguration und Betrieb auf einem Server

[← zurück zur README](../README.md)

## Einstellungen im Webinterface

| Seite | Einstellung |
| --- | --- |
| Konfiguration → Internetstream | Adresse des Icecast-MP3-Streams, z. B. `http://www.beispiel.de:8000/gottesdienst`. Fehlt `http://`, wird es ergänzt. Die neue Adresse gilt nach wenigen Sekunden, laufende Anrufe bleiben bestehen. |
| Konfiguration → SIP-Konfiguration | SIP-ID, Passwort und Registrar des Anbieters. Wird ohne Neustart geladen. Das gespeicherte Passwort wird nicht wieder angezeigt, ein leeres Feld behält es bei. Alle Felder leeren schaltet die Registrierung ab. |
| Konfiguration → Zugangsdaten ändern | Benutzername und Passwort für das Webinterface |
| Ansage | Eigene Begrüßungsansage hochladen (wav, mp3, ogg, flac bis 20 MB). Sie wird in 8 kHz Mono umgewandelt. Ohne eigene Ansage läuft `ansagen/willkommen-gottesdienst.gsm`. |
| Nummerverwaltung | Liste „Name Nummer“ (txt/csv, max. 1 MB). Statt der Nummer wird dann der Name des Anrufers angezeigt. |
| Sicherung | Konfiguration verschlüsselt herunterladen und wieder einspielen, siehe [Sicherung und Updates](sicherung-und-updates.md) |
| Updates | Prüfen, ob es eine neue Version gibt |

Eingehende Anrufe des Anbieters landen wie im Original auf Extension 200.

## Eigene Einstellungen: `.env` und `docker-compose.override.yml`

`docker-compose.yml` bitte **nicht** ändern, sonst bricht `update.sh` wegen lokaler Änderungen ab. Stattdessen:

- **Einstellungen** in die Datei `.env` im Telerix-Ordner, eine Zeile pro Einstellung. Vorlage:
  `cp .env.example .env`
- **Zusätzliche Ports oder Dienste** (z. B. SIP-Ports, eigener Proxy) in eine Datei
  `docker-compose.override.yml`. Docker Compose liest sie automatisch mit ein.

Beide Dateien werden nicht eingecheckt. Änderungen übernehmen mit `docker compose up -d`.

| Einstellung in `.env` | Bedeutung |
| --- | --- |
| `TELERIX_UPDATE_REPO` | GitHub-Repository für die Update-Prüfung, z. B. `rcdr11/Telerix`. Setzt `install.sh` automatisch. |
| `TELERIX_EXTERNAL_ADDRESS` | Öffentliche IPv4-Adresse oder Hostname für SIP/RTP, siehe [Kein Ton beim Anrufer](#kein-ton-beim-anrufer) |
| `TELERIX_TRUSTED_PROXIES` | Adresse(n) des Reverse-Proxys, siehe [Reverse-Proxy](#reverse-proxy-davor-setzen) |
| `TELERIX_COOKIE_SECURE` | `1` hinter einem HTTPS-Proxy: Das Session-Cookie wird dann nur über HTTPS gesendet. |
| `TELERIX_AUDIO_ANHEBUNG_DB` | Lautstärke am Telefon, 0 bis 15 dB (Standard 5 ≈ −20 dBFS), siehe [Tonqualität](tonqualitaet.md) |
| `TELERIX_AUDIO_PUFFER_SEKUNDEN` | Vorlauf gegen Stockungen des Streams, 0 bis 10 s (Standard 4). Um diese Zeit kommt der Ton verzögert an. |
| `TELERIX_TESTPHONE_PASSWORD` | Legt ein Testtelefon `testphone` an (mind. 12 Zeichen aus `A-Z a-z 0-9 . _ + -`). Extension 100 spielt Piepton und Stream. |

Ungültige Werte bei `TELERIX_EXTERNAL_ADDRESS` und `TELERIX_TRUSTED_PROXIES` verhindern den Start. Die Meldung
dazu zeigt `docker compose logs`.

## Passwort vergessen

1. Neues Passwort (mindestens 10 Zeichen) in `secrets/admin_password.txt` schreiben.
2. Übernehmen:

   ```sh
   docker compose exec telerix sh -c 'TELERIX_ADMIN_RESET=1 php84 /opt/telerix/bin/init-admin.php && chown telerix:telerix /var/lib/telerix/config/admin.json'
   ```

3. Mit Benutzer `admin` und dem neuen Passwort anmelden.

`secrets/admin_password.txt` nie löschen: Ohne die Datei startet der Container nicht mehr. Fehlte sie bei einem
Start doch einmal, legt Docker an ihrer Stelle ein leeres **Verzeichnis** an. Dieses Verzeichnis löschen und die
Datei neu anlegen.

## Betrieb auf einem Server

Die Installation ist dieselbe wie auf dem eigenen Rechner (`./install.sh`). Zusätzlich zu beachten:

1. **Webinterface nicht direkt freigeben.** Es lauscht nur auf `127.0.0.1:8080`. Für den Zugriff von außen
   einen Reverse-Proxy mit HTTPS (und am besten Basic-Auth) davorsetzen, siehe unten.
2. **Kein Ton beim Anrufer:** öffentliche Adresse eintragen, siehe unten.
3. **Updates** mit `./update.sh`, siehe [Sicherung und Updates](sicherung-und-updates.md).

### Reverse-Proxy davorsetzen

Beispiel mit nginx als eigenem Dienst im selben Compose-Projekt, in `docker-compose.override.yml`:

```yaml
services:
  proxy:
    image: nginx:1.30-alpine
    restart: unless-stopped
    ports:
      - "443:443"
    volumes:
      - ./proxy/nginx.conf:/etc/nginx/conf.d/default.conf:ro
      - ./proxy/certs:/etc/nginx/certs:ro
      - ./proxy/htpasswd:/etc/nginx/htpasswd:ro
    networks:
      default:
        ipv4_address: 172.30.0.10   # feste Adresse, siehe unten

networks:
  default:
    ipam:
      config:
        - subnet: 172.30.0.0/24
```

In der Proxy-Konfiguration:

```nginx
location / {
    auth_basic "Telerix";
    auth_basic_user_file /etc/nginx/htpasswd;
    proxy_pass http://telerix:8080;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

Und in `.env`:

```sh
TELERIX_TRUSTED_PROXIES=172.30.0.10
TELERIX_COOKIE_SECURE=1
```

**Warum `TELERIX_TRUSTED_PROXIES`?** Ohne die Einstellung sieht Telerix als Absender immer den Proxy. Die
Login-Sperre nach 5 Fehlversuchen trifft dann alle Besucher gemeinsam. Mit der Einstellung wertet Telerix den
Header `X-Forwarded-For` aus, aber nur, wenn die Anfrage von einer eingetragenen Adresse kommt, und sieht so die
echte IP jedes Besuchers.

**Nur den Proxy eintragen, keine Client-Netze.** Wer in der Liste steht, darf Telerix eine beliebige Client-IP
„vorsagen“. Ein breites Netz wie `172.16.0.0/12` (alle Docker-Netze) funktioniert, solange Besucher mit ihrer
echten öffentlichen IP beim Proxy ankommen. Kommen sie über eine Adresse aus diesem Netz an (z. B. über den
Docker-Gateway bei rootless Docker oder IPv6-Weiterleitung), könnten sie die Sperre mit einem gefälschten Header
umgehen. Deshalb im Beispiel die feste Adresse `172.30.0.10`.

Prüfen: `docker compose logs telerix`. Die Zugriffszeilen müssen mit der IP des Besuchers beginnen, nicht mit
`172.30.0.10`.

### Kein Ton beim Anrufer

Läuft der Server hinter NAT (Router, Cloud-Firewall), schickt Asterisk seine interne Adresse mit. Der Anbieter
weiß dann nicht, wohin er das Audio senden soll.

1. In `.env` die öffentliche Adresse eintragen:

   ```sh
   TELERIX_EXTERNAL_ADDRESS=203.0.113.10
   ```

   Ein Hostname (z. B. `sip.example.org`) geht auch. Er wird beim Start aufgelöst. Ändert sich die IP, Telerix
   danach neu starten.

2. SIP- und RTP-Ports freigeben, in `docker-compose.override.yml`:

   ```yaml
   services:
     telerix:
       ports:
         - "5060:5060/udp"
         - "10000-10099:10000-10099/udp"
   ```

   Diese Ports in der Firewall am besten nur für die Adressen deines SIP-Anbieters öffnen.

3. Übernehmen und prüfen:

   ```sh
   docker compose up -d
   docker compose exec telerix asterisk -rx "pjsip show transport transport-udp"
   ```

   `external_media_address` und `external_signaling_address` müssen die öffentliche Adresse zeigen.

Auf dem eigenen Rechner zu Hause ist das meist nicht nötig. Asterisk meldet sich beim Anbieter von innen an, und
eingehende Anrufe kommen über diese Verbindung zurück.

## Wo liegen die Daten?

Alles Veränderliche liegt im Docker-Volume `telerix_telerix-data` (im Container `/var/lib/telerix`):

| Verzeichnis | Inhalt |
| --- | --- |
| `config/` | Admin-Zugang (Passwort-Hash), SIP-Zugang |
| `state/` | Login-Sperren, Ergebnis der Update-Prüfung |
| `uploads/` | hochgeladene Namenslisten und Ansage (zufällige Dateinamen) |
| `asterisk/` | aus den Einstellungen erzeugte Asterisk-Dateien, Stream-Adresse |
| `sounds/` | umgewandelte Ansage |

`docker compose down` lässt das Volume bestehen. `docker compose down -v` löscht es mit allen Einstellungen.
