<?php

declare(strict_types=1);

namespace MTL\Services;

use MTL\Core\Config;
use MTL\Core\Database;
use MTL\Core\HttpException;
use MTL\Models\Album;
use MTL\Models\Media;
use MTL\Models\Step;
use MTL\Models\User;
use MTL\Support\Str;

defined('MTL_APP') || exit;

/**
 * Resumable chunked uploads.
 *
 * A 300 MB video cannot be sent as one request on shared hosting: it would run
 * into post_max_size, the FastCGI timeout, or a dropped mobile connection at
 * 90%. The browser therefore splits the file into fixed-size parts and sends
 * them one at a time; each part is written straight into the right offset of a
 * sparse file, and the parts already received are recorded so an interrupted
 * upload resumes instead of starting over.
 */
final class UploadService
{
    /** How long an unfinished upload is kept before the sweeper removes it. */
    private const TTL_SECONDS = 21600; // six hours

    /**
     * Registers an upload and reports how it should be sent.
     *
     * @param array{type?:string,id?:int} $target where the finished media
     *                                            should be attached
     *
     * @return array{uuid:string,chunk_bytes:int,chunks_total:int,received:list<int>,resumed:bool}
     */
    public static function begin(
        User $user,
        string $originalName,
        string $mimeType,
        int $sizeBytes,
        array $target = [],
    ): array {
        if ($sizeBytes <= 0) {
            throw new HttpException(422, 'The file is empty.');
        }

        // The declared type is only used to reject the obviously wrong before
        // any bytes are transferred; the real check happens on the assembled
        // file, from its own contents.
        if ($mimeType !== '' && !MediaService::isAccepted($mimeType)) {
            throw new HttpException(422, __('media.wrong_type') . ' (' . $mimeType . ')');
        }

        $limit = str_starts_with($mimeType, 'video/')
            ? (int) Config::get('media.videos.max_bytes', 512 * 1024 * 1024)
            : 128 * 1024 * 1024;

        if ($sizeBytes > $limit) {
            throw new HttpException(413, __('media.too_large', ['max' => Str::bytes($limit)]));
        }

        if (MediaService::quotaExceeded()) {
            throw new HttpException(507, 'The storage quota for this site has been reached.');
        }

        $chunkBytes = self::chunkSize();
        $chunksTotal = max(1, (int) ceil($sizeBytes / $chunkBytes));

        $db = Database::instance();

        // An interrupted upload of the same file by the same person resumes
        // rather than starting a second one.
        $existing = $db->table('upload_sessions')
            ->where('user_id', '=', $user->id())
            ->where('original_name', '=', $originalName)
            ->where('size_bytes', '=', $sizeBytes)
            ->where('status', '=', 'pending')
            ->whereRaw('expires_at > UTC_TIMESTAMP()')
            ->first();

        if ($existing !== null && is_file(storage_path('tmp/' . $existing['temp_path']))) {
            return [
                'uuid'         => (string) $existing['uuid'],
                'chunk_bytes'  => (int) $existing['chunk_bytes'],
                'chunks_total' => (int) $existing['chunks_total'],
                'received'     => self::receivedIndexes((string) $existing['chunks_received']),
                'resumed'      => true,
            ];
        }

        $uuid = \MTL\Models\Model::newUuid();
        $tempName = $uuid . '.part';

        $temporary = storage_path('tmp/' . $tempName);

        if (!is_dir(dirname($temporary)) && !mkdir(dirname($temporary), 0775, true) && !is_dir(dirname($temporary))) {
            throw new \RuntimeException('Cannot write to storage/tmp. Set the directory to 0775.');
        }

        // Create the file now so the first chunk can seek into it.
        if (file_put_contents($temporary, '') === false) {
            throw new \RuntimeException('Cannot create the temporary upload file.');
        }

        $targetType = in_array($target['type'] ?? 'none', ['none', 'step', 'album', 'trip', 'avatar'], true)
            ? (string) ($target['type'] ?? 'none')
            : 'none';

        $db->table('upload_sessions')->insert([
            'uuid'            => $uuid,
            'user_id'         => $user->id(),
            'original_name'   => mb_substr(MediaService::cleanFileName($originalName), 0, 255, 'UTF-8'),
            'mime_type'       => mb_substr($mimeType, 0, 100, 'UTF-8'),
            'size_bytes'      => $sizeBytes,
            'chunk_bytes'     => $chunkBytes,
            'chunks_total'    => $chunksTotal,
            'chunks_received' => str_repeat('0', $chunksTotal),
            'bytes_received'  => 0,
            'temp_path'       => $tempName,
            'target_type'     => $targetType,
            'target_id'       => isset($target['id']) ? (int) $target['id'] : null,
            'status'          => 'pending',
            'created_at'      => gmdate('Y-m-d H:i:s'),
            'updated_at'      => gmdate('Y-m-d H:i:s'),
            'expires_at'      => gmdate('Y-m-d H:i:s', time() + self::TTL_SECONDS),
        ]);

        return [
            'uuid'         => $uuid,
            'chunk_bytes'  => $chunkBytes,
            'chunks_total' => $chunksTotal,
            'received'     => [],
            'resumed'      => false,
        ];
    }

