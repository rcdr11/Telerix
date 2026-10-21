<?php
/*
 * Anmeldung: Zugangsdaten, Session-Prüfung und Login-Drosselung.
 *
 * Die Zugangsdaten liegen nicht mehr im Repository (früher userdata.php),
 * sondern in TELERIX_DATA/config/admin.json. Angelegt wird die Datei beim
 * ersten Containerstart aus TELERIX_ADMIN_PASSWORD bzw.
 * TELERIX_ADMIN_PASSWORD_FILE (siehe bin/init-admin.php).
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/* Abmeldung nach 4 Stunden ohne Seitenaufruf (wie im Original). */
const SESSION_IDLE_TIMEOUT = 14400;

/* Höchstens 5 Fehlversuche je Client in 15 Minuten, danach 15 Minuten Sperre. */
const LOGIN_MAX_FAILS = 5;
/* Zusätzlich eine globale Grenze gegen verteilte Versuche. */
const LOGIN_MAX_FAILS_GLOBAL = 30;
const LOGIN_WINDOW = 900;
const LOGIN_LOCK = 900;

const PASSWORD_MIN_LENGTH = 10;

function admin_file(): string
{
   return data_path('config/admin.json');
}

/* Liefert ['user' => ..., 'hash' => ...] oder null, wenn nicht eingerichtet. */
function admin_load(): ?array
{
   $raw = @file_get_contents(admin_file());
   if ($raw === false) {
      return null;
   }
   $a = json_decode($raw, true);
   if (!is_array($a) || !is_string($a['user'] ?? null) || !is_string($a['hash'] ?? null)) {
      return null;
   }
   return $a;
}

function admin_save(string $user, string $password): void
{
   $json = json_encode(
      ['user' => $user, 'hash' => password_hash($password, PASSWORD_DEFAULT)],
      JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
   );
   atomic_write(admin_file(), $json . "\n", 0600);
}

function valid_username(string $user): bool
{
   return preg_match('/^[A-Za-z0-9._-]{1,64}$/', $user) === 1;
}

/* Prüft Benutzer und Passwort in konstanter Zeit bezüglich des Benutzernamens. */
function admin_verify(string $user, string $password): bool
{
   $a = admin_load();
   if ($a === null) {
      return false;
   }
   $userOk = hash_equals($a['user'], $user);
   $passOk = password_verify($password, $a['hash']);
   return $userOk && $passOk;
}

function is_logged_in(): bool
{
   return ($_SESSION['status'] ?? null) === 'logged in';
}

function login_session(string $user): void
{
   /* Neue Session-ID gegen Session-Fixation, neues CSRF-Token. */
   session_regenerate_id(true);
   unset($_SESSION['csrf']);
   $_SESSION['status'] = 'logged in';
   $_SESSION['user'] = $user;
   $_SESSION['visit_time'] = time();
}

function logout_session(): void
{
   $_SESSION = [];
   $p = session_get_cookie_params();
   setcookie(session_name(), '', [
      'expires'  => time() - 3600,
      'path'     => $p['path'],
      'secure'   => $p['secure'],
      'httponly' => true,
      'samesite' => 'Strict',
   ]);
   session_destroy();
}

/* Für alle geschützten Seiten: ohne gültige Anmeldung zurück zum Login. */
function require_login(): void
{
   telerix_session_start();
   if (!is_logged_in()) {
      redirect('index.php');
   }
   if (time() - (int)($_SESSION['visit_time'] ?? 0) > SESSION_IDLE_TIMEOUT) {
      logout_session();
      redirect('index.php');
   }
   $_SESSION['visit_time'] = time();
}

/* ---------- Login-Drosselung ---------- */

function throttle_file(): string
{
   return data_path('state/login_attempts.json');
}

/*
 * Liest und schreibt den Zustand unter exklusiver Sperre.
 * $fn bekommt den Zustand per Referenz und liefert das Ergebnis.
 */
function throttle_locked(callable $fn): mixed
{
   $fh = fopen(throttle_file(), 'c+');
   if ($fh === false) {
      throw new RuntimeException('Login-Zustand nicht verfügbar');
   }
   try {
      flock($fh, LOCK_EX);
      $state = json_decode(stream_get_contents($fh) ?: '[]', true);
      if (!is_array($state)) {
         $state = [];
      }
      $now = time();
      foreach ($state as $k => $e) {
         if ($e['first'] + LOGIN_WINDOW < $now && $e['locked_until'] < $now) {
            unset($state[$k]);
         }
      }
      $result = $fn($state, $now);
      ftruncate($fh, 0);
      rewind($fh);
      fwrite($fh, json_encode($state));
      fflush($fh);
      return $result;
   } finally {
      flock($fh, LOCK_UN);
      fclose($fh);
   }
}

function throttle_key(): string
{
   return hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

/* Liefert die verbleibende Sperrzeit in Sekunden (0 = nicht gesperrt). */
function throttle_remaining(): int
{
   $key = throttle_key();
   return throttle_locked(function (array &$state, int $now) use ($key): int {
      $wait = 0;
      foreach ([$key, '*'] as $k) {
         if (isset($state[$k])) {
            $wait = max($wait, $state[$k]['locked_until'] - $now);
         }
      }
      return max(0, $wait);
   });
}

function throttle_fail(): void
{
   $key = throttle_key();
   throttle_locked(function (array &$state, int $now) use ($key): void {
      foreach ([$key => LOGIN_MAX_FAILS, '*' => LOGIN_MAX_FAILS_GLOBAL] as $k => $max) {
         $e = $state[$k] ?? ['fails' => 0, 'first' => $now, 'locked_until' => 0];
         if ($e['first'] + LOGIN_WINDOW < $now) {
            $e = ['fails' => 0, 'first' => $now, 'locked_until' => 0];
         }
         $e['fails']++;
         if ($e['fails'] >= $max) {
            $e['locked_until'] = $now + LOGIN_LOCK;
         }
         $state[$k] = $e;
      }
   });
}

function throttle_reset(): void
{
   $key = throttle_key();
   throttle_locked(function (array &$state, int $now) use ($key): void {
      unset($state[$key]);
   });
}
