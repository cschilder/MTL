<?php

declare(strict_types=1);

namespace MTL\Support;

defined('MTL_APP') || exit;

/**
 * A QR code encoder, used for the two-factor enrolment code.
 *
 * The alternative would be Google's chart API or a similar service, which
 * would mean sending the TOTP secret of every account to a third party — so
 * that is not an option. Rendering it here keeps the secret on the server, and
 * an SVG needs no image extension.
 *
 * Scope is deliberately narrow: byte mode, error-correction level M, versions
 * 1 to 10 (up to 213 bytes). An otpauth:// URI is a hundred and fifty
 * characters or so, well inside that. Anything longer falls back to the
 * manually typed key that the enrolment screen also shows.
 */
final class QrCode
{
    /**
     * Level M block structure, by version.
     *
     * [ec codewords per block, group 1 blocks, group 1 data codewords,
     *  group 2 blocks, group 2 data codewords]
     *
     * @var array<int,array{0:int,1:int,2:int,3:int,4:int}>
     */
    private const BLOCKS = [
        1  => [10, 1, 16, 0, 0],
        2  => [16, 1, 28, 0, 0],
        3  => [26, 1, 44, 0, 0],
        4  => [18, 2, 32, 0, 0],
        5  => [24, 2, 43, 0, 0],
        6  => [16, 4, 27, 0, 0],
        7  => [18, 4, 31, 0, 0],
        8  => [22, 2, 38, 2, 39],
        9  => [22, 3, 36, 2, 37],
        10 => [26, 4, 43, 1, 44],
    ];

    /**
     * Centres of the alignment patterns, by version.
     *
     * @var array<int,list<int>>
     */
    private const ALIGNMENT = [
        1  => [],
        2  => [6, 18],
        3  => [6, 22],
        4  => [6, 26],
        5  => [6, 30],
        6  => [6, 34],
        7  => [6, 22, 38],
        8  => [6, 24, 42],
        9  => [6, 26, 46],
        10 => [6, 28, 50],
    ];

    /** Error-correction level M, as it appears in the format information. */
    private const EC_LEVEL_BITS = 0b00;

    private int $size;

    /** @var list<list<?bool>> null means "not yet placed" */
    private array $matrix = [];

    /** @var list<list<bool>> true where a function pattern lives */
    private array $reserved = [];

    private function __construct(private readonly int $version)
    {
        $this->size = 17 + 4 * $version;
    }

    /**
     * Renders $text as an SVG.
     *
     * @param int $moduleSize pixels per module in the viewBox
     * @param int $quietZone  modules of margin; the specification requires four
     */
    public static function svg(string $text, int $moduleSize = 4, int $quietZone = 4): string
    {
        $modules = self::encode($text);

        if ($modules === null) {
            return '';
        }

        $count = count($modules);
        $dimension = ($count + $quietZone * 2) * $moduleSize;

        // One path for every dark module rather than one rect each: the markup
        // is a fraction of the size and renders identically.
        $path = '';

        for ($y = 0; $y < $count; ++$y) {
            for ($x = 0; $x < $count; ++$x) {
                if ($modules[$y][$x]) {
                    $path .= sprintf(
                        'M%d %dh%dv%dh-%dz',
                        ($x + $quietZone) * $moduleSize,
                        ($y + $quietZone) * $moduleSize,
                        $moduleSize,
                        $moduleSize,
                        $moduleSize
                    );
                }
            }
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %1$d" width="%1$d" height="%1$d" role="img" aria-label="QR code">'
            . '<rect width="%1$d" height="%1$d" fill="#fff"/>'
            . '<path d="%2$s" fill="#000"/>'
            . '</svg>',
            $dimension,
            $path
        );
    }

    /**
     * Encodes $text into a module matrix.
     *
     * @param int|null $forceMask pins the mask pattern rather than choosing the
     *                            lowest-penalty one. For tests only.
     *
     * @return list<list<bool>>|null null when the text does not fit
     */
    public static function encode(string $text, ?int $forceMask = null): ?array
    {
        $length = strlen($text);

        $version = self::chooseVersion($length);

        if ($version === null) {
            return null;
        }

        $code = new self($version);

        $data = $code->buildDataCodewords($text);
        $final = $code->addErrorCorrection($data);

        $code->reserveFunctionPatterns();
        $code->placeData($final);

        return $code->applyBestMask($forceMask);
    }

