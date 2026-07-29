<?php

declare(strict_types=1);

namespace MTL\Http\Controllers;

use MTL\Core\HttpException;
use MTL\Core\Request;
use MTL\Core\Response;
use MTL\Models\Album;
use MTL\Models\Media;
use MTL\Models\Step;
use MTL\Models\Trip;
use MTL\Services\SettingsService;

defined('MTL_APP') || exit;

/**
 * Serves media files.
 *
 * Files are streamed through PHP rather than being served straight off disk,
 * because a private trip's photos must not be readable by anyone who guesses a
 * URL. That costs a little throughput and buys a real access check; on a
 * personal travel site the trade is easy.
 *
 * Everything a browser needs for a good experience is here: strong validators
 * so a repeat visit is a 304, byte ranges so a video can be scrubbed, and
 * immutable caching, since a media file never changes once written.
 */
final class MediaController extends Controller
{
    /** Bytes read per iteration while streaming. */
    private const CHUNK = 262144;

    public function show(Request $request): Response
    {
        $media = $this->resolve($request->param('uuid', ''));

        $this->assertReadable($media);

        $variant = $request->param('variant', 'medium') ?? 'medium';

        [$path, $mime] = $this->resolveVariant($media, $variant);

        return $this->stream($request, $path, $mime, $media, false);
    }

    /**
     * Serves the untouched original as a download.
     */
    public function download(Request $request): Response
    {
        $media = $this->resolve($request->param('uuid', ''));

        $this->assertReadable($media);

        // Originals carry full resolution and any metadata the camera wrote,
        // so handing them out is a separate decision from showing the photo.
        if (!SettingsService::bool('media.download_original') && !$this->auth()->can('media.update', $media)) {
            throw HttpException::forbidden();
        }

        return $this->stream(
            $request,
            $media->absolutePath(),
            $media->string('mime_type', 'application/octet-stream'),
            $media,
            true
        );
    }

    // -------------------------------------------------------------------------
    // Resolution and access
    // -------------------------------------------------------------------------

    private function resolve(string $identifier): Media
    {
        $media = Media::isUuid($identifier)
            ? Media::findByUuid($identifier)
            : (ctype_digit($identifier) ? Media::find((int) $identifier) : null);

        if ($media === null || $media->isDeleted()) {
            throw HttpException::notFound();
        }

        return $media;
    }

    /**
     * Decides whether the current visitor may see this file.
     *
     * 'public'  — anyone.
     * 'private' — the owner, or someone who may read private content.
     * 'inherit' — follows whatever the file is attached to. A photo that is
     *             not attached to anything yet is treated as private, so a
     *             just-uploaded image is not exposed by its URL before the
     *             report around it is published.
     */
    private function assertReadable(Media $media): void
    {
        $visibility = $media->string('visibility', 'inherit');

        if ($visibility === 'public') {
            return;
        }

        $user = $this->user();

        if ($user !== null && ($user->isEditor() || $media->int('user_id') === $user->id())) {
            return;
        }

        if ($visibility === 'private') {
            throw HttpException::notFound();
        }

        if ($this->isAttachedToSomethingVisible($media, $user)) {
            return;
        }

        // 404 rather than 403: a 403 confirms the file exists.
        throw HttpException::notFound();
    }

    private function isAttachedToSomethingVisible(Media $media, ?\MTL\Models\User $user): bool
    {
        $db = db();

        // Any published, publicly visible step this photo appears in.
        $stepIds = $db->table('step_media')->where('media_id', '=', $media->id())->pluck('step_id');

        foreach ($stepIds as $stepId) {
            $step = Step::find((int) $stepId);

            if ($step !== null && $step->isVisibleTo($user)) {
                return true;
            }
        }

        $albumIds = $db->table('album_media')->where('media_id', '=', $media->id())->pluck('album_id');

        foreach ($albumIds as $albumId) {
            $album = Album::find((int) $albumId);

            if ($album !== null && $album->isVisibleTo($user)) {
                return true;
            }
        }

        // Used as a cover image somewhere visible.
        $tripId = $db->table('trips')->where('cover_media_id', '=', $media->id())->value('id');

        if ($tripId !== null) {
            $trip = Trip::find((int) $tripId);

            if ($trip !== null && $trip->isVisibleTo($user)) {
                return true;
            }
        }

        // Avatars are shown next to public content, so they follow the account.
        return $db->table('users')->where('avatar_media_id', '=', $media->id())->exists();
    }

    /**
     * The file on disk for a requested rendition.
     *
     * @return array{0:string,1:string} absolute path and MIME type
     */
    private function resolveVariant(Media $media, string $variant): array
    {
        if ($variant === 'original' || !$media->isImage()) {
            return [$media->absolutePath(), $media->string('mime_type', 'application/octet-stream')];
        }

        $variants = $media->json('variants');

        if (isset($variants[$variant]['path'])) {
            $path = storage_path('media/' . $variants[$variant]['path']);

            if (is_file($path)) {
                return [$path, $this->mimeForExtension(pathinfo($path, PATHINFO_EXTENSION))];
            }
        }

        // Fall back to any smaller rendition, then to the original. A missing
        // variant is normal: an image smaller than the target size never had
        // one generated.
        foreach (array_reverse(Media::VARIANTS) as $candidate) {
            if (!isset($variants[$candidate]['path'])) {
                continue;
            }

            $path = storage_path('media/' . $variants[$candidate]['path']);

            if (is_file($path)) {
                return [$path, $this->mimeForExtension(pathinfo($path, PATHINFO_EXTENSION))];
            }
        }

        return [$media->absolutePath(), $media->string('mime_type', 'application/octet-stream')];
    }

