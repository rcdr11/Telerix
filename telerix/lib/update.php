<?php
/*
 * Prüfung auf neue Telerix-Versionen.
 *
 * Fragt das neueste Release im GitHub-Repository TELERIX_UPDATE_REPO
 * ("besitzer/repository") ab und vergleicht es mit TELERIX_VERSION.
 * Das Webinterface aktualisiert sich bewusst nicht selbst: Dafür bräuchte
 * der Container Zugriff auf Docker, und der entspricht Root-Rechten auf dem
 * Rechner. Das Update wird mit update.sh auf dem Rechner gestartet.
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/* Ergebnis eine Stunde zwischenspeichern (GitHub erlaubt ohne Anmeldung
   60 Anfragen pro Stunde). */
const UPDATE_CACHE_SECONDS = 3600;

function update_repo(): string
{
   $repo = trim((string)getenv('TELERIX_UPDATE_REPO'));
   return preg_match('#^[A-Za-z0-9_.-]{1,100}/[A-Za-z0-9_.-]{1,100}$#', $repo) ? $repo : '';
}

function update_cache_file(): string
{
   return data_path('state/update_check.json');
}

/* "v0.04-mod" -> "0.04-mod" */
function update_normalize_version(string $tag): string
{
   return ltrim(trim($tag), 'vV');
}

/*
 * Neuestes Release von GitHub holen. Liefert
 * ['version', 'name', 'notes', 'url', 'published'] oder wirft eine
 * RuntimeException mit einer anzeigbaren Meldung.
 */
function update_fetch_latest(string $repo): array
{
   $ctx = stream_context_create([
      'http' => [
         'timeout' => 5,
         'follow_location' => 0,
         'ignore_errors' => true,
         'header' => "User-Agent: Telerix-Updatecheck\r\nAccept: application/vnd.github+json\r\n",
      ],
      'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
   ]);
   $raw = @file_get_contents('https://api.github.com/repos/' . $repo . '/releases/latest', false, $ctx, 0, 512 * 1024);
   if ($raw === false) {
      throw new RuntimeException('GitHub ist nicht erreichbar.');
   }
   $status = 0;
   foreach ($http_response_header ?? [] as $h) {
      if (preg_match('#^HTTP/\S+ (\d{3})#', $h, $m)) {
         $status = (int)$m[1];
      }
   }
   if ($status === 404) {
      throw new RuntimeException("Im Repository $repo gibt es noch kein Release (oder es ist privat).");
   }
   if ($status === 403 || $status === 429) {
      throw new RuntimeException('GitHub begrenzt gerade die Anfragen. Bitte später erneut prüfen.');
   }
   $r = json_decode($raw, true);
   if ($status !== 200 || !is_array($r) || !is_string($r['tag_name'] ?? null)) {
      throw new RuntimeException('Unerwartete Antwort von GitHub (HTTP ' . $status . ').');
   }
   $url = is_string($r['html_url'] ?? null) && str_starts_with($r['html_url'], 'https://github.com/') ? $r['html_url'] : '';
   return [
      'version'   => update_normalize_version($r['tag_name']),
      'name'      => is_string($r['name'] ?? null) ? mb_substr($r['name'], 0, 200) : '',
      'notes'     => is_string($r['body'] ?? null) ? mb_substr($r['body'], 0, 20000) : '',
      'url'       => $url,
      'published' => is_string($r['published_at'] ?? null) ? $r['published_at'] : '',
   ];
}

/*
 * Status für die Anzeige: ['repo', 'checked' (Zeitstempel), 'latest'
 * (Release oder null), 'error' (Meldung oder null), 'update' (bool)].
 */
function update_status(bool $force = false): array
{
   $repo = update_repo();
   $status = ['repo' => $repo, 'checked' => 0, 'latest' => null, 'error' => null, 'update' => false];
   if ($repo === '') {
      return $status;
   }

   $cached = json_decode((string)@file_get_contents(update_cache_file()), true);
   if (!$force && is_array($cached) && ($cached['repo'] ?? '') === $repo
         && time() - (int)($cached['checked'] ?? 0) < UPDATE_CACHE_SECONDS) {
      $status = array_merge($status, $cached);
   } else {
      $status['checked'] = time();
      try {
         $status['latest'] = update_fetch_latest($repo);
      } catch (RuntimeException $e) {
         $status['error'] = $e->getMessage();
      }
      atomic_write(update_cache_file(), json_encode($status), 0600);
   }

   if ($status['latest'] !== null) {
      $status['update'] = version_compare($status['latest']['version'], TELERIX_VERSION, '>');
   }
   return $status;
}
