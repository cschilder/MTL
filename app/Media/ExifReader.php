<?php

declare(strict_types=1);

namespace MTL\Media;

use MTL\Support\Str;

defined('MTL_APP') || exit;

/**
 * Reads the metadata a camera or phone writes into a photo.
 *
 * The interesting parts for MTL are the capture time and the GPS fix: together
 * they can place a step on the globe and on the timeline without the author
 * typing anything.
 *
 * EXIF is written by hundreds of devices and plenty of them get it wrong, so
 * everything here is defensive: a malformed tag yields null rather than an
 * exception, and strings are forced into valid UTF-8 before they can reach the
 * database or the JSON encoder.
 */
final class ExifReader
{
    /**
     * @return array{
     *   captured_at: ?string,
     *   latitude: ?float,
     *   longitude: ?float,
     *   altitude: ?float,
     *   orientation: int,
     *   camera_make: string,
     *   camera_model: string,
     *   lens: string,
     *   exposure: string,
     *   iso: ?int,
     *   focal_length: string,
     *   width: ?int,
     *   height: ?int
     * }
     */
    public static function read(string $file): array
    {
        $empty = [
            'captured_at'  => null,
            'latitude'     => null,
            'longitude'    => null,
            'altitude'     => null,
            'orientation'  => 1,
            'camera_make'  => '',
            'camera_model' => '',
            'lens'         => '',
            'exposure'     => '',
            'iso'          => null,
            'focal_length' => '',
            'width'        => null,
            'height'       => null,
        ];

        if (!function_exists('exif_read_data') || !is_file($file)) {
            return $empty;
        }

        // Only JPEG and TIFF carry EXIF; calling exif_read_data on a PNG emits
        // a warning and returns false.
        $probe = @getimagesize($file);
        $mime = is_array($probe) ? (string) ($probe['mime'] ?? '') : '';

        if (!in_array($mime, ['image/jpeg', 'image/tiff'], true)) {
            return $empty;
        }

        $exif = @exif_read_data($file, 'ANY_TAG', true);

        if (!is_array($exif)) {
            return $empty;
        }

        $ifd0 = $exif['IFD0'] ?? [];
        $sub = $exif['EXIF'] ?? [];
        $gps = $exif['GPS'] ?? [];

        return [
            'captured_at'  => self::readCaptureTime($sub, $ifd0),
            'latitude'     => self::readCoordinate($gps, 'GPSLatitude', 'GPSLatitudeRef', 'S'),
            'longitude'    => self::readCoordinate($gps, 'GPSLongitude', 'GPSLongitudeRef', 'W'),
            'altitude'     => self::readAltitude($gps),
            'orientation'  => self::readOrientation($ifd0),
            'camera_make'  => self::readString($ifd0['Make'] ?? '', 80),
            'camera_model' => self::readString($ifd0['Model'] ?? '', 80),
            'lens'         => self::readString($sub['UndefinedTag:0xA434'] ?? $sub['LensModel'] ?? '', 120),
            'exposure'     => self::readExposure($sub),
            'iso'          => self::readIso($sub),
            'focal_length' => self::readFocalLength($sub),
            'width'        => isset($sub['ExifImageWidth']) ? (int) $sub['ExifImageWidth'] : null,
            'height'       => isset($sub['ExifImageLength']) ? (int) $sub['ExifImageLength'] : null,
        ];
    }