    private static function chooseVersion(int $byteLength): ?int
    {
        foreach (self::BLOCKS as $version => $blocks) {
            [, $group1Blocks, $group1Size, $group2Blocks, $group2Size] = $blocks;

            $dataCodewords = $group1Blocks * $group1Size + $group2Blocks * $group2Size;

            // Four bits of mode indicator plus the character count field.
            $headerBits = 4 + self::countBits($version);

            $capacity = intdiv($dataCodewords * 8 - $headerBits, 8);

            if ($byteLength <= $capacity) {
                return $version;
            }
        }

        return null;
    }

    /** The width of the character-count field in byte mode. */
    private static function countBits(int $version): int
    {
        return $version <= 9 ? 8 : 16;
    }

    // -------------------------------------------------------------------------
    // Data
    // -------------------------------------------------------------------------

    /**
     * @return list<int> data codewords, padded to the version's capacity
     */
    private function buildDataCodewords(string $text): array
    {
        [, $group1Blocks, $group1Size, $group2Blocks, $group2Size] = self::BLOCKS[$this->version];

        $totalData = $group1Blocks * $group1Size + $group2Blocks * $group2Size;

        $bits = '';

        // Mode indicator: byte mode.
        $bits .= '0100';
        $bits .= str_pad(decbin(strlen($text)), self::countBits($this->version), '0', STR_PAD_LEFT);

        foreach (str_split($text) as $character) {
            $bits .= str_pad(decbin(ord($character)), 8, '0', STR_PAD_LEFT);
        }

        // Up to four zero bits mark the end of the message.
        $bits .= str_repeat('0', min(4, $totalData * 8 - strlen($bits)));

        // Pad to a whole codeword.
        if (strlen($bits) % 8 !== 0) {
            $bits .= str_repeat('0', 8 - (strlen($bits) % 8));
        }

        $codewords = [];

        foreach (str_split($bits, 8) as $byte) {
            $codewords[] = bindec($byte);
        }

        // The specification's alternating pad bytes fill the remainder.
        $pad = [0xEC, 0x11];
        $index = 0;

        while (count($codewords) < $totalData) {
            $codewords[] = $pad[$index % 2];
            ++$index;
        }

        return $codewords;
    }

    /**
     * Splits the data into blocks, computes Reed-Solomon parity for each, and
     * interleaves the result the way the specification requires.
     *
     * @param list<int> $data
     *
     * @return list<int>
     */
    private function addErrorCorrection(array $data): array
    {
        [$ecPerBlock, $group1Blocks, $group1Size, $group2Blocks, $group2Size] = self::BLOCKS[$this->version];

        $blocks = [];
        $ecBlocks = [];
        $offset = 0;

        foreach ([[$group1Blocks, $group1Size], [$group2Blocks, $group2Size]] as [$count, $size]) {
            for ($i = 0; $i < $count; ++$i) {
                $block = array_slice($data, $offset, $size);
                $offset += $size;

                $blocks[] = $block;
                $ecBlocks[] = self::reedSolomon($block, $ecPerBlock);
            }
        }

        $result = [];

        // Data codewords, one from each block in turn.
        $longest = max(array_map('count', $blocks));

        for ($i = 0; $i < $longest; ++$i) {
            foreach ($blocks as $block) {
                if (isset($block[$i])) {
                    $result[] = $block[$i];
                }
            }
        }

        // Then the parity codewords, the same way.
        for ($i = 0; $i < $ecPerBlock; ++$i) {
            foreach ($ecBlocks as $block) {
                if (isset($block[$i])) {
                    $result[] = $block[$i];
                }
            }
        }

        return $result;
    }

    /**
     * Reed-Solomon parity over GF(256) with the QR generator polynomial
     * x^8 + x^4 + x^3 + x^2 + 1.
     *
     * @param list<int> $data
     *
     * @return list<int>
     */
    private static function reedSolomon(array $data, int $degree): array
    {
        [$exp, $log] = self::galoisTables();

        // The generator polynomial, built up as a product of (x - a^i).
        $generator = [1];

        for ($i = 0; $i < $degree; ++$i) {
            $next = array_fill(0, count($generator) + 1, 0);

            foreach ($generator as $index => $coefficient) {
                $next[$index] ^= $coefficient;

                if ($coefficient !== 0) {
                    $next[$index + 1] ^= $exp[($log[$coefficient] + $i) % 255];
                }
            }

            $generator = $next;
        }

        // Polynomial division: the remainder is the parity.
        $remainder = array_fill(0, $degree, 0);

        foreach ($data as $byte) {
            $factor = $byte ^ $remainder[0];

            array_shift($remainder);
            $remainder[] = 0;

            if ($factor !== 0) {
                foreach ($generator as $index => $coefficient) {
                    if ($index === 0 || $coefficient === 0) {
                        continue;
                    }

                    $remainder[$index - 1] ^= $exp[($log[$coefficient] + $log[$factor]) % 255];
                }
            }
        }

        return $remainder;
    }

