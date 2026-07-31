<?php

declare(strict_types=1);

namespace MTL\Models;

use MTL\Support\Str;

defined('MTL_APP') || exit;

/**
 * An uploaded file and everything known about it.
 */
final class Media extends Model
{
    protected static string $table = 'media';

    /** @var list<string> */
    protected static array $jsonColumns = ['variants'];

    /** @var list<string> */
    protected static array $dateColumns = ['created_at', 'updated_at', 'deleted_at', 'captured_at'];

    public const KIND_IMAGE = 'image';
    public const KIND_VIDEO = 'video';
    public const KIND_AUDIO = 'audio';
    public const KIND_DOCUMENT = 'document';

    /**
     * Variant names, largest last. The order matters: srcset() walks it, and
     * variantPath() falls back down the list when a size is missing.
     *
     * @var list<string>
     */
    public const VARIANTS = ['thumb', 'small', 'medium', 'large'];

    public function isImage(): bool
    {
        return $this->string('kind') === self::KIND_IMAGE;
    }

    public function isVideo(): bool
    {
        return $this->string('kind') === self::KIND_VIDEO;
    }

    public function isReady(): bool
    {
        return $this->string('status') === 'ready';
    }

    /** Absolute path of the original file on disk. */
    public function absolutePath(): string
    {
        return storage_path('media/' . $this->string('path'));
    }

    public function fileExists(): bool
    {
        return is_file($this->absolutePath());
    }

    /**
     * URL for one rendition.
     *
     * Requests go through the media controller rather than straight to the
     * filesystem, because a private photo must not be readable by URL alone.
     */
    public function url(string $variant = 'medium'): string
    {
        if (!$this->isImage() || $variant === 'original') {
            return path('/media/original/' . $this->string('uuid'));
        }

        return path('/media/' . $this->resolveVariant($variant) . '/' . $this->string('uuid'));
    }

    /**
     * Falls back to a smaller rendition when the requested one was never
     * generated — which happens when the original was smaller than the target.
     */
    private function resolveVariant(string $wanted): string
    {
        $variants = $this->json('variants');

        if (isset($variants[$wanted])) {
            return $wanted;
        }

        $index = array_search($wanted, self::VARIANTS, true);

        if ($index === false) {
            return 'medium';
        }

        // Walk down to smaller sizes first: serving something too small is
        // better than serving a multi-megabyte original into a thumbnail slot.
        for ($i = $index - 1; $i >= 0; --$i) {
            if (isset($variants[self::VARIANTS[$i]])) {
                return self::VARIANTS[$i];
            }
        }

        for ($i = $index + 1; $i < count(self::VARIANTS); ++$i) {
            if (isset($variants[self::VARIANTS[$i]])) {
                return self::VARIANTS[$i];
            }
        }

        return 'original';
    }

    /**
     * A srcset covering every generated size, so the browser can pick.
     */
    public function srcset(): string
    {
        $variants = $this->json('variants');
        $parts = [];

        foreach (self::VARIANTS as $name) {
            if (!isset($variants[$name]['w'])) {
                continue;
            }

            $parts[] = path('/media/' . $name . '/' . $this->string('uuid')) . ' ' . (int) $variants[$name]['w'] . 'w';
        }

        return implode(', ', $parts);
    }

    /**
     * Intrinsic size of a rendition, for the width/height attributes that stop
     * the page from reflowing as images load.
     *
     * @return array{0:int,1:int}
     */
    public function dimensions(string $variant = 'medium'): array
    {
        $variants = $this->json('variants');
        $resolved = $this->resolveVariant($variant);

        if (isset($variants[$resolved]['w'], $variants[$resolved]['h'])) {
            return [(int) $variants[$resolved]['w'], (int) $variants[$resolved]['h']];
        }

        return [$this->int('width'), $this->int('height')];
    }

    public function aspectRatio(): float
    {
        $width = $this->int('width');
        $height = $this->int('height');

        return $height > 0 ? $width / $height : 1.5;
    }

    /**
     * Alt text, falling back to the title and then to a generic description.
     *
     * Never returns the file name: "IMG_4821.JPG" read aloud is worse than
     * nothing.
     */
    public function alt(): string
    {
        $alt = $this->string('alt_text');

        if ($alt !== '') {
            return $alt;
        }

        $title = $this->string('title');

        if ($title !== '') {
            return $title;
        }

        return '';
    }

    public function displayTitle(): string
    {
        $title = $this->string('title');

        if ($title !== '') {
            return $title;
        }

        $original = $this->string('original_name');

        return $original === '' ? '#' . $this->id() : $original;
    }

    public function hasLocation(): bool
    {
        return $this->attribute('latitude') !== null && $this->attribute('longitude') !== null;
    }

    public function sizeLabel(): string
    {
        return Str::bytes($this->int('size_bytes'));
    }

    public function durationLabel(): string
    {
        $ms = $this->int('duration_ms');

        return $ms > 0 ? Str::duration((int) round($ms / 1000)) : '';
    }

    public function cameraLabel(): string
    {
        $make = $this->string('camera_make');
        $model = $this->string('camera_model');

        if ($model === '') {
            return $make;
        }

        // Manufacturers repeat the brand in the model ("NIKON" / "NIKON D750");
        // showing it twice reads as a bug.
        if ($make !== '' && !str_starts_with(strtolower($model), strtolower($make))) {
            return $make . ' ' . $model;
        }

        return $model;
    }

    /**
     * The background colour shown while the image is still downloading.
     */
    public function placeholderStyle(): string
    {
        $colour = $this->string('dominant_color');

        return $colour === '' ? '' : 'background-color: ' . $colour;
    }

    /**
     * Every file belonging to this record: the original and each variant,
     * as paths relative to storage/media.
     *
     * @return list<string>
     */
    public function allPaths(): array
    {
        $paths = [$this->string('path')];

        foreach ($this->json('variants') as $variant) {
            if (is_array($variant) && isset($variant['path'])) {
                $paths[] = (string) $variant['path'];
            }
        }

        return array_values(array_unique(array_filter($paths)));
    }

    public static function findByChecksum(string $checksum): ?self
    {
        if ($checksum === '') {
            return null;
        }

        $row = self::query()
            ->where('checksum', '=', $checksum)
            ->whereNull('deleted_at')
            ->first();

        return $row === null ? null : new self($row);
    }

    /**
     * Classifies a MIME type into the kind column.
     */
    public static function kindFor(string $mimeType): string
    {
        return match (true) {
            str_starts_with($mimeType, 'image/') => self::KIND_IMAGE,
            str_starts_with($mimeType, 'video/') => self::KIND_VIDEO,
            str_starts_with($mimeType, 'audio/') => self::KIND_AUDIO,
            default                              => self::KIND_DOCUMENT,
        };
    }
}
