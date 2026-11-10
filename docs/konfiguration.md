# Konfiguration und Betrieb auf einem Server

[← zurück zur README](../README.md)

## Einstellungen im Webinterface

| Seite | Einstellung |
| --- | --- |
| Konfiguration → Internetstream | Adresse des Icecast-MP3-Streams, z. B. `http://www.beispiel.de:8000/gottesdienst`. Fehlt `http://`, wird es ergänzt. Die neue Adresse gilt nach wenigen Sekunden, laufende Anrufe bleiben bestehen. Darunter stehen Bitrate, Abtastrate und Kanäle des Streams, unter 128 kbit/s mit einem Hinweis. Darunter: Icecast-Status auf der Startseite ein- oder ausblenden und bei Bedarf seine Adresse eintragen, siehe [Icecast-Status](#icecast-status-auf-der-startseite). |
| Konfiguration → SIP-Konfiguration | SIP-ID, Passwort und Registrar des Anbieters. Wird ohne Neustart geladen. Das gespeicherte Passwort wird nicht wieder angezeigt, ein leeres Feld behält es bei. Alle Felder leeren schaltet die Registrierung ab. |
| Konfiguration → Ton am Telefon | Hauptschalter *Tonaufbereitung* (aus = nur Mono und 16 kHz), Hochpass (aus bis 250 Hz), Sprachverständlichkeit (Präsenz, aus bis +4 dB), Klangprofil (aus, Sprache, Sprache und Musik), Lautstärke (Schieberegler −24 bis +24 dB), Vorlauf gegen Stockungen (0 bis 10 s, Standard 4), Codec (HD oder Telefonqualität bevorzugen) und abgesetzt der Eingangspegel für zu laute Streams (−12 bis 0 dB). Standard: Aufbereitung an, aber alles aus bis auf einen Begrenzer, der seltene Spitzen über etwa −3,5 dBFS kappt; Codec HD. Ton gilt nach wenigen Sekunden, auch für laufende Anrufe, der Codec ab dem nächsten Anruf. Die Hörprobe nimmt 20 s des Streams mit den gewählten Werten auf, ohne zu speichern, und zeigt die Pegel. Siehe [Tonqualität](tonqualitaet.md). |
| Konfiguration → Öffentliche Adresse | Öffentliche IPv4-Adresse oder Hostname für SIP/RTP, siehe [Kein Ton beim Anrufer](#kein-ton-beim-anrufer). Gilt nach einem Neustart von Asterisk. |
| Konfiguration → Testtelefon | Testtelefon `testphone` mit Passwort (mind. 12 Zeichen aus `A-Z a-z 0-9 . _ + -`). Extension 100 spielt Piepton und Stream. |
| Konfiguration → Zugangsdaten ändern | Benutzername und Passwort für das Webinterface |
| Konfiguration → Nur über .env einstellbar | zeigt die Werte, die bewusst nur über `.env` gehen (siehe unten) |
| Ansage | Begrüßungsansage ein- und ausschalten. Eigene Ansage hochladen (wav, mp3, ogg, flac bis 20 MB), sie wird in Mono umgewandelt, in 16 kHz für HD-Anrufer und 8 kHz für alle anderen, mit Hochpass und Präsenz wie beim Stream und auf einen festen Sprachpegel gebracht, siehe [Begrüßungsansage](tonqualitaet.md#begrüßungsansage). Ohne eigene Ansage läuft `ansagen/willkommen-gottesdienst.gsm`. Abgeschaltet hören Anrufer gleich nach dem Annehmen den Stream. |
| Nummerverwaltung | Liste „Name Nummer“ (txt/csv, max. 1 MB). Statt der Nummer wird dann der Name des Anrufers angezeigt. |
| Startseite | Laufende Anrufer mit Codec (HD oder Telefonqualität), siehe [HD oder Telefonqualität?](tonqualitaet.md#hd-oder-telefonqualität). Qualität des Streams (Bitrate) im Kasten des Icecast-Status. Anrufer und Icecast-Status werden jede Minute neu geladen, solange die Seite sichtbar ist. Das zählt nicht als Bedienung: Nach 4 Stunden ohne Bedienung wird trotzdem abgemeldet. |
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

**Nur über `.env`** gehen diese Einstellungen. Sie greifen in den Start des Containers ein, und ein falscher Wert
ließe sich im Webinterface nicht mehr korrigieren:

| Einstellung in `.env` | Bedeutung |
| --- | --- |
| `TELERIX_UPDATE_REPO` | GitHub-Repository für die Update-Prüfung, z. B. `rcdr11/Telerix`. Setzt `install.sh` automatisch. |
| `TELERIX_TRUSTED_PROXIES` | Adresse(n) des Reverse-Proxys, siehe [Reverse-Proxy](#reverse-proxy-davor-setzen) |
| `TELERIX_COOKIE_SECURE` | `1` hinter einem HTTPS-Proxy: Das Session-Cookie wird dann nur über HTTPS gesendet. |
| `TELERIX_SIP_ALLOW` | SIP nur von diesen Netzen annehmen (ab 0.07-mod, Standard leer = von überall), siehe [Ports und Firewall](#ports-und-firewall) |

**Im Webinterface oder über `.env`** gehen diese. Ist die Variable in `.env` gesetzt, hat sie Vorrang: Das Feld im
Webinterface ist dann gesperrt und zeigt den Wert aus `.env`. Wer lieber im Webinterface einstellt, löscht die
Zeile aus `.env` und führt `docker compose up -d` aus.

| Einstellung in `.env` | Im Webinterface | Bedeutung |
| --- | --- | --- |
| `TELERIX_AUDIO_ANHEBUNG_DB` | Ton am Telefon | Lautstärke am Telefon, −24 bis 24 dB (Standard 0 = unverändert), siehe [Tonqualität](tonqualitaet.md) |
| `TELERIX_AUDIO_PUFFER_SEKUNDEN` | Ton am Telefon | Vorlauf gegen Stockungen des Streams, 0 bis 10 s (Standard 4). Um diese Zeit kommt der Ton verzögert an. |
| `TELERIX_EXTERNAL_ADDRESS` | Öffentliche Adresse | Öffentliche IPv4-Adresse oder Hostname für SIP/RTP, siehe [Kein Ton beim Anrufer](#kein-ton-beim-anrufer) |
| `TELERIX_TESTPHONE_PASSWORD` | Testtelefon | Legt ein Testtelefon `testphone` an (mind. 12 Zeichen aus `A-Z a-z 0-9 . _ + -`). Extension 100 spielt Piepton und Stream. |

Ungültige Werte bei `TELERIX_EXTERNAL_ADDRESS`, `TELERIX_TESTPHONE_PASSWORD`, `TELERIX_TRUSTED_PROXIES` und
`TELERIX_SIP_ALLOW` verhindern den Start, ungültige Tonwerte werden ignoriert. Die Meldung dazu zeigt `docker compose logs`.

## Icecast-Status auf der Startseite

Der Kasten *Internetradiostreams* zeigt, wie viele Zuhörer den Stream gerade im Internet hören. Telerix liest dazu
die Statusseite `status-json.xsl` von Icecast (ab Version 2.4) und zeigt nur den eigenen Mountpoint, sofern er
sich über den Pfad der Stream-Adresse zuordnen lässt. Telerix selbst zählt dabei als ein Zuhörer, denn die
Wartemusik hält eine Verbindung offen. Das Ergebnis wird 30 Sekunden zwischengespeichert.

Ohne eingetragene Statusadresse probiert Telerix nacheinander:

1. das Verzeichnis der Stream-Adresse: aus `https://radio.example.org/listen/gemeinde/radio.mp3` wird
   `https://radio.example.org/listen/gemeinde/status-json.xsl`. So ist es bei **AzuraCast**, das intern Icecast
   verwendet und Stream und Status unter `/listen/<station>/` weiterreicht.
2. die Wurzel des Servers: `https://radio.example.org/status-json.xsl`, wie bei einem Icecast, der direkt
   erreichbar ist (z. B. `http://radio.example.org:8000/status-json.xsl`).

Klappt beides nicht, zeigt die Startseite den Grund (z. B. HTTP 404 oder keine Antwort). Dann unter
*Konfiguration → Internetstream* die Statusadresse selbst eintragen, z. B. `http://radio.example.org:8000`;
`/status-json.xsl` wird ergänzt. Gibt es keinen Icecast-Status (z. B. bei Shoutcast oder einem Streaming-Dienst),
lässt sich der Kasten auf der Startseite ausblenden. Wieder einblenden: Häkchen unter *Konfiguration →
Internetstream*.

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
3. **Ports und Firewall:** SIP und RTP nur für den Anbieter öffnen, siehe unten.
4. **Updates** mit `./update.sh`, siehe [Sicherung und Updates](sicherung-und-updates.md).
5. **Protokolle** begrenzen, auch für eigene Dienste, siehe [Protokolle](#protokolle).

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

1. Im Webinterface unter *Konfiguration → Öffentliche Adresse* die öffentliche Adresse eintragen, z. B.
   `203.0.113.10`, und Asterisk neu starten (Knopf darunter). Alternativ in `.env`:

   ```sh
   TELERIX_EXTERNAL_ADDRESS=203.0.113.10
   ```

   Ein Hostname (z. B. `sip.example.org`) geht auch. Er wird beim Start aufgelöst. Ändert sich die IP, Asterisk
   bzw. Telerix danach neu starten.

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

### Ports und Firewall

| Port | Wofür | Wer muss heran? |
| --- | --- | --- |
| 5060/udp | SIP (Anmeldung beim Anbieter, eingehende Anrufe) | nur der SIP-Anbieter |
| 10000–10099/udp | RTP (Ton der Anrufe, `rtp.conf`) | nur der SIP-Anbieter |
| 8080/tcp | Webinterface | niemand von außen: lauscht nur auf `127.0.0.1`; von außen über einen [Reverse-Proxy](#reverse-proxy-davorsetzen) mit HTTPS, am besten auch dort nur für die eigenen Adressen |

Ein offener Port 5060 wird binnen Minuten von **SIP-Scannern** aus dem ganzen Internet gefunden, die Anmeldedaten
durchprobieren. Telerix weist sie ab (keine anonymen Anrufe, nur bekannte Gegenstellen), aber sie kosten Rechenzeit
und füllen das Protokoll. Darum die Ports in der Firewall des Servers bzw. des Cloud-Anbieters **nur für die
IP-Bereiche des SIP-Anbieters** öffnen. Für sipgate sind das laut
[sipgate-Hilfe](https://help.sipgate.de/trunking/en/was-sind-die-ip-und-port-bereiche-von-sipgate) derzeit
`217.10.64.0/20`, `217.116.112.0/20` und `212.9.32.0/19`. Die Bereiche können sich ändern, bitte dort nachsehen.

Beispiel mit `ufw` (Ubuntu). Achtung: Von Docker veröffentlichte Ports umgehen `ufw`. Am sichersten ist deshalb die
Firewall des Cloud-Anbieters oder die Kette `DOCKER-USER`:

```sh
# DOCKER-USER: alles an 5060 und 10000-10099/udp verwerfen, was nicht von sipgate kommt
for net in 217.10.64.0/20 217.116.112.0/20 212.9.32.0/19; do
   iptables -I DOCKER-USER -p udp -s "$net" -m multiport --dports 5060,10000:10099 -j RETURN
done
iptables -A DOCKER-USER -p udp -m multiport --dports 5060,10000:10099 -j DROP
```

(Die Regeln der Kette `DOCKER-USER` gehen beim Neustart verloren, außer man sichert sie, z. B. mit
`iptables-persistent`. Die `RETURN`-Regeln müssen vor der `DROP`-Regel stehen.)

**Zusätzlich in Asterisk (optional, ab 0.07-mod):** `TELERIX_SIP_ALLOW` in `.env` lässt Asterisk SIP-Anfragen von
anderen Adressen abweisen, bevor sie geprüft werden (PJSIP-ACL). localhost und die privaten Netze (`10.0.0.0/8`,
`172.16.0.0/12`, `192.168.0.0/16`, für Docker und ein Testtelefon im Heimnetz) bleiben immer erlaubt. Standard ist
leer, dann gilt wie bisher keine Einschränkung.

```sh
TELERIX_SIP_ALLOW=217.10.64.0/20,217.116.112.0/20,212.9.32.0/19
```

Danach `docker compose up -d`. Im Protokoll steht beim Start `SIP nur von ...`. Abgewiesene Anfragen fasst Telerix
stündlich zusammen („von der ACL abgewiesen“). Die ACL ersetzt die Firewall nicht: Die Pakete erreichen den Server
trotzdem. Und sie wirkt nur, wenn Asterisk die echte Absenderadresse sieht. Mit dem Userland-Proxy von Docker
(z. B. Docker Desktop, rootless Docker) kommen alle Pakete scheinbar vom Docker-Gateway (privates Netz) und werden
durchgelassen.

## Protokolle

Alles, was Telerix meldet, steht im Container-Protokoll:

```sh
docker compose logs --since 1h telerix                 # letzte Stunde
docker compose logs -f telerix                         # laufend mitlesen
docker compose logs --since 24h telerix | grep -E "telerix-stream|Anruf"   # Wartemusik und Anrufe
```

Was dort steht (ab 0.07-mod):

- **Wartemusik** (`telerix-stream:`): Verbindungsaufbau, jeder Abbruch mit Grund („keine Daten seit 15 s“,
  „Verbindung beendet“, „keine Verbindung (HTTP-Fehler …)“) und „wieder verbunden“, außerdem eine Warnung, wenn
  der Stream länger als 2 Minuten nur Stille liefert. Bei längerem Ausfall wird nach 5 Fehlversuchen nur noch
  jeder zehnte gemeldet. Siehe [Tonqualität](tonqualitaet.md#wenn-der-stream-hängt).
- **Anrufe** ohne Rufnummer: beim Annehmen der Codec, beim Auflegen Dauer, Grund und wer aufgelegt hat, z. B.
  `Anruf 1794317000.12 beendet: Dauer 1834 s, Grund 0 (ohne Angabe), aufgelegt von Anrufer`. Beide Zeilen tragen
  dieselbe Kennung. „aufgelegt von Telerix (aufgelegt oder neu gestartet)“ heißt: über „Alle Anrufer auflegen“,
  „Asterisk neu starten“ oder ein Update. Legt der Anrufer normal auf, meldet Asterisk 22 den Grund 0 oder 16
  (normal); andere Werte sind [Q.850-Codes](https://www.voip-info.org/asterisk-variable-hangupcause/), z. B. 38
  oder 41 bei Netzstörungen.
- **SIP-Scanner** einmal je Stunde zusammengefasst: Anzahl der abgewiesenen Anfragen und ungültigen Pakete, Gründe
  und die häufigsten Absender. Einzelne Meldungen dazu gibt es nicht mehr. Fehler bei der Anmeldung beim eigenen
  Anbieter (z. B. falsches Passwort) erscheinen dagegen wie bisher sofort und einzeln. Taucht die Adresse des
  eigenen Anbieters in der Zusammenfassung auf, stimmt etwas mit der Einrichtung nicht.
- Zugriffe auf das Webinterface (nginx), ohne die Abfragen des Docker-Healthchecks.

Die Startseite zeigt unter **Wartemusik**, wann zuletzt Daten vom Stream ankamen, warnt, wenn das länger als 30 s her
ist oder der Stream nur Stille liefert, und zählt die Anrufe der letzten 24 Stunden mit Durchschnittsdauer und Anteil
der Anrufe unter 30 s. Viele sehr kurze Anrufe sind ein Hinweis, dass Anrufer nichts hören.

**Größe begrenzen.** Docker hebt das Protokoll eines Containers sonst unbegrenzt auf (ein Server kam so auf 4,5 GB in
18 Tagen). Für Telerix ist ab 0.07-mod in `docker-compose.yml` festgelegt: höchstens 5 Dateien zu 10 MB. Das gilt
nach dem Update bzw. nach `docker compose up -d`; das alte, große Protokoll verschwindet dabei mit dem alten
Container. Asterisk schreibt keine eigene Datei `messages.log` mehr (sie enthielt alles doppelt und wuchs ebenfalls
ohne Grenze, weil im Container kein `logrotate` läuft).

**Eigene Dienste** in `docker-compose.override.yml` (z. B. ein eigener nginx-Proxy) bekommen diese Begrenzung nicht
automatisch. Dort genauso eintragen:

```yaml
services:
  proxy:
    image: nginx:1.30-alpine
    # ... wie oben ...
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "5"
```

Für alle Container auf dem Server auf einmal geht es auch in `/etc/docker/daemon.json` (gilt nur für neu erzeugte
Container, danach `systemctl restart docker` und die Container neu erzeugen):

```json
{ "log-driver": "json-file", "log-opts": { "max-size": "10m", "max-file": "5" } }
```

Wie groß die Protokolle gerade sind:

```sh
docker ps -q | xargs docker inspect -f '{{.Name}} {{.LogPath}}' | while read n p; do echo "$n $(sudo du -h "$p" | cut -f1)"; done
```

## Wo liegen die Daten?

Alles Veränderliche liegt im Docker-Volume `telerix_telerix-data` (im Container `/var/lib/telerix`):

| Verzeichnis | Inhalt |
| --- | --- |
| `config/` | Admin-Zugang (Passwort-Hash), SIP-Zugang, Einstellungen aus dem Webinterface (`settings.json`) |
| `state/` | Login-Sperren, Ergebnis der Update-Prüfung, Zwischenspeicher für Icecast-Status, Qualität des Streams und Hörprobe (feste Größe) |
| `uploads/` | hochgeladene Namenslisten und Ansage (zufällige Dateinamen) |
| `asterisk/` | aus den Einstellungen erzeugte Dateien für Asterisk und die Tonaufbereitung, Stream-Adresse |
| `sounds/` | umgewandelte Ansage |

Nicht im Volume (nach einem Update neu): der Zustand der Wartemusik (`/run/telerix-stream/status`) und die
Anrufstatistik (AstDB von Asterisk).

`docker compose down` lässt das Volume bestehen. `docker compose down -v` löscht es mit allen Einstellungen.
