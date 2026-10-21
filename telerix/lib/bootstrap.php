<?php
/*
 * Gemeinsame Initialisierung für alle Seiten des Webinterface.
 *
 * Geändert gegenüber dem Original-Telerix (siehe README, Abschnitt
 * "Veränderte Version").
 */
declare(strict_types=1);

const TELERIX_VERSION = '0.04-mod';

/* Persistente Daten liegen außerhalb des Webroots (Docker-Volume). */
define('TELERIX_DATA', rtrim(getenv('TELERIX_DATA') ?: '/var/lib/telerix', '/'));

function data_path(string $rel): string
{
   return TELERIX_DATA . '/' . $rel;
}

/* Text für die Ausgabe in HTML maskieren. */
function h(?string $s): string
{
   return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/*
 * Datei atomar schreiben: erst in eine temporäre Datei im selben
 * Verzeichnis, Rechte setzen, dann umbenennen. So sieht Asterisk nie
 * eine halb geschriebene Konfiguration.
 */
function atomic_write(string $path, string $content, int $mode): void
{
   $tmp = tempnam(dirname($path), '.tmp-');
   if ($tmp === false) {
      throw new RuntimeException("Temporäre Datei für $path konnte nicht angelegt werden");
   }
   if (file_put_contents($tmp, $content) !== strlen($content) || !chmod($tmp, $mode) || !rename($tmp, $path)) {
      @unlink($tmp);
      throw new RuntimeException("$path konnte nicht geschrieben werden");
   }
}

/* ---------- Session ---------- */

function telerix_session_start(): void
{
   if (session_status() === PHP_SESSION_ACTIVE) {
      return;
   }
   $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
   $secure = $https || getenv('TELERIX_COOKIE_SECURE') === '1';

   ini_set('session.use_strict_mode', '1');
   ini_set('session.use_only_cookies', '1');
   ini_set('session.use_trans_sid', '0');
   session_name($secure ? '__Host-TELERIXSID' : 'TELERIXSID');
   session_set_cookie_params([
      'lifetime' => 0,
      'path'     => '/',
      'secure'   => $secure,
      'httponly' => true,
      'samesite' => 'Strict',
   ]);
   session_start();
}

/* ---------- Rückmeldungen über Weiterleitungen hinweg ---------- */

function flash(string $type, string $msg): void
{
   $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function take_flashes(): array
{
   $f = $_SESSION['flash'] ?? [];
   unset($_SESSION['flash']);
   return is_array($f) ? $f : [];
}

function redirect(string $page): never
{
   header('Location: ' . $page, true, 303);
   exit;
}

/* ---------- CSRF ---------- */

function csrf_token(): string
{
   if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
      $_SESSION['csrf'] = bin2hex(random_bytes(32));
   }
   return $_SESSION['csrf'];
}

function csrf_field(): string
{
   return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

/* Aktionen nur per POST mit gültigem CSRF-Token zulassen. */
function require_post_csrf(): void
{
   if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
      http_response_code(405);
      header('Allow: POST');
      exit('Methode nicht erlaubt.');
   }
   $token = $_POST['csrf'] ?? '';
   if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
      http_response_code(403);
      exit('Ungültiges oder abgelaufenes Formular. Bitte die Seite neu laden.');
   }
}

/* POST-Feld als String lesen (Arrays und fehlende Felder werden zu ''). */
function post_str(string $name): string
{
   $v = $_POST[$name] ?? '';
   return is_string($v) ? $v : '';
}