    /**
     * The moment the shutter fired, as a UTC datetime string.
     *
     * EXIF timestamps are local time with no zone, which is exactly what a
     * traveller wants to see. The offset tag is used when the camera wrote one;
     * otherwise the value is stored as-is and treated as UTC, and the author
     * can correct it in the media editor.
     *
     * @param array<string,mixed> $sub
     * @param array<string,mixed> $ifd0
     */
    private static function readCaptureTime(array $sub, array $ifd0): ?string
    {
        $raw = $sub['DateTimeOriginal']
            ?? $sub['DateTimeDigitized']
            ?? $ifd0['DateTime']
            ?? null;

        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        // The EXIF format is "YYYY:MM:DD HH:MM:SS".
        if (preg_match('/^(\d{4}):(\d{2}):(\d{2})[ T](\d{2}):(\d{2}):(\d{2})/', trim($raw), $m) !== 1) {
            return null;
        }

        // Some cameras write all zeros when the clock was never set.
        if ($m[1] === '0000') {
            return null;
        }

        $offset = $sub['UndefinedTag:0x9011'] ?? $sub['OffsetTimeOriginal'] ?? null;
        $timezone = new \DateTimeZone('UTC');

        if (is_string($offset) && preg_match('/^[+-]\d{2}:\d{2}$/', trim($offset)) === 1) {
            try {
                $timezone = new \DateTimeZone(trim($offset));
            } catch (\Exception) {
                $timezone = new \DateTimeZone('UTC');
            }
        }

        try {
            $date = new \DateTimeImmutable(
                sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6]),
                $timezone
            );
        } catch (\Exception) {
            return null;
        }

        // A date far outside the range of digital photography means a bad tag.
        $year = (int) $date->format('Y');

        if ($year < 1990 || $year > (int) gmdate('Y') + 1) {
            return null;
        }

        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * Converts a GPS coordinate from degrees/minutes/seconds to a decimal.
     *
     * @param array<string,mixed> $gps
     * @param string              $negativeRef the hemisphere letter that makes
     *                                         the value negative
     */
    private static function readCoordinate(array $gps, string $key, string $refKey, string $negativeRef): ?float
    {
        $parts = $gps[$key] ?? null;
        $reference = $gps[$refKey] ?? '';

        if (!is_array($parts) || count($parts) < 2) {
            return null;
        }

        $degrees = self::rational($parts[0] ?? null);
        $minutes = self::rational($parts[1] ?? null);
        $seconds = self::rational($parts[2] ?? null);

        if ($degrees === null) {
            return null;
        }

        $decimal = $degrees + (($minutes ?? 0) / 60) + (($seconds ?? 0) / 3600);

        if (is_string($reference) && strtoupper(trim($reference)) === $negativeRef) {
            $decimal = -$decimal;
        }

        $limit = $key === 'GPSLatitude' ? 90 : 180;

        if (!is_finite($decimal) || abs($decimal) > $limit) {
            return null;
        }

        // A fix at exactly 0,0 in the Gulf of Guinea is almost always a device
        // reporting "no fix" rather than a real position.
        if (abs($decimal) < 0.000001) {
            return null;
        }

        return round($decimal, 7);
    }

    /**
     * @param array<string,mixed> $gps
     */
    private static function readAltitude(array $gps): ?float
    {
        $value = self::rational($gps['GPSAltitude'] ?? null);

        if ($value === null || !is_finite($value)) {
            return null;
        }

        // Reference 1 means below sea level.
        if ((int) ($gps['GPSAltitudeRef'] ?? 0) === 1) {
            $value = -$value;
        }

        // Anything outside this range is a broken tag, not a real elevation.
        if ($value < -500 || $value > 12000) {
            return null;
        }

        return round($value, 2);
    }

    /**
     * @param array<string,mixed> $ifd0
     */
    private static function readOrientation(array $ifd0): int
    {
        $value = (int) ($ifd0['Orientation'] ?? 1);

        return ($value >= 1 && $value <= 8) ? $value : 1;
    }

    /**
     * @param array<string,mixed> $sub
     */
    private static function readExposure(array $sub): string
    {
        $parts = [];

        $exposure = $sub['ExposureTime'] ?? null;

        if (is_string($exposure) && $exposure !== '') {
            // Already a fraction such as "1/250"; leave it alone.
            $parts[] = str_contains($exposure, '/') ? $exposure . 's' : $exposure . 's';
        }

        $aperture = self::rational($sub['FNumber'] ?? null);

        if ($aperture !== null && $aperture > 0) {
            $parts[] = 'f/' . rtrim(rtrim(number_format($aperture, 1, '.', ''), '0'), '.');
        }

        return substr(implode(' · ', $parts), 0, 60);
    }

    /**
     * @param array<string,mixed> $sub
     */
    private static function readIso(array $sub): ?int
    {
        $value = $sub['ISOSpeedRatings'] ?? $sub['PhotographicSensitivity'] ?? null;

        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        $iso = (int) $value;

        return ($iso >= 1 && $iso <= 4_000_000) ? $iso : null;
    }

    /**
     * @param array<string,mixed> $sub
     */
    private static function readFocalLength(array $sub): string
    {
        $value = self::rational($sub['FocalLength'] ?? null);

        if ($value === null || $value <= 0 || $value > 5000) {
            return '';
        }

        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.') . 'mm';
    }

    /**
     * EXIF numbers arrive as "num/den" strings.
     */
    private static function rational(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (!is_string($value) || $value === '') {
            return null;
        }

        if (str_contains($value, '/')) {
            [$numerator, $denominator] = array_pad(explode('/', $value, 2), 2, '1');

            if (!is_numeric($numerator) || !is_numeric($denominator) || (float) $denominator == 0.0) {
                return null;
            }

            return (float) $numerator / (float) $denominator;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Cleans a text tag: cameras write trailing NULs, padding spaces and
     * occasionally text in a legacy encoding.
     */
    private static function readString(mixed $value, int $maxLength): string
    {
        if (!is_string($value)) {
            return '';
        }

        $value = trim(str_replace("\0", '', $value));
        $value = Str::toUtf8($value);
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? $value;

        return mb_substr(trim($value), 0, $maxLength, 'UTF-8');
    }

    /**
     * Removes location data from a JPEG in place.
     *
     * Offered as a setting for anyone who does not want their home address
     * embedded in the photos on a public page. Without an EXIF writer the only
     * dependable way to do this with the standard extensions is to re-encode
     * the image, which drops every APP segment along with the GPS block.
     */
    public static function stripLocation(string $file): bool
    {
        if (!function_exists('imagecreatefromjpeg')) {
            return false;
        }

        $probe = @getimagesize($file);

        if (!is_array($probe) || ($probe['mime'] ?? '') !== 'image/jpeg') {
            return false;
        }

        $image = @imagecreatefromjpeg($file);

        if ($image === false) {
            return false;
        }

        // Re-encoded at high quality: this runs on the stored original, so the
        // loss has to be small enough not to matter.
        $written = imagejpeg($image, $file, 95);
        imagedestroy($image);

        return $written;
    }
}