    /**
     * Stores one chunk.
     *
     * @return array{received:int,total:int,complete:bool,percent:int}
     */
    public static function receiveChunk(User $user, string $uuid, int $index, string $chunkFile): array
    {
        $session = self::load($user, $uuid);

        $total = (int) $session['chunks_total'];

        if ($index < 0 || $index >= $total) {
            throw new HttpException(422, 'Chunk index out of range.');
        }

        $chunkBytes = (int) $session['chunk_bytes'];
        $size = (int) filesize($chunkFile);

        $expected = $index === $total - 1
            ? ((int) $session['size_bytes']) - ($chunkBytes * ($total - 1))
            : $chunkBytes;

        if ($size !== $expected) {
            throw new HttpException(422, sprintf('Chunk %d has the wrong size (%d bytes, expected %d).', $index, $size, $expected));
        }

        $temporary = storage_path('tmp/' . basename((string) $session['temp_path']));

        $handle = @fopen($temporary, 'c+b');

        if ($handle === false) {
            throw new \RuntimeException('Cannot open the temporary upload file.');
        }

        try {
            // An exclusive lock: two chunks of the same upload can arrive
            // concurrently, and they write to different offsets of one file.
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Could not lock the temporary upload file.');
            }

            fseek($handle, $index * $chunkBytes);

            $bytes = file_get_contents($chunkFile);

            if ($bytes === false || fwrite($handle, $bytes) === false) {
                throw new \RuntimeException('Could not write the chunk.');
            }

            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
            @unlink($chunkFile);
        }

        // Mark the chunk received. Re-read inside the update so two concurrent
        // chunks cannot overwrite each other's bit.
        $db = Database::instance();

        $bitmap = $db->transaction(static function () use ($db, $session, $index): string {
            $current = (string) $db->table('upload_sessions')
                ->where('id', '=', (int) $session['id'])
                ->value('chunks_received');

            $current[$index] = '1';

            $db->table('upload_sessions')
                ->where('id', '=', (int) $session['id'])
                ->update([
                    'chunks_received' => $current,
                    'bytes_received'  => substr_count($current, '1') * (int) $session['chunk_bytes'],
                    'updated_at'      => gmdate('Y-m-d H:i:s'),
                ]);

            return $current;
        });

        $received = substr_count($bitmap, '1');

