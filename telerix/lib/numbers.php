<?php
/*
 * Telefonnummernliste: ordnet Anrufernummern Namen zu.
 *
 * Ersetzt createlist.sh, showlist.sh und den Namensabgleich in
 * show_caller_numbers.sh. Hochgeladene Listen liegen außerhalb des
 * Webroots unter TELERIX_DATA/uploads/lists mit zufälligen Dateinamen.
 * Gespeichert wird nur die bereinigte Form "Nummer<TAB>Name" je Zeile.
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function numbers_dir(): string
{
   return data_path('uploads/lists');
}

/* Nummer auf Ziffern reduzieren; +49/0049 wird zu 0 (nationale Schreibweise). */
function number_normalize(string $n): string
{
   $n = preg_replace('/[^0-9+]/', '', $n);
   $n = preg_replace('/^(\+|00)49/', '0', $n);
   return preg_replace('/[^0-9]/', '', $n);
}

/*
 * Eine Zeile wie "Max Mustermann 06011 123" oder "Mustermann;+49 6011 123"
 * zerlegen. Liefert ['number' => ..., 'name' => ...] oder null.
 */
function number_parse_line(string $line): ?array
{
   if (!preg_match('/\+?[0-9][0-9 \/()-]{2,}[0-9]/', $line, $m)) {
      return null;
   }
   $number = number_normalize($m[0]);
   if (strlen($number) < 3 || strlen($number) > 20) {
      return null;
   }
   $name = str_replace($m[0], ' ', $line);
   $name = trim(preg_replace('/[\s,;:|"\']+/u', ' ', $name));
   $name = mb_substr($name, 0, 80);
   return ['number' => $number, 'name' => $name];
}

/* Alle Einträge aus allen hochgeladenen Listen, in Upload-Reihenfolge. */
function numbers_all(): array
{
   $files = glob(numbers_dir() . '/*.txt') ?: [];
   usort($files, fn($a, $b) => filemtime($a) <=> filemtime($b));
   $entries = [];
   foreach ($files as $f) {
      foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
         $parts = explode("\t", $line, 2);
         if (count($parts) === 2) {
            $entries[] = ['number' => $parts[0], 'name' => $parts[1]];
         }
      }
   }
   return $entries;
}

/* Anzeige für eine Anrufernummer: Name aus der Liste oder die Nummer selbst. */
function caller_display(string $callerId, array $entries): string
{
   if ($callerId === '' || strcasecmp($callerId, 'anonymous') === 0) {
      return 'Unterdrückte Rufnummer';
   }
   $n = number_normalize($callerId);
   foreach ($entries as $e) {
      if ($n !== '' && $e['number'] === $n && $e['name'] !== '') {
         return $e['name'];
      }
   }
   return $callerId;
}

function numbers_delete_all(): void
{
   foreach (glob(numbers_dir() . '/*.txt') ?: [] as $f) {
      unlink($f);
   }
}
