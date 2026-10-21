<?php
/*
 * Legt beim ersten Containerstart die Admin-Zugangsdaten an.
 *
 * Das Passwort kommt aus TELERIX_ADMIN_PASSWORD_FILE (z. B. ein Docker-Secret
 * unter /run/secrets/...) oder TELERIX_ADMIN_PASSWORD. Existiert
 * config/admin.json bereits, bleibt sie unverändert – danach wird das
 * Passwort im Webinterface geändert. Mit TELERIX_ADMIN_RESET=1 wird sie
 * überschrieben (Passwort vergessen).
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';

function fail(string $msg): never
{
   fwrite(STDERR, "telerix: $msg\n");
   exit(1);
}

$reset = getenv('TELERIX_ADMIN_RESET') === '1';
if (admin_load() !== null && !$reset) {
   echo "telerix: Admin-Zugangsdaten vorhanden, keine Änderung.\n";
   exit(0);
}

$user = getenv('TELERIX_ADMIN_USER') ?: 'admin';
if (!valid_username($user)) {
   fail('TELERIX_ADMIN_USER darf nur A-Z, a-z, 0-9, Punkt, Unterstrich und Bindestrich enthalten (max. 64 Zeichen).');
}

$file = getenv('TELERIX_ADMIN_PASSWORD_FILE') ?: '';
if ($file !== '') {
   $password = @file_get_contents($file);
   if ($password === false) {
      fail("Passwortdatei $file ist nicht lesbar.");
   }
   $password = rtrim($password, "\r\n");
} else {
   $password = getenv('TELERIX_ADMIN_PASSWORD') ?: '';
}

if ($password === '') {
   fail('Kein Admin-Passwort gesetzt. Bitte TELERIX_ADMIN_PASSWORD_FILE oder TELERIX_ADMIN_PASSWORD angeben (siehe README).');
}
if (strlen($password) < PASSWORD_MIN_LENGTH) {
   fail('Das Admin-Passwort muss mindestens ' . PASSWORD_MIN_LENGTH . ' Zeichen lang sein.');
}

admin_save($user, $password);
echo "telerix: Admin-Zugangsdaten für Benutzer '$user' angelegt.\n";