    private function mimeForExtension(string $extension): string
    {
        return match (strtolower($extension)) {
            'webp'  => 'image/webp',
            'png'   => 'image/png',
            'avif'  => 'image/avif',
            'gif'   => 'image/gif',
            'mp4'   => 'video/mp4',
            'webm'  => 'video/webm',
            'mov'   => 'video/quicktime',
            default => 'image/jpeg',
        };
    }

    // -------------------------------------------------------------------------
    // Delivery
    // -------------------------------------------------------------------------

    private function stream(Request $request, string $path, string $mime, Media $media, bool $asDownload): Response
    {
        if (!is_file($path)) {
            logger()->warning('Media file missing on disk', ['media_id' => $media->id(), 'path' => $path]);

            throw HttpException::notFound();
        }

        $size = (int) filesize($path);
        $modified = (int) filemtime($path);

        // The checksum makes a strong validator that survives a file being
        // copied between servers, which mtime does not.
        $etag = '"' . substr($media->string('checksum') ?: (string) $modified, 0, 32) . '-' . $size . '"';

        $headers = [
            'Content-Type'   => $mime,
            'Last-Modified'  => gmdate('D, d M Y H:i:s', $modified) . ' GMT',
            'ETag'           => $etag,
            'Accept-Ranges'  => 'bytes',
            // A rendition never changes: a different image means a different
            // media record and a different URL.
            'Cache-Control'  => $media->string('visibility') === 'public'
                ? 'public, max-age=31536000, immutable'
                : 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($asDownload) {
            $headers['Content-Disposition'] = 'attachment; filename="'
                . str_replace('"', '', $media->string('original_name', 'download')) . '"';
        }

        // Conditional request: answer 304 and send nothing.
        if ($this->isNotModified($request, $etag, $modified)) {
            return (new Response('', 304))->withHeaders($headers);
        }

        [$start, $end, $isPartial] = $this->resolveRange($request, $size);

        if ($start === null) {
            return (new Response('', 416))->withHeaders($headers + ['Content-Range' => 'bytes */' . $size]);
        }

        $length = $end - $start + 1;

        $headers['Content-Length'] = (string) $length;

        if ($isPartial) {
            $headers['Content-Range'] = sprintf('bytes %d-%d/%d', $start, $end, $size);
        }

        $response = Response::stream(function () use ($path, $start, $length): void {
            $handle = fopen($path, 'rb');

            if ($handle === false) {
                return;
            }

            // Output buffering would defeat the point of streaming: the whole
            // file would be collected in memory before anything was sent.
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            fseek($handle, $start);

            $remaining = $length;

            while ($remaining > 0 && !feof($handle)) {
                $bytes = fread($handle, (int) min(self::CHUNK, $remaining));

                if ($bytes === false) {
                    break;
                }

                echo $bytes;
                flush();

                $remaining -= strlen($bytes);

                // The visitor navigated away or the player seeked elsewhere;
                // continuing would send a large file into a closed socket.
                if (connection_aborted() !== 0) {
                    break;
                }
            }

            fclose($handle);
        }, $isPartial ? 206 : 200);

        return $response->withHeaders($headers);
    }

    private function isNotModified(Request $request, string $etag, int $modified): bool
    {
        $ifNoneMatch = $request->header('if-none-match');

        if ($ifNoneMatch !== null) {
            // A weak comparison, and a client may send several tags.
            foreach (explode(',', $ifNoneMatch) as $candidate) {
                if (trim(str_replace('W/', '', $candidate)) === $etag) {
                    return true;
                }
            }

            return false;
        }

        $ifModifiedSince = $request->header('if-modified-since');

        if ($ifModifiedSince !== null) {
            $since = strtotime($ifModifiedSince);

            return $since !== false && $since >= $modified;
        }

        return false;
    }

    /**
     * Parses a Range header.
     *
     * Only a single range is honoured; multipart ranges are rare, and every
     * video player falls back gracefully when they are not offered.
     *
     * @return array{0:?int,1:int,2:bool} start, end, whether it is partial
     */
    private function resolveRange(Request $request, int $size): array
    {
        $range = $request->header('range');

        if ($range === null || $size === 0) {
            return [0, max(0, $size - 1), false];
        }

        if (preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m) !== 1) {
            return [0, $size - 1, false];
        }

        $first = $m[1];
        $last = $m[2];

        if ($first === '' && $last === '') {
            return [null, 0, false];
        }

        if ($first === '') {
            // A suffix range: the last N bytes.
            $length = (int) $last;

            if ($length <= 0) {
                return [null, 0, false];
            }

            $start = max(0, $size - $length);

            return [$start, $size - 1, true];
        }

        $start = (int) $first;
        $end = $last === '' ? $size - 1 : (int) $last;

        if ($start > $end || $start >= $size) {
            return [null, 0, false];
        }

        return [$start, min($end, $size - 1), true];
    }
}
