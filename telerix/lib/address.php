<?php
/*
 * Prüfung von Adressangaben: öffentliche Adresse für SIP/RTP und
 * Proxy-Adressen. Gemeinsam für bin/check-address.php (Containerstart) und
 * das Webinterface.
 */
declare(strict_types=1);

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
   if (count($labels) < 2 || preg_match('/^[0-9]+$/', end($labels)) === 1) {
      return false;
   }
   foreach ($labels as $l) {
      if (preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/', $l) !== 1) {
         return false;
      }
   }
   return true;
}

/* Öffentliche Adresse für SIP/RTP: IPv4-Adresse oder Hostname. */
function valid_external_address(string $v): bool
{
   return filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false || valid_hostname($v);
}