    /**
     * @return array{0:list<int>,1:array<int,int>} exponent and logarithm tables
     */
    private static function galoisTables(): array
    {
        static $exp = null;
        static $log = null;

        if ($exp !== null && $log !== null) {
            return [$exp, $log];
        }

        $exp = array_fill(0, 256, 0);
        $log = array_fill(0, 256, 0);

        $value = 1;

        for ($i = 0; $i < 255; ++$i) {
            $exp[$i] = $value;
            $log[$value] = $i;

            $value <<= 1;

            // Reduce modulo the field polynomial 0x11D.
            if ($value & 0x100) {
                $value ^= 0x11D;
            }
        }

        return [$exp, $log];
    }

    // -------------------------------------------------------------------------
    // Matrix
    // -------------------------------------------------------------------------

    private function reserveFunctionPatterns(): void
    {
        $this->matrix = array_fill(0, $this->size, array_fill(0, $this->size, null));
        $this->reserved = array_fill(0, $this->size, array_fill(0, $this->size, false));

        // The three finder patterns and their separators.
        foreach ([[0, 0], [$this->size - 7, 0], [0, $this->size - 7]] as [$x, $y]) {
            $this->placeFinder($x, $y);
        }

        // Timing patterns along row and column 6.
        for ($i = 8; $i < $this->size - 8; ++$i) {
            $module = $i % 2 === 0;

            $this->setFunction($i, 6, $module);
            $this->setFunction(6, $i, $module);
        }

        $this->placeAlignmentPatterns();

        // The dark module, which is always set.
        $this->setFunction(8, $this->size - 8, true);

        $this->reserveFormatArea();

        if ($this->version >= 7) {
            $this->reserveVersionArea();
        }
    }

    private function placeFinder(int $originX, int $originY): void
    {
        // The 7×7 pattern plus a one-module separator on the inner sides.
        for ($y = -1; $y <= 7; ++$y) {
            for ($x = -1; $x <= 7; ++$x) {
                $px = $originX + $x;
                $py = $originY + $y;

                if ($px < 0 || $py < 0 || $px >= $this->size || $py >= $this->size) {
                    continue;
                }

                $inRing = ($x === 0 || $x === 6) && $y >= 0 && $y <= 6;
                $inRing = $inRing || (($y === 0 || $y === 6) && $x >= 0 && $x <= 6);
                $inCore = $x >= 2 && $x <= 4 && $y >= 2 && $y <= 4;

                $this->setFunction($px, $py, $inRing || $inCore);
            }
        }
    }

    private function placeAlignmentPatterns(): void
    {
        $centres = self::ALIGNMENT[$this->version];

        foreach ($centres as $cy) {
            foreach ($centres as $cx) {
                // The three corners hold finder patterns instead.
                if (($cx === 6 && $cy === 6)
                    || ($cx === 6 && $cy === $this->size - 7)
                    || ($cx === $this->size - 7 && $cy === 6)
                ) {
                    continue;
                }

                for ($y = -2; $y <= 2; ++$y) {
                    for ($x = -2; $x <= 2; ++$x) {
                        $dark = max(abs($x), abs($y)) !== 1;

                        $this->setFunction($cx + $x, $cy + $y, $dark);
                    }
                }
            }
        }
    }

    private function reserveFormatArea(): void
    {
        for ($i = 0; $i < 9; ++$i) {
            $this->reserve(8, $i);
            $this->reserve($i, 8);
        }

        for ($i = 0; $i < 8; ++$i) {
            $this->reserve($this->size - 1 - $i, 8);
            $this->reserve(8, $this->size - 1 - $i);
        }
    }

