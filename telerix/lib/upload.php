<?php
/*
 * Sichere Datei-Uploads.
 *
 * Früher (upload.php, listupload.php) wurde unter dem Original-Dateinamen
 * im Webordner uploads/ gespeichert, der Typ nur teilweise geprüft – so
 * ließen sich PHP-Dateien hochladen und ausführen. Jetzt gilt:
 * - Whitelist für Dateiendung UND per finfo erkannten MIME-Typ,
 * - Größenlimit,
 * - zufälliger Dateiname, Ablage außerhalb des Webroots,
 * - Rechte 0600 (nur der PHP-Benutzer).
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/* Fehler mit einer Meldung, die dem Benutzer angezeigt werden darf. */
final class UploadError extends RuntimeException {}

/*
 * Prüft den Upload im Feld $field.
 * $allowed: Endung => Liste erlaubter MIME-Typen.
 * Liefert ['tmp' => Pfad, 'ext' => Endung] oder wirft UploadError.
 */
function upload_check(string $field, array $allowed, int $maxBytes): array
{
   $f = $_FILES[$field] ?? null;
   if (!is_array($f) || !is_string($f['tmp_name'] ?? null) || !is_int($f['error'] ?? null)) {
      throw new UploadError('Keine Datei ausgewählt.');
   }
   switch ($f['error']) {
      case UPLOAD_ERR_OK:
         break;
      case UPLOAD_ERR_NO_FILE:
         throw new UploadError('Keine Datei ausgewählt.');
      case UPLOAD_ERR_INI_SIZE:
      case UPLOAD_ERR_FORM_SIZE:
         throw new UploadError('Die Datei ist zu groß.');
      default:
         throw new UploadError('Der Upload ist fehlgeschlagen.');
   }
   if (!is_uploaded_file($f['tmp_name'])) {
      throw new UploadError('Der Upload ist fehlgeschlagen.');
   }
   if ($f['size'] <= 0 || $f['size'] > $maxBytes) {
      throw new UploadError('Die Datei ist leer oder größer als ' . intdiv($maxBytes, 1024 * 1024) . ' MB.');
   }

   $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
   if (!isset($allowed[$ext])) {
      throw new UploadError('Dateityp nicht erlaubt. Erlaubt: ' . implode(', ', array_keys($allowed)) . '.');
   }
   /* Den vom Browser gemeldeten Typ ignorieren, den Inhalt selbst prüfen. */
   $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
   if (!in_array($mime, $allowed[$ext], true)) {
      throw new UploadError("Der Inhalt passt nicht zur Endung .$ext (erkannt: $mime).");
   }
   return ['tmp' => $f['tmp_name'], 'ext' => $ext];
}

/* Hochgeladene Datei unter zufälligem Namen in $dir ablegen. */
function upload_store(string $tmp, string $dir, string $ext): string
{
   $target = $dir . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
   if (!move_uploaded_file($tmp, $target) || !chmod($target, 0600)) {
      throw new UploadError('Die Datei konnte nicht gespeichert werden.');
   }
   return $target;
}
