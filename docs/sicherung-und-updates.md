# Sicherung und Updates

[← zurück zur README](../README.md)

## Sicherung im Webinterface

Auf der Seite **Sicherung**:

- **Herunterladen:** Admin-Zugang, SIP-Zugang, Stream-Adresse, Nummernliste und die eigene Ansage werden als eine
  Datei `telerix-backup-….tlxbak` gespeichert. Sie ist mit einem selbst gewählten Backup-Passwort (mindestens
  10 Zeichen) verschlüsselt. **Ohne dieses Passwort lässt sich die Sicherung nicht wieder einspielen.**
- **Einspielen:** ersetzt die komplette Konfiguration, auch den Admin-Zugang. Danach gelten Benutzername und
  Passwort aus der Sicherung. Manipulierte oder fremde Dateien werden abgewiesen.

Nicht enthalten sind das Original der hochgeladenen Ansage (nur die umgewandelte Fassung), die Login-Sperren und
die Einstellungen aus `.env`.

## Sicherung des ganzen Datenvolumes

`update.sh` sichert das Volume vor jedem Update automatisch nach `backups/`. Von Hand:

```sh
docker run --rm -v telerix_telerix-data:/data -v "$PWD":/backup alpine:3.24.2 \
  tar czf /backup/telerix-daten.tgz -C /data .
```

Diese Archive sind **nicht verschlüsselt** und enthalten das SIP-Passwort im Klartext.

## Updates

Die Seite **Updates** zeigt, ob es auf GitHub eine neue Version gibt, und listet die Änderungen auf. Aktualisiert
wird im Telerix-Ordner mit:

```sh
./update.sh
```

Das Skript zeigt die Änderungen, fragt nach und dann:

1. sichert es das Datenvolume nach `backups/`,
2. holt es die neue Version,
3. baut es Telerix neu. Klappt das nicht, bleibt alles beim Alten.
4. startet es Telerix neu. Laufende Anrufe werden dabei getrennt.

Mit `./update.sh -y` läuft es ohne Rückfrage.

**Warum kein Update-Knopf im Webinterface?** Damit sich Telerix selbst ersetzen könnte, bräuchte der Container
Zugriff auf Docker. Das entspricht Administratorrechten auf dem ganzen Rechner, und jede Lücke im Webinterface
würde dann wieder zum Vollzugriff führen.

**Hinweise:**

- `update.sh` bricht ab, wenn Dateien im Repository verändert wurden (z. B. `docker-compose.yml`). Eigene
  Einstellungen deshalb in `.env` bzw. `docker-compose.override.yml`, siehe [Konfiguration](konfiguration.md).
- Die Update-Prüfung braucht `TELERIX_UPDATE_REPO` in `.env`. `install.sh` setzt das automatisch.
- Neue Versionen erscheinen erst, wenn im Repository ein Release veröffentlicht wurde, siehe
  [Entwicklung](entwicklung.md#neue-version-veröffentlichen).
