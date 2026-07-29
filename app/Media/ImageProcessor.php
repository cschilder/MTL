<?php

declare(strict_types=1);

namespace MTL\Media;

use MTL\Core\Config;

defined('MTL_APP') || exit;

/**
 * Image resizing, orientation and colour analysis, built on GD.
 *
 * GD rather than Imagick because it is the one image extension a Strato
 * Hosting Advanced account is guaranteed to have. It cannot read every format
 * Imagick can, but it handles JPEG, PNG, WebP, GIF and (on PHP 8.1+) AVIF,
 * which covers what a phone or a camera produces.
 *
 * Memory is the real constraint on shared hosting: a 48-megapixel photo needs
 * roughly 200 MB decoded. Every entry point therefore checks the pixel count
 * from the header before anything is decoded.
 */
final class ImageProcessor
{
    /** @var array<string,true> mime types GD can decode in this build */
    private static ?array $readable = null;

    public function __construct(
        private readonly int $quality = 82,
        private readonly bool $preferWebp = true,
        private readonly int $maxPixels = 80_000_000,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            quality: (int) Config::get('media.images.quality', 82),
            preferWebp: (bool) Config::get('media.images.prefer_webp', true),
            maxPixels: (int) Config::get('media.images.max_pixels', 80_000_000),
        );
    }

    public static function isAvailable(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatetruecolor');
    }

    /**
     * MIME types this PHP build can actually decode.
     *
     * @return array<string,true>
     */
    public static function readableTypes(): array
    {
        if (self::$readable !== null) {
            return self::$readable;
        }

        $types = [];

        if (function_exists('imagecreatefromjpeg')) {
            $types['image/jpeg'] = true;
        }
        if (function_exists('imagecreatefrompng')) {
            $types['image/png'] = true;
        }
        if (function_exists('imagecreatefromgif')) {
            $types['image/gif'] = true;
        }
        if (function_exists('imagecreatefromwebp')) {
            $types['image/webp'] = true;
        }
        if (function_exists('imagecreatefromavif')) {
            $types['image/avif'] = true;
        }

        return self::$readable = $types;
    }

    public function canWriteWebp(): bool
    {
        return $this->preferWebp && function_exists('imagewebp');
    }

    /**
     * Reads the header of an image without decoding it.
     *
     * @return array{width:int,height:int,mime:string}|null
     */
    public static function probe(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }

        $info = @getimagesize($file);

        if ($info === false || ($info[0] ?? 0) < 1 || ($info[1] ?? 0) < 1) {
            return null;
        }

        return [
            'width'  => (int) $info[0],
            'height' => (int) $info[1],
            'mime'   => (string) ($info['mime'] ?? ''),
        ];
    }

    /**
     * Generates the derived sizes for one image.
     *
     * Only sizes smaller than the source are produced: upscaling a 400 px
     * photo to 2048 px wastes storage and looks worse than letting the browser
     * scale it.
     *
     * @param array<string,int> $sizes variant name => longest edge in pixels
     *
     * @return array<string,array{path:string,w:int,h:int,bytes:int}>
     */
    public function generateVariants(string $sourceFile, string $targetDirectory, string $baseName, array $sizes, int $orientation = 1): array
    {
        $probe = self::probe($sourceFile);

        if ($probe === null) {
            throw new \RuntimeException('Not a readable image: ' . basename($sourceFile));
        }

        $this->guardPixelCount($probe['width'], $probe['height']);

        $source = $this->load($sourceFile, $probe['mime']);

        if ($source === null) {
            throw new \RuntimeException('This image format cannot be read by the server: ' . $probe['mime']);
        }

        try {
            $source = $this->applyOrientation($source, $orientation);

            $sourceWidth = imagesx($source);
            $sourceHeight = imagesy($source);
            $longestEdge = max($sourceWidth, $sourceHeight);

            if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
                throw new \RuntimeException('Cannot create the media directory. Check that storage/ is writable.');
            }

            $useWebp = $this->canWriteWebp();
            $extension = $useWebp ? 'webp' : ($probe['mime'] === 'image/png' ? 'png' : 'jpg');

            $variants = [];

            // Largest first, so each smaller size can be built from the
            // previous one instead of from the full-resolution original.
            arsort($sizes);
            $previous = $source;
            $previousIsSource = true;

            foreach ($sizes as $name => $edge) {
                if ($edge >= $longestEdge && $name !== 'thumb') {
                    // The source is already at or below this size. Only skip
                    // it for the larger variants; a thumbnail is always wanted,
                    // because the library grid loads dozens at a time.
                    continue;
                }

                $scale = min(1.0, $edge / max(1, max(imagesx($previous), imagesy($previous))));

                $width = max(1, (int) round(imagesx($previous) * $scale));
                $height = max(1, (int) round(imagesy($previous) * $scale));

                $resized = $this->resample($previous, $width, $height, $probe['mime']);

                $file = $targetDirectory . '/' . $baseName . '-' . $name . '.' . $extension;

                $this->write($resized, $file, $extension);

                $variants[$name] = [
                    'path'  => $this->relativePath($file),
                    'w'     => $width,
                    'h'     => $height,
                    'bytes' => (int) (@filesize($file) ?: 0),
                ];

                if (!$previousIsSource) {
                    imagedestroy($previous);
                }

                $previous = $resized;
                $previousIsSource = false;
            }

            if (!$previousIsSource) {
                imagedestroy($previous);
            }

            // Variant order in the stored JSON should be smallest first, which
            // is the order srcset wants.
            $ordered = [];
            foreach (['thumb', 'small', 'medium', 'large'] as $name) {
                if (isset($variants[$name])) {
                    $ordered[$name] = $variants[$name];
                }
            }

            return $ordered;
        } finally {
            imagedestroy($source);
        }
    }

    /**
     * The average colour of the image, as #rrggbb.
     *
     * Used as the tile background while the real image downloads, so a grid of
     * photos does not flash white.
     */
    public function dominantColour(string $file): string
    {
        $probe = self::probe($file);

        if ($probe === null) {
            return '';
        }

        $this->guardPixelCount($probe['width'], $probe['height']);

        $source = $this->load($file, $probe['mime']);

        if ($source === null) {
            return '';
        }

        try {
            // Scaling to a single pixel makes GD compute the average for us.
            $tiny = imagecreatetruecolor(1, 1);

            if ($tiny === false) {
                return '';
            }

            imagecopyresampled($tiny, $source, 0, 0, 0, 0, 1, 1, imagesx($source), imagesy($source));

            $rgb = imagecolorat($tiny, 0, 0);
            imagedestroy($tiny);

            return sprintf('#%02x%02x%02x', ($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF);
        } finally {
            imagedestroy($source);
        }
    }

    /**
     * A very small inline preview, base64 encoded.
     *
     * Shown blurred behind the real image so a slow connection still gets the
     * shape and colours of the photo immediately. Kept under about 900 bytes,
     * which is what the database column allows.
     */
    public function placeholder(string $file, int $width = 20): string
    {
        $probe = self::probe($file);

        if ($probe === null) {
            return '';
        }

        $this->guardPixelCount($probe['width'], $probe['height']);

        $source = $this->load($file, $probe['mime']);

        if ($source === null) {
            return '';
        }

        try {
            $ratio = imagesy($source) / max(1, imagesx($source));
            $height = max(1, (int) round($width * $ratio));

            $tiny = $this->resample($source, $width, $height, 'image/jpeg');

            $temporary = tempnam(sys_get_temp_dir(), 'mtl');

            if ($temporary === false) {
                imagedestroy($tiny);

                return '';
            }

            // JPEG at low quality: the result is blurred by CSS anyway, and
            // WebP's header overhead dominates at this size.
            imagejpeg($tiny, $temporary, 40);
            imagedestroy($tiny);

            $bytes = (string) file_get_contents($temporary);
            @unlink($temporary);

            if ($bytes === '' || strlen($bytes) > 900) {
                return '';
            }

            return 'data:image/jpeg;base64,' . base64_encode($bytes);
        } finally {
            imagedestroy($source);
        }
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function guardPixelCount(int $width, int $height): void
    {
        if ($width * $height > $this->maxPixels) {
            // A "decompression bomb": a small file that expands to gigabytes
            // of pixels. Refusing here keeps the request from being killed by
            // the memory limit halfway through.
            throw new \RuntimeException(sprintf(
                'This image is too large to process (%d × %d pixels; the limit is %d megapixels).',
                $width,
                $height,
                (int) round($this->maxPixels / 1_000_000)
            ));
        }
    }

    private function load(string $file, string $mime): ?\GdImage
    {
        $image = match ($mime) {
            'image/jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($file) : false,
            'image/png'  => function_exists('imagecreatefrompng') ? @imagecreatefrompng($file) : false,
            'image/gif'  => function_exists('imagecreatefromgif') ? @imagecreatefromgif($file) : false,
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : false,
            'image/avif' => function_exists('imagecreatefromavif') ? @imagecreatefromavif($file) : false,
            default      => false,
        };

        return $image === false ? null : $image;
    }

    /**
     * Rotates and flips according to the EXIF orientation tag, so every
     * generated variant is stored upright.
     */
    private function applyOrientation(\GdImage $image, int $orientation): \GdImage
    {
        if ($orientation <= 1 || $orientation > 8) {
            return $image;
        }

        $rotation = match ($orientation) {
            3, 4    => 180,
            5, 6    => -90,
            7, 8    => 90,
            default => 0,
        };

        $mirror = in_array($orientation, [2, 4, 5, 7], true);

        $result = $image;

        if ($rotation !== 0) {
            $rotated = imagerotate($image, (float) $rotation, 0);

            if ($rotated !== false) {
                imagedestroy($image);
                $result = $rotated;
            }
        }

        if ($mirror) {
            imageflip($result, IMG_FLIP_HORIZONTAL);
        }

        return $result;
    }

    private function resample(\GdImage $source, int $width, int $height, string $mime): \GdImage
    {
        $target = imagecreatetruecolor($width, $height);

        if ($target === false) {
            throw new \RuntimeException('Out of memory while resizing an image.');
        }

        // PNG, GIF and WebP can carry transparency; without this the resized
        // copy gets a black background.
        if (in_array($mime, ['image/png', 'image/gif', 'image/webp', 'image/avif'], true)) {
            imagealphablending($target, false);
            imagesavealpha($target, true);

            $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);

            if ($transparent !== false) {
                imagefilledrectangle($target, 0, 0, $width, $height, $transparent);
            }
        }

        imagecopyresampled(
            $target,
            $source,
            0, 0, 0, 0,
            $width,
            $height,
            imagesx($source),
            imagesy($source)
        );

        return $target;
    }

    private function write(\GdImage $image, string $file, string $extension): void
    {
        $written = match ($extension) {
            'webp' => imagewebp($image, $file, $this->quality),
            'png'  => imagepng($image, $file, 6),
            default => imagejpeg($image, $file, $this->quality),
        };

        if ($written === false) {
            throw new \RuntimeException('Could not write ' . basename($file) . '. Check that storage/media is writable.');
        }

        @chmod($file, 0644);
    }

    /** Converts an absolute path back to one relative to storage/media. */
    private function relativePath(string $absolute): string
    {
        $root = storage_path('media') . '/';

        return str_starts_with($absolute, $root) ? substr($absolute, strlen($root)) : basename($absolute);
    }
}
