<?php
/*
 * Begrüßungsansage: eigene Audiodatei hochladen, die Anrufern statt der
 * mitgelieferten Ansage (tx/willkommen-gottesdienst) vorgespielt wird.
 *
 * Das Original bleibt unter zufälligem Namen in TELERIX_DATA/uploads/audio.
 * Für Asterisk wird es mit sox in 8 kHz/16 Bit/Mono-WAV umgewandelt
 * (TELERIX_DATA/sounds/ansage.wav). Der Dialplan prüft, ob die Datei
 * existiert.
 */
declare(strict_types=1);

require_once __DIR__ . '/upload.php';
require_once __DIR__ . '/asterisk.php';

const AUDIO_MAX_BYTES = 20 * 1024 * 1024;
/* Längere Ansagen werden abgeschnitten. */
const AUDIO_MAX_SECONDS = 600;

const AUDIO_ALLOWED = [
   'wav'  => ['audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave'],
   'mp3'  => ['audio/mpeg', 'audio/mp3', 'audio/x-mpeg'],
   'ogg'  => ['audio/ogg', 'application/ogg', 'audio/vorbis', 'audio/x-vorbis+ogg'],
   'flac' => ['audio/flac', 'audio/x-flac'],
];

function audio_upload_dir(): string
{
   return data_path('uploads/audio');
}

function announcement_file(): string
{
   return data_path('sounds/ansage.wav');
}

function announcement_exists(): bool
{
   return is_file(announcement_file());
}

/* Prüft, speichert und wandelt die hochgeladene Ansage um. */
function announcement_upload(string $field): void
{
   $u = upload_check($field, AUDIO_ALLOWED, AUDIO_MAX_BYTES);
   $src = upload_store($u['tmp'], audio_upload_dir(), $u['ext']);

   $tmp = data_path('sounds/.ansage-' . bin2hex(random_bytes(8)) . '.wav');
   [$code, , $err] = run_command([
      'sox', '--no-show-progress', '-t', $u['ext'], $src,
      '-r', '8000', '-c', '1', '-b', '16', '-e', 'signed-integer', '-t', 'wav', $tmp,
      'trim', '0', (string)AUDIO_MAX_SECONDS,
   ], 120);
   if ($code !== 0 || !is_file($tmp) || filesize($tmp) <= 44) {
      @unlink($tmp);
      @unlink($src);
      error_log('telerix: sox fehlgeschlagen: ' . trim($err));
      throw new UploadError('Die Audiodatei konnte nicht gelesen werden.');
   }
   if (!chmod($tmp, 0640) || !rename($tmp, announcement_file())) {
      @unlink($tmp);
      throw new UploadError('Die Ansage konnte nicht gespeichert werden.');
   }
   /* Nur die zuletzt hochgeladene Originaldatei aufbewahren. */
   foreach (glob(audio_upload_dir() . '/*') ?: [] as $f) {
      if ($f !== $src) {
         unlink($f);
      }
   }
}

/* Zurück zur mitgelieferten Ansage. */
function announcement_delete(): void
{
   @unlink(announcement_file());
   foreach (glob(audio_upload_dir() . '/*') ?: [] as $f) {
      unlink($f);
   }
}
