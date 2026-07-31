<?php

declare(strict_types=1);

namespace MTL\Services;

use MTL\Core\Config;
use MTL\Core\Database;
use MTL\Media\ExifReader;
use MTL\Media\ImageProcessor;
use MTL\Models\Album;
use MTL\Models\Media;
use MTL\Models\Model;
use MTL\Models\Step;
use MTL\Models\User;
use MTL\Support\Str;

defined('MTL_APP') || exit;

/**
 * Everything that happens to a file between "the browser sent it" and "it is
 * in the library".
 */
final class MediaService
{
    /**
     * File extension per accepted MIME type.
     *
     * The extension is derived from the detected type rather than from the
     * uploaded file name, so "holiday.jpg.php" cannot become a .php file on
     * disk. Anything not listed here is refused outright.
     *
     * @var array<string,string>
     */
    private const EXTENSIONS = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'image/avif'      => 'avif',
        'image/gif'       => 'gif',
        'video/mp4'       => 'mp4',
        'video/webm'      => 'webm',
        'video/quicktime' => 'mov',
        'audio/mpeg'      => 'mp3',
        'audio/mp4'       => 'm4a',
        'audio/ogg'       => 'ogg',
        'application/pdf' => 'pdf',
    ];

    /**
     * Takes a file that is already on disk and turns it into a library entry.
     *
     * @param array{
     *   title?:string, alt_text?:string, caption?:string, visibility?:string,
     *   captured_at?:string, latitude?:float, longitude?:float
     * } $attributes
     */
    public static function store(string $sourceFile, string $originalName, ?User $owner, array $attributes = []): Media
    {
        if (!is_file($sourceFile)) {
            throw new \RuntimeException('The uploaded file is no longer there.');
        }

        $size = (int) filesize($sourceFile);

        if ($size <= 0) {
            throw new \RuntimeException('The uploaded file is empty.');
        }

        $mime = self::detectMimeType($sourceFile);
        self::assertAccepted($mime, $size);

        $extension = self::EXTENSIONS[$mime];
        $kind = Media::kindFor($mime);

        // Identical files are stored once. Re-uploading the same photo into a
        // second album should not double the storage bill.
        $checksum = (string) hash_file('sha256', $sourceFile);
        $existing = Media::findByChecksum($checksum);

        if ($existing !== null) {
            @unlink($sourceFile);

            return $existing;
        }

        // Sharded by upload date so no directory grows past a few hundred
        // files, which keeps FTP listings usable.
        $relativeDirectory = gmdate('Y/m/d');
        $absoluteDirectory = storage_path('media/' . $relativeDirectory);

        if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0775, true) && !is_dir($absoluteDirectory)) {
            throw new \RuntimeException('Cannot write to storage/media. Set the directory to 0775.');
        }

        // A random stem rather than the original name: the delivery route is
        // authorised, but an unguessable path is a useful second layer.
        $stem = bin2hex(random_bytes(8));
        $relativePath = $relativeDirectory . '/' . $stem . '.' . $extension;
        $absolutePath = storage_path('media/' . $relativePath);

        if (!self::moveInto($sourceFile, $absolutePath)) {
            throw new \RuntimeException('Could not save the uploaded file.');
        }

        @chmod($absolutePath, 0644);

        $record = [
            'user_id'       => $owner?->id(),
            'path'          => $relativePath,
            'original_name' => mb_substr(self::cleanFileName($originalName), 0, 255, 'UTF-8'),
            'extension'     => $extension,
            'mime_type'     => $mime,
            'kind'          => $kind,
            'size_bytes'    => $size,
            'checksum'      => $checksum,
            'storage_bytes' => $size,
            'status'        => 'ready',
            'visibility'    => $attributes['visibility'] ?? SettingsService::string('media.default_visibility', 'inherit'),
            'title'         => mb_substr((string) ($attributes['title'] ?? ''), 0, 191, 'UTF-8'),
            'alt_text'      => mb_substr((string) ($attributes['alt_text'] ?? ''), 0, 500, 'UTF-8'),
        ];

        if ($kind === Media::KIND_IMAGE) {
            $record = array_merge($record, self::analyseImage($absolutePath, $stem, $absoluteDirectory));
        }

        // Explicit values from the caller win over what EXIF said.
        foreach (['captured_at', 'latitude', 'longitude'] as $field) {
            if (isset($attributes[$field]) && $attributes[$field] !== '' && $attributes[$field] !== null) {
                $record[$field] = $attributes[$field];
            }
        }

        if (($attributes['caption'] ?? '') !== '') {
            $record['caption_md'] = $attributes['caption'];
            $record['caption_html'] = \MTL\Markdown\Markdown::renderSnippet((string) $attributes['caption']);
        }

        try {
            $media = Media::create($record);
        } catch (\Throwable $e) {
            // Do not leave the file behind if the row could not be written.
            @unlink($absolutePath);

            foreach (glob($absoluteDirectory . '/' . $stem . '-*') ?: [] as $variant) {
                @unlink($variant);
            }

            throw $e;
        }

        AuditService::log('media.uploaded', $media, [
            'bytes' => $size,
            'kind'  => $kind,
            'mime'  => $mime,
        ], $owner);

        return $media;
    }

    /**
     * Reads EXIF, generates variants and derives the display colours.
     *
     * A failure here degrades the record rather than rejecting the upload: a
     * photo the server cannot resize is still worth keeping, and the media
     * library shows the reason.
     *
     * @return array<string,mixed>
     */
    private static function analyseImage(string $file, string $stem, string $directory): array
    {
        $out = [];

        $exif = ExifReader::read($file);

        $out['orientation']  = $exif['orientation'];
        $out['captured_at']  = $exif['captured_at'];
        $out['camera_make']  = $exif['camera_make'];
        $out['camera_model'] = $exif['camera_model'];
        $out['lens']         = $exif['lens'];
        $out['exposure']     = $exif['exposure'];
        $out['iso']          = $exif['iso'];
        $out['focal_length'] = $exif['focal_length'];

        if (!SettingsService::bool('media.strip_gps')) {
            $out['latitude']   = $exif['latitude'];
            $out['longitude']  = $exif['longitude'];
            $out['altitude_m'] = $exif['altitude'];
        } else {
            ExifReader::stripLocation($file);
        }

        $probe = ImageProcessor::probe($file);

        if ($probe !== null) {
            // The stored width and height describe the image as displayed, so
            // a portrait photo tagged with a rotation reports its short edge
            // as the width.
            $swap = in_array($exif['orientation'], [5, 6, 7, 8], true);

            $out['width']  = $swap ? $probe['height'] : $probe['width'];
            $out['height'] = $swap ? $probe['width'] : $probe['height'];
        }

        if (!ImageProcessor::isAvailable()) {
            $out['status'] = 'ready';
            $out['processing_error'] = 'The gd extension is not available, so no smaller sizes were generated.';

            return $out;
        }

        try {
            $processor = ImageProcessor::fromConfig();

            /** @var array<string,int> $sizes */
            $sizes = Config::get('media.images.variants', [
                'thumb' => 320, 'small' => 640, 'medium' => 1280, 'large' => 2048,
            ]);

            $variants = $processor->generateVariants($file, $directory, $stem, $sizes, $exif['orientation']);

            $out['variants'] = $variants;
            $out['dominant_color'] = $processor->dominantColour($file);
            $out['placeholder'] = $processor->placeholder($file);

            $out['storage_bytes'] = (int) filesize($file) + array_sum(array_column($variants, 'bytes'));
            $out['status'] = 'ready';
        } catch (\Throwable $e) {
            logger()->warning('Image processing failed', ['file' => basename($file), 'error' => $e->getMessage()]);

            $out['status'] = 'failed';
            $out['processing_error'] = mb_substr($e->getMessage(), 0, 500, 'UTF-8');
        }

        return $out;
    }

    /**
     * Regenerates the derived sizes for an existing record.
     */
    public static function rebuildVariants(Media $media): bool
    {
        if (!$media->isImage() || !$media->fileExists() || !ImageProcessor::isAvailable()) {
            return false;
        }

        $absolute = $media->absolutePath();
        $directory = dirname($absolute);
        $stem = pathinfo($absolute, PATHINFO_FILENAME);

        // Remove the old ones first, otherwise a change of output format
        // leaves the previous files orphaned.
        foreach ($media->json('variants') as $variant) {
            if (is_array($variant) && isset($variant['path'])) {
                @unlink(storage_path('media/' . $variant['path']));
            }
        }

        try {
            $processor = ImageProcessor::fromConfig();

            /** @var array<string,int> $sizes */
            $sizes = Config::get('media.images.variants', []);

            $variants = $processor->generateVariants($absolute, $directory, $stem, $sizes, $media->int('orientation', 1));

            $media->update([
                'variants'         => $variants,
                'dominant_color'   => $processor->dominantColour($absolute),
                'placeholder'      => $processor->placeholder($absolute),
                'storage_bytes'    => (int) filesize($absolute) + array_sum(array_column($variants, 'bytes')),
                'status'           => 'ready',
                'processing_error' => '',
            ]);

            return true;
        } catch (\Throwable $e) {
            $media->update([
                'status'           => 'failed',
                'processing_error' => mb_substr($e->getMessage(), 0, 500, 'UTF-8'),
            ]);

            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Deleting
    // -------------------------------------------------------------------------

    /**
     * Moves media to the bin. The files stay on disk until it is emptied, so a
     * mistake is recoverable.
     */
    public static function softDelete(Media $media): void
    {
        $media->softDelete();

        AuditService::log('media.deleted', $media);

        self::refreshCountersFor($media);
    }

    public static function restore(Media $media): void
    {
        $media->restore();

        AuditService::log('media.restored', $media);

        self::refreshCountersFor($media);
    }

    /**
     * Deletes the record and every file behind it, for good.
     */
    public static function purge(Media $media): void
    {
        $paths = $media->allPaths();

        // A record used as a cover elsewhere must not leave a dangling
        // reference; the foreign keys are ON DELETE SET NULL, which handles
        // trips, steps and albums, and the pivots cascade.
        $id = $media->id();
        $label = $media->displayTitle();

        $media->forceDelete();

        foreach ($paths as $path) {
            $absolute = storage_path('media/' . $path);

            // Confine the deletion to the media directory: a corrupted path
            // column must never be able to reach outside it.
            if (self::isInsideMediaRoot($absolute) && is_file($absolute)) {
                @unlink($absolute);
            }
        }

        AuditService::log('media.purged', null, ['media_id' => $id, 'label' => $label]);
    }

    /**
     * Empties the bin of everything deleted more than $days ago.
     *
     * @return int number of records removed
     */
    public static function purgeTrash(int $days = 30, int $limit = 200): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * 86400));

        $rows = Media::query()
            ->whereNotNull('deleted_at')
            ->where('deleted_at', '<', $cutoff)
            ->limit($limit)
            ->get();

        $removed = 0;

        foreach ($rows as $row) {
            self::purge(Media::fromRow($row));
            ++$removed;
        }

        return $removed;
    }

    // -------------------------------------------------------------------------
    // Attaching to content
    // -------------------------------------------------------------------------

    /**
     * Attaches media to a step, appending to the end of the gallery.
     *
     * @param list<int> $mediaIds
     */
    public static function attachToStep(Step $step, array $mediaIds): int
    {
        return self::attach('step_media', 'step_id', $step, $mediaIds);
    }

    /**
     * @param list<int> $mediaIds
     */
    public static function attachToAlbum(Album $album, array $mediaIds): int
    {
        return self::attach('album_media', 'album_id', $album, $mediaIds);
    }

    /**
     * @param list<int> $mediaIds
     */
    private static function attach(string $table, string $foreignKey, Model $owner, array $mediaIds): int
    {
        $mediaIds = array_values(array_unique(array_filter(array_map('intval', $mediaIds))));

        if ($mediaIds === []) {
            return 0;
        }

        $db = Database::instance();

        return $db->transaction(static function () use ($db, $table, $foreignKey, $owner, $mediaIds): int {
            // Only attach media that actually exists and is not in the bin.
            $valid = $db->table('media')
                ->whereIn('id', $mediaIds)
                ->whereNull('deleted_at')
                ->pluck('id');

            $valid = array_map('intval', $valid);

            if ($valid === []) {
                return 0;
            }

            $existing = $db->table($table)
                ->where($foreignKey, '=', $owner->id())
                ->pluck('media_id');

            $existing = array_map('intval', $existing);

            $position = (int) ($db->table($table)->where($foreignKey, '=', $owner->id())->max('position') ?? -1);

            $added = 0;

            // Preserve the order the caller supplied rather than the order the
            // database happened to return.
            foreach ($mediaIds as $mediaId) {
                if (!in_array($mediaId, $valid, true) || in_array($mediaId, $existing, true)) {
                    continue;
                }

                $db->table($table)->insert([
                    $foreignKey  => $owner->id(),
                    'media_id'   => $mediaId,
                    'position'   => ++$position,
                    'created_at' => gmdate('Y-m-d H:i:s'),
                ]);

                ++$added;
            }

            if ($added > 0) {
                self::refreshCounters($owner);
            }

            return $added;
        });
    }

    public static function detachFromStep(Step $step, int $mediaId): bool
    {
        return self::detach('step_media', 'step_id', $step, $mediaId);
    }

    public static function detachFromAlbum(Album $album, int $mediaId): bool
    {
        return self::detach('album_media', 'album_id', $album, $mediaId);
    }

    private static function detach(string $table, string $foreignKey, Model $owner, int $mediaId): bool
    {
        $removed = Database::instance()
            ->table($table)
            ->where($foreignKey, '=', $owner->id())
            ->where('media_id', '=', $mediaId)
            ->delete();

        if ($removed > 0) {
            self::refreshCounters($owner);
        }

        return $removed > 0;
    }

    /**
     * Applies a new gallery order.
     *
     * @param list<int> $mediaIds in the order they should appear
     */
    public static function reorder(string $table, string $foreignKey, Model $owner, array $mediaIds): void
    {
        $db = Database::instance();

        $db->transaction(static function () use ($db, $table, $foreignKey, $owner, $mediaIds): void {
            $position = 0;

            foreach ($mediaIds as $mediaId) {
                $db->table($table)
                    ->where($foreignKey, '=', $owner->id())
                    ->where('media_id', '=', (int) $mediaId)
                    ->update(['position' => $position++]);
            }
        });
    }

    // -------------------------------------------------------------------------
    // Counters
    // -------------------------------------------------------------------------

    private static function refreshCounters(Model $owner): void
    {
        if ($owner instanceof Step) {
            $count = Database::instance()
                ->table('step_media')
                ->join('media', 'media.id', '=', 'step_media.media_id')
                ->where('step_media.step_id', '=', $owner->id())
                ->whereNull('media.deleted_at')
                ->count();

            $owner->update(['media_count' => $count]);

            $trip = $owner->trip();

            if ($trip !== null) {
                TripService::refreshAggregates($trip);
            }

            return;
        }

        if ($owner instanceof Album) {
            $count = Database::instance()
                ->table('album_media')
                ->join('media', 'media.id', '=', 'album_media.media_id')
                ->where('album_media.album_id', '=', $owner->id())
                ->whereNull('media.deleted_at')
                ->count();

            $owner->update(['media_count' => $count]);
        }
    }

    /** Refreshes the counters of everything a media record is attached to. */
    private static function refreshCountersFor(Media $media): void
    {
        $db = Database::instance();

        foreach ($db->table('step_media')->where('media_id', '=', $media->id())->pluck('step_id') as $stepId) {
            $step = Step::find((int) $stepId);

            if ($step !== null) {
                self::refreshCounters($step);
            }
        }

        foreach ($db->table('album_media')->where('media_id', '=', $media->id())->pluck('album_id') as $albumId) {
            $album = Album::find((int) $albumId);

            if ($album !== null) {
                self::refreshCounters($album);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Validation helpers
    // -------------------------------------------------------------------------

    /**
     * Determines the type from the file's own bytes.
     *
     * The browser-supplied Content-Type is not consulted: it is whatever the
     * client chose to send.
     */
    public static function detectMimeType(string $file): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);

            if ($finfo !== false) {
                $mime = finfo_file($finfo, $file);
                // No finfo_close(): the handle frees itself when it goes out of
                // scope, and PHP 8.5 deprecates closing it by hand — which took
                // every upload down on the first host that ran 8.5.

                if (is_string($mime) && $mime !== '') {
                    return strtolower(explode(';', $mime)[0]);
                }
            }
        }

        // Without fileinfo, getimagesize still identifies images correctly.
        $probe = @getimagesize($file);

        if (is_array($probe) && isset($probe['mime'])) {
            return strtolower((string) $probe['mime']);
        }

        return 'application/octet-stream';
    }

    public static function isAccepted(string $mime): bool
    {
        /** @var list<string> $images */
        $images = Config::get('media.images.accept', []);
        /** @var list<string> $videos */
        $videos = Config::get('media.videos.accept', []);

        return isset(self::EXTENSIONS[$mime]) && in_array($mime, array_merge($images, $videos), true);
    }

    private static function assertAccepted(string $mime, int $size): void
    {
        if (!self::isAccepted($mime)) {
            throw new \RuntimeException(__('media.wrong_type') . ' (' . $mime . ')');
        }

        if (str_starts_with($mime, 'video/')) {
            $limit = (int) Config::get('media.videos.max_bytes', 512 * 1024 * 1024);

            if ($size > $limit) {
                throw new \RuntimeException(__('media.too_large', ['max' => Str::bytes($limit)]));
            }
        }
    }

    /**
     * Moves an uploaded or assembled file into place.
     *
     * rename() fails across filesystems, which is exactly the case when the
     * PHP upload directory is on a different mount from the web space —
     * common on shared hosting — so it falls back to a copy.
     */
    private static function moveInto(string $source, string $target): bool
    {
        if (is_uploaded_file($source)) {
            return move_uploaded_file($source, $target);
        }

        if (@rename($source, $target)) {
            return true;
        }

        if (@copy($source, $target)) {
            @unlink($source);

            return true;
        }

        return false;
    }

    private static function isInsideMediaRoot(string $path): bool
    {
        $root = realpath(storage_path('media'));
        $real = realpath($path);

        return $root !== false && $real !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR);
    }

    /**
     * Keeps an uploaded file name readable while removing anything that could
     * be interpreted as a path or as markup when it is displayed.
     */
    public static function cleanFileName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = Str::toUtf8($name);
        $name = preg_replace('/[\x00-\x1F\x7F<>:"|?*]/u', '', $name) ?? $name;

        return trim($name) === '' ? 'bestand' : trim($name);
    }

    /**
     * Total bytes held by media that has not been deleted.
     */
    public static function totalStorageBytes(): int
    {
        return (int) Media::query()->whereNull('deleted_at')->sum('storage_bytes');
    }

    /**
     * True when the configured storage quota has been reached.
     */
    public static function quotaExceeded(): bool
    {
        $quotaMb = SettingsService::int('media.quota_mb');

        return $quotaMb > 0 && self::totalStorageBytes() >= $quotaMb * 1024 * 1024;
    }
}
