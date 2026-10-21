<?php
/*
 * Prüft Adressangaben aus Umgebungsvariablen für docker/entrypoint.sh.
 *
 *   php84 check-address.php proxies "<Liste>"
 *      Liste von IPv4/IPv6-Adressen oder CIDR-Netzen, getrennt durch Komma
 *      oder Leerzeichen. Gibt die Einträge einzeln je Zeile aus.
 *   php84 check-address.php external "<Wert>"
 *      Öffentliche IPv4-Adresse oder Hostname. Gibt den Wert aus.
 *
 * Bei einem ungültigen Eintrag: Meldung auf stderr, Exitcode 1. Ungültige
 * Werte werden bewusst nicht übergangen: Ein falsch geschriebener Proxy
 * würde sonst still die Drosselung aushebeln bzw. abschalten.
 */
declare(strict_types=1);

function fail(string $msg): never
{
   fwrite(STDERR, "telerix: $msg\n");
   exit(1);
}

/* IPv4/IPv6-Adresse oder CIDR-Netz mit passender Präfixlänge. */
function valid_ip_or_cidr(string $v): bool
{
   $parts = explode('/', $v);
   if (count($parts) > 2) {
      return false;
   }
   $ip = $parts[0];
   if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
      $max = 32;
   } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
      $max = 128;
   } else {
      return false;
   }
   if (count($parts) === 1) {
      return true;
   }
   return preg_match('/^(0|[1-9][0-9]{0,2})$/', $parts[1]) === 1 && (int)$parts[1] <= $max;
}

/* Hostname nach RFC 1123, mindestens zwei Labels, letztes Label nicht rein numerisch. */
function valid_hostname(string $v): bool
{
   if (strlen($v) > 253) {
      return false;
   }
   $labels = explode('.', $v);
   if (count($labels) < 2 || ctype_digit(end($labels))) {
      return false;
   }
   foreach ($labels as $l) {
      if (preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/', $l) !== 1) {
         return false;
      }
   }
   return true;
}

[$mode, $value] = [$argv[1] ?? '', $argv[2] ?? ''];

switch ($mode) {
   case 'proxies':
      $entries = preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);
      foreach ($entries as $e) {
         if (!valid_ip_or_cidr($e)) {
            fail("TELERIX_TRUSTED_PROXIES: '$e' ist keine gültige IPv4/IPv6-Adresse und kein CIDR-Netz (z. B. 172.16.0.0/12).");
         }
         if (preg_match('#/0$#', $e)) {
            fail("TELERIX_TRUSTED_PROXIES: '$e' würde allen Absendern vertrauen; dann könnte jeder die Login-Drosselung per X-Forwarded-For umgehen.");
         }
         echo $e, "\n";
      }
      break;

   case 'external':
      $v = trim($value);
      if (filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false && !valid_hostname($v)) {
         fail("TELERIX_EXTERNAL_ADDRESS: '$v' ist keine gültige IPv4-Adresse und kein gültiger Hostname (z. B. 203.0.113.10 oder sip.example.org).");
      }
      echo $v, "\n";
      break;

   default:
      fail('Aufruf: check-address.php proxies|external <Wert>');
}
