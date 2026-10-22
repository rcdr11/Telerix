<?php
/*
 * Prüft TELERIX_TRUSTED_PROXIES für docker/entrypoint.sh.
 *
 *   php84 check-address.php proxies "<Liste>"
 *      Liste von IPv4/IPv6-Adressen oder CIDR-Netzen, getrennt durch Komma
 *      oder Leerzeichen. Gibt die Einträge einzeln je Zeile aus.
 *
 * (Die öffentliche Adresse für SIP/RTP prüft jetzt bin/render-config.php.)
 *
 * Bei einem ungültigen Eintrag: Meldung auf stderr, Exitcode 1. Ungültige
 * Werte werden bewusst nicht übergangen: Ein falsch geschriebener Proxy
 * würde sonst still die Drosselung aushebeln bzw. abschalten.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/address.php';

function fail(string $msg): never
{
   fwrite(STDERR, "telerix: $msg\n");
   exit(1);
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

   default:
      fail('Aufruf: check-address.php proxies <Liste>');
}