    private function reserveVersionArea(): void
    {
        for ($i = 0; $i < 6; ++$i) {
            for ($j = 0; $j < 3; ++$j) {
                $this->reserve($this->size - 11 + $j, $i);
                $this->reserve($i, $this->size - 11 + $j);
            }
        }
    }

    private function setFunction(int $x, int $y, bool $dark): void
    {
        if ($x < 0 || $y < 0 || $x >= $this->size || $y >= $this->size) {
            return;
        }

        $this->matrix[$y][$x] = $dark;
        $this->reserved[$y][$x] = true;
    }

    private function reserve(int $x, int $y): void
    {
        if ($x < 0 || $y < 0 || $x >= $this->size || $y >= $this->size) {
            return;
        }

        $this->reserved[$y][$x] = true;
    }

    /**
     * Lays the codewords out in the zig-zag the specification defines: two
     * modules wide, upwards then downwards, from the bottom right.
     *
     * @param list<int> $codewords
     */
    private function placeData(array $codewords): void
    {
        $bits = '';

        foreach ($codewords as $codeword) {
            $bits .= str_pad(decbin($codeword), 8, '0', STR_PAD_LEFT);
        }

        $index = 0;
        $upward = true;

        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            // Column 6 is the vertical timing pattern and is skipped entirely.
            if ($right === 6) {
                $right = 5;
            }

            for ($step = 0; $step < $this->size; ++$step) {
                $y = $upward ? $this->size - 1 - $step : $step;

                for ($column = 0; $column < 2; ++$column) {
                    $x = $right - $column;

                    if ($this->reserved[$y][$x]) {
                        continue;
                    }

                    // Remaining modules past the end of the data stay light.
                    $this->matrix[$y][$x] = ($bits[$index] ?? '0') === '1';
                    ++$index;
                }
            }

            $upward = !$upward;
        }
    }

    /**
     * Tries all eight masks and keeps the one with the lowest penalty, which
     * is what makes the code readable at an angle and in poor light.
     *
     * @param int|null $forceMask pins the mask instead of choosing one. Only
     *                            the tests use it: fixing the mask is what lets
     *                            a golden matrix be compared byte for byte.
     *
     * @return list<list<bool>>
     */
    private function applyBestMask(?int $forceMask = null): array
    {
        $masks = $forceMask === null ? range(0, 7) : [$forceMask];

        $best = null;
        $bestPenalty = PHP_INT_MAX;
        $bestMask = 0;

        foreach ($masks as $mask) {
            $candidate = $this->withMask($mask);

            $penalty = self::penalty($candidate);

            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $best = $candidate;
                $bestMask = $mask;
            }
        }

        /** @var list<list<bool>> $best */
        $this->writeFormatInformation($best, $bestMask);

        if ($this->version >= 7) {
            $this->writeVersionInformation($best);
        }

        return $best;
    }

    /**
     * @return list<list<bool>>
     */
    private function withMask(int $mask): array
    {
        $result = [];

        for ($y = 0; $y < $this->size; ++$y) {
            $row = [];

            for ($x = 0; $x < $this->size; ++$x) {
                $module = (bool) ($this->matrix[$y][$x] ?? false);

                if (!$this->reserved[$y][$x] && self::maskCondition($mask, $x, $y)) {
                    $module = !$module;
                }

                $row[] = $module;
            }

            $result[] = $row;
        }

        return $result;
    }

    private static function maskCondition(int $mask, int $x, int $y): bool
    {
        return match ($mask) {
            0 => ($x + $y) % 2 === 0,
            1 => $y % 2 === 0,
            2 => $x % 3 === 0,
            3 => ($x + $y) % 3 === 0,
            4 => (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0,
            5 => (($x * $y) % 2) + (($x * $y) % 3) === 0,
            6 => (((($x * $y) % 2) + (($x * $y) % 3)) % 2) === 0,
            7 => (((($x + $y) % 2) + (($x * $y) % 3)) % 2) === 0,
            default => false,
        };
    }

    /**
     * The four penalty rules from the specification.
     *
     * @param list<list<bool>> $matrix
     */
    private static function penalty(array $matrix): int
    {
        $size = count($matrix);
        $score = 0;

        // Rule 1: runs of five or more identical modules in a row or column.
        for ($i = 0; $i < $size; ++$i) {
            foreach ([true, false] as $horizontal) {
                $run = 1;

                for ($j = 1; $j < $size; ++$j) {
                    $current = $horizontal ? $matrix[$i][$j] : $matrix[$j][$i];
                    $previous = $horizontal ? $matrix[$i][$j - 1] : $matrix[$j - 1][$i];

                    if ($current === $previous) {
                        ++$run;
                        continue;
                    }

                    if ($run >= 5) {
                        $score += 3 + ($run - 5);
                    }

                    $run = 1;
                }

                if ($run >= 5) {
                    $score += 3 + ($run - 5);
                }
            }
        }

        // Rule 2: every 2×2 block of one colour.
        for ($y = 0; $y < $size - 1; ++$y) {
            for ($x = 0; $x < $size - 1; ++$x) {
                $value = $matrix[$y][$x];

                if ($value === $matrix[$y][$x + 1]
                    && $value === $matrix[$y + 1][$x]
                    && $value === $matrix[$y + 1][$x + 1]
                ) {
                    $score += 3;
                }
            }
        }

        // Rule 3: the finder-like sequence 1:1:3:1:1 with four light modules
        // on either side, which a scanner could mistake for a finder pattern.
        $patterns = [
            [true, false, true, true, true, false, true, false, false, false, false],
            [false, false, false, false, true, false, true, true, true, false, true],
        ];

        for ($y = 0; $y < $size; ++$y) {
            for ($x = 0; $x < $size; ++$x) {
                foreach ($patterns as $pattern) {
                    if ($x + count($pattern) <= $size) {
                        $match = true;

                        foreach ($pattern as $offset => $expected) {
                            if ($matrix[$y][$x + $offset] !== $expected) {
                                $match = false;
                                break;
                            }
                        }

                        if ($match) {
                            $score += 40;
                        }
                    }

                    if ($y + count($pattern) <= $size) {
                        $match = true;

                        foreach ($pattern as $offset => $expected) {
                            if ($matrix[$y + $offset][$x] !== $expected) {
                                $match = false;
                                break;
                            }
                        }

                        if ($match) {
                            $score += 40;
                        }
                    }
                }
            }
        }

        // Rule 4: how far the proportion of dark modules strays from half.
        $dark = 0;

        foreach ($matrix as $row) {
            foreach ($row as $module) {
                if ($module) {
                    ++$dark;
                }
            }
        }

        $percentage = ($dark * 100) / ($size * $size);
        $deviation = (int) (abs($percentage - 50) / 5);

        return $score + $deviation * 10;
    }

    /**
     * @param list<list<bool>> $matrix
     */
    private function writeFormatInformation(array &$matrix, int $mask): void
    {
        $data = (self::EC_LEVEL_BITS << 3) | $mask;

        // BCH(15,5) error correction over the five data bits.
        $remainder = $data;

        for ($i = 0; $i < 10; ++$i) {
            $remainder = ($remainder << 1) ^ ((($remainder >> 9) & 1) * 0x537);
        }

        // The final XOR keeps the all-zero case from looking like a pattern.
        $bits = (($data << 10) | $remainder) ^ 0x5412;

        for ($i = 0; $i < 15; ++$i) {
            $bit = (bool) (($bits >> $i) & 1);

            // The two copies, placed around the top-left finder and split
            // between the other two.
            if ($i < 6) {
                $matrix[$i][8] = $bit;
            } elseif ($i === 6) {
                $matrix[7][8] = $bit;
            } elseif ($i === 7) {
                $matrix[8][8] = $bit;
            } elseif ($i === 8) {
                $matrix[8][7] = $bit;
            } else {
                $matrix[8][14 - $i] = $bit;
            }

            if ($i < 8) {
                $matrix[8][$this->size - 1 - $i] = $bit;
            } else {
                $matrix[$this->size - 15 + $i][8] = $bit;
            }
        }
    }

    /**
     * @param list<list<bool>> $matrix
     */
    private function writeVersionInformation(array &$matrix): void
    {
        $remainder = $this->version;

        for ($i = 0; $i < 12; ++$i) {
            $remainder = ($remainder << 1) ^ ((($remainder >> 11) & 1) * 0x1F25);
        }

        $bits = ($this->version << 12) | $remainder;

        for ($i = 0; $i < 18; ++$i) {
            $bit = (bool) (($bits >> $i) & 1);

            $x = intdiv($i, 3);
            $y = $this->size - 11 + ($i % 3);

            $matrix[$y][$x] = $bit;
            $matrix[$x][$y] = $bit;
        }
    }
}