        return [
            'received' => $received,
            'total'    => $total,
            'complete' => $received === $total,
            'percent'  => (int) round(($received / max(1, $total)) * 100),
        ];
    }

    /**
     * Assembles the upload into a media record.
     */
    public static function complete(User $user, string $uuid): Media
    {
        $session = self::load($user, $uuid);

        $bitmap = (string) $session['chunks_received'];

        if (str_contains($bitmap, '0')) {
            throw new HttpException(409, 'The upload is not finished: ' . substr_count($bitmap, '0') . ' chunk(s) are missing.');
        }

        $db = Database::instance();
        $temporary = storage_path('tmp/' . basename((string) $session['temp_path']));

        if (!is_file($temporary)) {
            throw new HttpException(410, 'The uploaded data has expired. Please upload the file again.');
        }

        $actual = (int) filesize($temporary);
        $declared = (int) $session['size_bytes'];

        if ($actual !== $declared) {
            @unlink($temporary);
            $db->table('upload_sessions')->where('id', '=', (int) $session['id'])->update([
                'status' => 'failed',
                'error'  => sprintf('Assembled size %d does not match the declared %d.', $actual, $declared),
            ]);

            throw new HttpException(422, 'The uploaded file is incomplete. Please try again.');
        }

        $db->table('upload_sessions')->where('id', '=', (int) $session['id'])->update(['status' => 'assembling']);

        try {
            $media = MediaService::store($temporary, (string) $session['original_name'], $user);
        } catch (\Throwable $e) {
            @unlink($temporary);

            $db->table('upload_sessions')->where('id', '=', (int) $session['id'])->update([
                'status' => 'failed',
                'error'  => mb_substr($e->getMessage(), 0, 500, 'UTF-8'),
            ]);

            throw $e;
        }

        $db->table('upload_sessions')->where('id', '=', (int) $session['id'])->update([
            'status'     => 'complete',
            'media_id'   => $media->id(),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        self::attachToTarget($session, $media);

        return $media;
    }

    /**
     * Hooks the finished media onto whatever the uploader was working on.
     *
     * @param array<string,mixed> $session
     */
    private static function attachToTarget(array $session, Media $media): void
    {
        $type = (string) ($session['target_type'] ?? 'none');
        $id = (int) ($session['target_id'] ?? 0);

        if ($type === 'none' || $id <= 0) {
            return;
        }

        switch ($type) {
            case 'step':
                $step = Step::find($id);

                if ($step !== null && !$step->isDeleted()) {
                    MediaService::attachToStep($step, [$media->id()]);

                    // A photo with a location can place a step that has none,
                    // which is the whole point of reading the geotag.
                    if (!$step->hasCoordinates() && $media->hasLocation()) {
                        $step->update([
                            'latitude'  => $media->float('latitude'),
                            'longitude' => $media->float('longitude'),
                        ]);
                    }

                    if ($step->date('occurred_at') === null && $media->date('captured_at') !== null) {
                        $step->update(['occurred_at' => $media->string('captured_at')]);
                    }
                }
                break;

            case 'album':
                $album = Album::find($id);

                if ($album !== null && !$album->isDeleted()) {
                    MediaService::attachToAlbum($album, [$media->id()]);
                }
                break;

            case 'trip':
                $trip = \MTL\Models\Trip::find($id);

                if ($trip !== null && $trip->int('cover_media_id') === 0) {
                    $trip->update(['cover_media_id' => $media->id()]);
                }
                break;

            case 'avatar':
                $user = User::find($id);

                if ($user !== null) {
                    $user->update(['avatar_media_id' => $media->id()]);
                }
                break;
        }
    }

    /**
     * @return array{uuid:string,received:list<int>,chunks_total:int,status:string,percent:int}
     */
    public static function status(User $user, string $uuid): array
    {
        $session = self::load($user, $uuid);

        $bitmap = (string) $session['chunks_received'];
        $total = (int) $session['chunks_total'];
        $received = substr_count($bitmap, '1');

        return [
            'uuid'         => (string) $session['uuid'],
            'received'     => self::receivedIndexes($bitmap),
            'chunks_total' => $total,
            'status'       => (string) $session['status'],
            'percent'      => (int) round(($received / max(1, $total)) * 100),
        ];
    }

    public static function abort(User $user, string $uuid): void
    {
        $session = self::load($user, $uuid);

        $temporary = storage_path('tmp/' . basename((string) $session['temp_path']));

        if (is_file($temporary)) {
            @unlink($temporary);
        }

        Database::instance()->table('upload_sessions')->where('id', '=', (int) $session['id'])->delete();
    }

    /**
     * @return array<string,mixed>
     */
    private static function load(User $user, string $uuid): array
    {
        if (!\MTL\Models\Model::isUuid($uuid)) {
            throw new HttpException(422, 'Invalid upload identifier.');
        }

        $session = Database::instance()
            ->table('upload_sessions')
            ->where('uuid', '=', $uuid)
            ->first();

        if ($session === null) {
            throw new HttpException(404, 'That upload is not known. It may have expired.');
        }

        // An upload belongs to the person who started it, whatever their role.
        if ((int) $session['user_id'] !== $user->id()) {
            throw HttpException::forbidden();
        }

        return $session;
    }

    /**
     * @return list<int>
     */
    private static function receivedIndexes(string $bitmap): array
    {
        $indexes = [];
        $length = strlen($bitmap);

        for ($i = 0; $i < $length; ++$i) {
            if ($bitmap[$i] === '1') {
                $indexes[] = $i;
            }
        }

        return $indexes;
    }

    /**
     * The chunk size to advertise to the browser.
     *
     * Capped by what PHP will actually accept: a chunk larger than
     * post_max_size is rejected before any code runs, which would look like a
     * mysterious network failure.
     */
    public static function chunkSize(): int
    {
        $configured = (int) Config::get('media.chunk_bytes', 4 * 1024 * 1024);

        $postMax = self::iniBytes('post_max_size');
        $uploadMax = self::iniBytes('upload_max_filesize');

        $ceiling = min($postMax > 0 ? $postMax : PHP_INT_MAX, $uploadMax > 0 ? $uploadMax : PHP_INT_MAX);

        // Leave room for the multipart envelope and the other form fields.
        if ($ceiling !== PHP_INT_MAX) {
            $ceiling = (int) ($ceiling * 0.8);
        }

        return max(256 * 1024, min($configured, $ceiling));
    }

    private static function iniBytes(string $directive): int
    {
        $value = trim((string) ini_get($directive));

        if ($value === '' || $value === '-1') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g'     => $number * 1024 * 1024 * 1024,
            'm'     => $number * 1024 * 1024,
            'k'     => $number * 1024,
            default => $number,
        };
    }
}
