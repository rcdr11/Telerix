<?php
/*
 * SIP-Zugang beim Anbieter (PJSIP).
 *
 * Ersetzt set_sipconfig.sh/get_sip_*.sh, die per sed Zeile 10 der
 * chan_sip-Datei sip.conf umgeschrieben haben. Die Eingaben werden jetzt
 * streng geprüft und als eigene PJSIP-Include-Datei geschrieben, die
 * /etc/asterisk/pjsip.conf per #include lädt.
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/* Rohdaten (Klartext-Passwort für Asterisk nötig), nur für "telerix" lesbar. */
function sip_file(): string
{
   return data_path('config/sip.json');
}

/* Von Asterisk gelesene, erzeugte Konfiguration. */
function sip_trunk_conf_file(): string
{
   return data_path('asterisk/pjsip_trunk.conf');
}

function sip_load(): array
{
   $empty = ['user' => '', 'password' => '', 'registrar' => ''];
   $raw = @file_get_contents(sip_file());
   if ($raw === false) {
      return $empty;
   }
   $s = json_decode($raw, true);
   if (!is_array($s)) {
      return $empty;
   }
   foreach ($empty as $k => $_) {
      $empty[$k] = is_string($s[$k] ?? null) ? $s[$k] : '';
   }
   return $empty;
}

/* "sip:host:port", "host:port/" usw. auf "host[:port]" vereinfachen. */
function sip_normalize_registrar(string $reg): string
{
   $reg = trim($reg);
   $reg = preg_replace('/^sips?:/i', '', $reg);
   return rtrim($reg, '/');
}

/* Liefert eine Fehlermeldung oder null, wenn alles gültig ist. */
function sip_validate(string $user, string $password, string $registrar): ?string
{
   if ($user === '' && $password === '' && $registrar === '') {
      return null; /* Leeren = Registrierung abschalten */
   }
   if (preg_match('/^[A-Za-z0-9._+\-]{1,64}$/', $user) !== 1) {
      return 'SIP-ID: erlaubt sind A-Z, a-z, 0-9 sowie . _ + - (max. 64 Zeichen).';
   }
   /* Druckbares ASCII ohne Leerzeichen und Backslash; ";" wird maskiert. */
   if (preg_match('/^[\x21-\x5B\x5D-\x7E]{1,128}$/', $password) !== 1) {
      return 'Passwort: 1 bis 128 druckbare ASCII-Zeichen, ohne Leerzeichen und Backslash.';
   }
   if (preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9.\-]{0,252}[A-Za-z0-9])?(?::[0-9]{1,5})?$/', $registrar) !== 1) {
      return 'Registrar: Hostname oder IPv4-Adresse, optional mit :Port (z. B. sipgate.de oder sip.example.org:5060).';
   }
   $port = (int)(explode(':', $registrar)[1] ?? 5060);
   if ($port < 1 || $port > 65535) {
      return 'Registrar: ungültiger Port.';
   }
   return null;
}

/* Erzeugt den Inhalt von pjsip_trunk.conf. Werte sind bereits geprüft. */
function sip_render_trunk(string $user, string $password, string $registrar): string
{
   $head = "; Automatisch erzeugt vom Telerix-Webinterface – nicht von Hand ändern.\n";
   if ($user === '') {
      return $head . "; Keine SIP-Registrierung eingerichtet.\n";
   }
   $host = explode(':', $registrar)[0];
   $pw = str_replace(';', '\;', $password);

   return $head . <<<CONF

[telerix-trunk-auth]
type=auth
auth_type=userpass
username={$user}
password={$pw}

[telerix-trunk]
type=aor
contact=sip:{$registrar}
qualify_frequency=60

[telerix-trunk]
type=endpoint
transport=transport-udp
context=sip-in
disallow=all
allow=g722,alaw,ulaw,gsm
outbound_auth=telerix-trunk-auth
aors=telerix-trunk
from_user={$user}
from_domain={$host}
direct_media=no
rtp_symmetric=yes
force_rport=yes
rewrite_contact=yes
dtmf_mode=rfc4733

[telerix-trunk]
type=identify
endpoint=telerix-trunk
match={$host}

; Eingehende Anrufe landen wie früher (register=.../200) auf Extension 200.
; line/endpoint ordnen Anrufe auch dann dem Anbieter zu, wenn sie von einer
; anderen IP-Adresse als dem Registrar kommen.
[telerix-trunk-reg]
type=registration
transport=transport-udp
outbound_auth=telerix-trunk-auth
server_uri=sip:{$registrar}
client_uri=sip:{$user}@{$registrar}
contact_user=200
line=yes
endpoint=telerix-trunk
retry_interval=60
forbidden_retry_interval=600
expiration=600

CONF;
}

/*
 * Speichert die Daten und schreibt die PJSIP-Konfiguration.
 * Ein leeres Passwort bei bestehender Registrierung behält das alte.
 */
function sip_save(string $user, string $password, string $registrar): void
{
   atomic_write(
      sip_file(),
      json_encode(['user' => $user, 'password' => $password, 'registrar' => $registrar], JSON_PRETTY_PRINT) . "\n",
      0600
   );
   /* 0640, Gruppe "asterisk" kommt vom setgid-Verzeichnis. */
   atomic_write(sip_trunk_conf_file(), sip_render_trunk($user, $password, $registrar), 0640);
}
