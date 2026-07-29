<?php

declare(strict_types=1);

namespace MTL\Markdown;

defined('MTL_APP') || exit;

/**
 * Inline-level markdown: emphasis, links, images, code spans and the GFM
 * additions (strikethrough, bare-URL autolinks, footnote references).
 *
 * Emphasis follows CommonMark's delimiter-stack algorithm rather than a
 * regular expression. That is the only way to get cases like `**a *b* c**`,
 * `*a**b**c*` and `foo_bar_baz` right, and those turn up in ordinary prose
 * often enough to matter.
 *
 * Raw HTML in the source is escaped, never passed through — see Markdown for
 * why.
 */
final class InlineParser
{
    /** Characters that a backslash may escape, per CommonMark. */
    private const ESCAPABLE = '!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~';

    private string $text = '';

    private int $length = 0;

    private int $position = 0;

    /** @var list<string> pieces of output, joined at the end */
    private array $out = [];

    /**
     * Open delimiter runs awaiting a match.
     *
     * @var list<array{char:string,length:int,index:int,canOpen:bool,canClose:bool,active:bool}>
     */
    private array $delimiters = [];

    /**
     * @param array<string,array{url:string,title:string}> $references link reference definitions
     * @param array<string,int>                            $footnotes  label => ordinal
     */
    public function __construct(
        private readonly array $references = [],
        private array $footnotes = [],
        private readonly ?MediaResolver $media = null,
    ) {
    }

    /** @return array<string,int> footnote labels referenced, in order of use */
    public function usedFootnotes(): array
    {
        return $this->footnotes;
    }

    public function parse(string $text): string
    {
        $this->text = $text;
        $this->length = strlen($text);
        $this->position = 0;
        $this->out = [];
        $this->delimiters = [];

        while ($this->position < $this->length) {
            $char = $this->text[$this->position];

            $handled = match ($char) {
                '\\'    => $this->parseBackslash(),
                '`'     => $this->parseCodeSpan(),
                '*', '_', '~' => $this->parseDelimiterRun($char),
                '['     => $this->parseLinkOpen(),
                '!'     => $this->parseImageOpen(),
                ']'     => $this->parseLinkClose(),
                '<'     => $this->parseAutolinkOrHtml(),
                '&'     => $this->parseEntity(),
                "\n"    => $this->parseNewline(),
                default => false,
            };

            if ($handled) {
                continue;
            }

            // A bare URL that GFM would linkify, but only at a position where a
            // link may start.
            if (($char === 'h' || $char === 'w' || $char === 'm') && $this->parseBareAutolink()) {
                continue;
            }

            // Ordinary text. The scan is byte-wise, so a single byte of a
            // multi-byte character must be passed through untouched: running it
            // through htmlspecialchars would see invalid UTF-8 and replace it,
            // turning "Þingvellir" into "\u{FFFD}ingvellir".
            //
            // Only '>' needs escaping here; '<' and '&' have their own branches
            // above, and quotes are only significant inside attributes, which
            // are escaped where they are built.
            $this->out[] = $char === '>' ? '&gt;' : $char;
            ++$this->position;
        }

        $this->resolveEmphasis(0);

        return implode('', $this->out);
    }

    // -------------------------------------------------------------------------
    // Simple constructs
    // -------------------------------------------------------------------------

    private function parseBackslash(): bool
    {
        $next = $this->text[$this->position + 1] ?? '';

        if ($next === "\n") {
            // Backslash at end of line is a hard break.
            $this->out[] = "<br>\n";
            $this->position += 2;

            return true;
        }

        if ($next !== '' && str_contains(self::ESCAPABLE, $next)) {
            $this->out[] = self::escape($next);
            $this->position += 2;

            return true;
        }

        $this->out[] = '\\';
        ++$this->position;

        return true;
    }

    /**
     * A code span is delimited by matching runs of backticks. The content is
     * taken literally, so nothing inside is parsed further.
     */
    private function parseCodeSpan(): bool
    {
        $start = $this->position;
        $openLength = strspn($this->text, '`', $start);
        $contentStart = $start + $openLength;

        $search = $contentStart;

        while ($search < $this->length) {
            $next = strpos($this->text, '`', $search);

            if ($next === false) {
                break;
            }

            $runLength = strspn($this->text, '`', $next);

            if ($runLength === $openLength) {
                $content = substr($this->text, $contentStart, $next - $contentStart);

                // Line endings inside a code span become spaces, and a single
                // leading and trailing space is stripped when both are present.
                $content = str_replace("\n", ' ', $content);

                if (strlen($content) > 2
                    && str_starts_with($content, ' ')
                    && str_ends_with($content, ' ')
                    && trim($content) !== ''
                ) {
                    $content = substr($content, 1, -1);
                }

                $this->out[] = '<code>' . self::escape($content) . '</code>';
                $this->position = $next + $runLength;

                return true;
            }

            $search = $next + $runLength;
        }

        // No closing run: the backticks are literal text.
        $this->out[] = str_repeat('`', $openLength);
        $this->position += $openLength;

        return true;
    }

    private function parseNewline(): bool
    {
        // Two or more trailing spaces before the newline make a hard break.
        $trailing = 0;
        $index = count($this->out) - 1;

        while ($index >= 0 && $this->out[$index] === ' ') {
            ++$trailing;
            --$index;
        }

        if ($trailing >= 2) {
            array_splice($this->out, $index + 1);
            $this->out[] = "<br>\n";
        } else {
            // A single trailing space is dropped; a soft break becomes a
            // newline in the output, which HTML renders as a space.
            if ($trailing === 1) {
                array_pop($this->out);
            }
            $this->out[] = "\n";
        }

        ++$this->position;

        return true;
    }

    private function parseEntity(): bool
    {
        // Named and numeric character references pass through untouched;
        // anything else is a literal ampersand.
        if (preg_match('/\G&(?:#[0-9]{1,7}|#[xX][0-9a-fA-F]{1,6}|[a-zA-Z][a-zA-Z0-9]{1,31});/', $this->text, $m, 0, $this->position) === 1) {
            $this->out[] = $m[0];
            $this->position += strlen($m[0]);

            return true;
        }

        $this->out[] = '&amp;';
        ++$this->position;

        return true;
    }

    /**
     * `<https://example.com>` and `<mail@example.com>`.
     *
     * A `<` that starts anything else — including a raw HTML tag — is escaped,
     * because raw HTML is not accepted.
     */
    private function parseAutolinkOrHtml(): bool
    {
        if (preg_match('/\G<([a-zA-Z][a-zA-Z0-9+.-]{1,31}:[^<>\x00-\x20]*)>/', $this->text, $m, 0, $this->position) === 1) {
            $url = $m[1];

            if (Url::isSafe($url)) {
                $this->out[] = '<a href="' . self::escape(Url::normalise($url)) . '" rel="nofollow noopener" target="_blank">'
                    . self::escape($url) . '</a>';
                $this->position += strlen($m[0]);

                return true;
            }
        }

        if (preg_match('/\G<([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})>/', $this->text, $m, 0, $this->position) === 1) {
            $this->out[] = '<a href="mailto:' . self::escape($m[1]) . '">' . self::escape($m[1]) . '</a>';
            $this->position += strlen($m[0]);

            return true;
        }

        $this->out[] = '&lt;';
        ++$this->position;

        return true;
    }

    /**
     * GFM's extended autolinks: a bare www./http(s)/mailto run in ordinary
     * text becomes a link.
     */
    private function parseBareAutolink(): bool
    {
        // Only at the start of the text or after whitespace or an opening
        // delimiter, so "shttp://x" is not linkified.
        $previous = $this->position > 0 ? $this->text[$this->position - 1] : ' ';

        if (!preg_match('/[\s*_~(]/', $previous)) {
            return false;
        }

        if (preg_match('#\G(https?://|www\.|mailto:)[^\s<]+#i', $this->text, $m, 0, $this->position) !== 1) {
            return false;
        }

        $match = $m[0];

        // Trailing punctuation belongs to the sentence, not to the URL.
        $trimmed = rtrim($match, '.,;:!?\'"');

        // A closing parenthesis only belongs to the URL if it balances one
        // inside it, which is what makes Wikipedia links work.
        while (str_ends_with($trimmed, ')') && substr_count($trimmed, ')') > substr_count($trimmed, '(')) {
            $trimmed = substr($trimmed, 0, -1);
        }

        if ($trimmed === '' || strlen($trimmed) < 6) {
            return false;
        }

        $href = str_starts_with(strtolower($trimmed), 'www.') ? 'https://' . $trimmed : $trimmed;

        if (!Url::isSafe($href)) {
            return false;
        }

        $this->out[] = '<a href="' . self::escape(Url::normalise($href)) . '" rel="nofollow noopener" target="_blank">'
            . self::escape($trimmed) . '</a>';

        $this->position += strlen($trimmed);

        return true;
    }

    // -------------------------------------------------------------------------
    // Emphasis
    // -------------------------------------------------------------------------

    /**
     * Records a run of `*`, `_` or `~` on the delimiter stack, to be paired up
     * once the whole inline sequence has been scanned.
     */
    private function parseDelimiterRun(string $char): bool
    {
        $start = $this->position;
        $runLength = strspn($this->text, $char, $start);

        // GFM strikethrough uses exactly one or two tildes; longer runs are
        // literal.
        if ($char === '~' && $runLength > 2) {
            $this->out[] = str_repeat('~', $runLength);
            $this->position += $runLength;

            return true;
        }

        $before = $start > 0 ? $this->text[$start - 1] : "\n";
        $after = ($start + $runLength) < $this->length ? $this->text[$start + $runLength] : "\n";

        $beforeIsWhitespace = preg_match('/\s/', $before) === 1;
        $afterIsWhitespace = preg_match('/\s/', $after) === 1;
        $beforeIsPunctuation = self::isPunctuation($before);
        $afterIsPunctuation = self::isPunctuation($after);

        // "Left-flanking" and "right-flanking" as CommonMark defines them.
        $leftFlanking = !$afterIsWhitespace
            && (!$afterIsPunctuation || $beforeIsWhitespace || $beforeIsPunctuation);

        $rightFlanking = !$beforeIsWhitespace
            && (!$beforeIsPunctuation || $afterIsWhitespace || $afterIsPunctuation);

        if ($char === '_') {
            // Underscores may not open or close inside a word, so snake_case
            // identifiers survive unchanged.
            $canOpen = $leftFlanking && (!$rightFlanking || $beforeIsPunctuation);
            $canClose = $rightFlanking && (!$leftFlanking || $afterIsPunctuation);
        } else {
            $canOpen = $leftFlanking;
            $canClose = $rightFlanking;
        }

        $this->out[] = str_repeat($char, $runLength);

        $this->delimiters[] = [
            'char'     => $char,
            'length'   => $runLength,
            'index'    => count($this->out) - 1,
            'canOpen'  => $canOpen,
            'canClose' => $canClose,
            'active'   => true,
        ];

        $this->position += $runLength;

        return true;
    }

    /**
     * Pairs delimiters from the stack, innermost first, rewriting the output
     * pieces in place.
     *
     * @param int $floor stack index below which pairing must not reach, used
     *                   so emphasis cannot straddle a link boundary
     */
    private function resolveEmphasis(int $floor): void
    {
        $count = count($this->delimiters);

        for ($closerIndex = $floor; $closerIndex < $count; ++$closerIndex) {
            $closer = &$this->delimiters[$closerIndex];

            if (!$closer['active'] || !$closer['canClose'] || $closer['length'] === 0) {
                continue;
            }

            // Walk back for the nearest compatible opener.
            for ($openerIndex = $closerIndex - 1; $openerIndex >= $floor; --$openerIndex) {
                $opener = &$this->delimiters[$openerIndex];

                if (!$opener['active'] || !$opener['canOpen'] || $opener['char'] !== $closer['char'] || $opener['length'] === 0) {
                    unset($opener);
                    continue;
                }

                // CommonMark's "rule of three": if one of the two runs can both
                // open and close, their combined length must not be a multiple
                // of three unless both are.
                $sum = $opener['length'] + $closer['length'];

                if (($opener['canClose'] || $closer['canOpen'])
                    && $sum % 3 === 0
                    && !($opener['length'] % 3 === 0 && $closer['length'] % 3 === 0)
                ) {
                    unset($opener);
                    continue;
                }

                $this->closeEmphasis($openerIndex, $closerIndex);

                // Everything between the two is now consumed.
                for ($between = $openerIndex + 1; $between < $closerIndex; ++$between) {
                    $this->delimiters[$between]['active'] = false;
                }

                unset($opener);

                // The closer may still have length left for another pairing.
                if ($this->delimiters[$closerIndex]['length'] > 0) {
                    --$closerIndex;
                }

                break;
            }
        }

        unset($closer);
    }

    private function closeEmphasis(int $openerIndex, int $closerIndex): void
    {
        $opener = &$this->delimiters[$openerIndex];
        $closer = &$this->delimiters[$closerIndex];

        if ($opener['char'] === '~') {
            $use = min($opener['length'], $closer['length']);
            $openTag = '<del>';
            $closeTag = '</del>';
        } else {
            // Two characters make strong, one makes ordinary emphasis.
            $use = ($opener['length'] >= 2 && $closer['length'] >= 2) ? 2 : 1;
            $openTag = $use === 2 ? '<strong>' : '<em>';
            $closeTag = $use === 2 ? '</strong>' : '</em>';
        }

        $opener['length'] -= $use;
        $closer['length'] -= $use;

        // Rewrite the literal delimiter text that was emitted earlier, leaving
        // any unused characters in place.
        $this->out[$opener['index']] = str_repeat($opener['char'], $opener['length']) . $openTag;
        $this->out[$closer['index']] = $closeTag . str_repeat($closer['char'], $closer['length']);

        if ($opener['length'] === 0) {
            $opener['active'] = false;
        }
        if ($closer['length'] === 0) {
            $closer['active'] = false;
        }
    }

    // -------------------------------------------------------------------------
    // Links, images and footnotes
    // -------------------------------------------------------------------------

    /**
     * Bracket openers waiting for a `]`.
     *
     * @var list<array{index:int,image:bool,delimiterFloor:int,active:bool}>
     */
    private array $brackets = [];

    private function parseLinkOpen(): bool
    {
        // A footnote reference is [^label] and never a link.
        if (preg_match('/\G\[\^([^\]\s]{1,64})\]/', $this->text, $m, 0, $this->position) === 1) {
            $this->emitFootnoteReference($m[1]);
            $this->position += strlen($m[0]);

            return true;
        }

        $this->out[] = '[';

        $this->brackets[] = [
            'index'          => count($this->out) - 1,
            'image'          => false,
            'delimiterFloor' => count($this->delimiters),
            'active'         => true,
        ];

        ++$this->position;

        return true;
    }

    private function parseImageOpen(): bool
    {
        if (($this->text[$this->position + 1] ?? '') !== '[') {
            return false;
        }

        $this->out[] = '![';

        $this->brackets[] = [
            'index'          => count($this->out) - 1,
            'image'          => true,
            'delimiterFloor' => count($this->delimiters),
            'active'         => true,
        ];

        $this->position += 2;

        return true;
    }

    private function parseLinkClose(): bool
    {
        $bracketIndex = null;

        for ($i = count($this->brackets) - 1; $i >= 0; --$i) {
            if ($this->brackets[$i]['active']) {
                $bracketIndex = $i;
                break;
            }
        }

        if ($bracketIndex === null) {
            $this->out[] = ']';
            ++$this->position;

            return true;
        }

        $bracket = $this->brackets[$bracketIndex];
        $after = $this->position + 1;

        $destination = null;
        $title = '';
        $consumed = 1;

        // Inline form: [text](url "title")
        if (($this->text[$after] ?? '') === '(') {
            $parsed = $this->parseInlineDestination($after);

            if ($parsed !== null) {
                [$destination, $title, $end] = $parsed;
                $consumed = $end - $this->position;
            }
        }

        // Reference forms: [text][label], [text][] and [text]
        if ($destination === null) {
            $labelText = $this->rawBetween($bracket['index'], count($this->out));

            if (preg_match('/\G\]\[([^\]]{0,999})\]/', $this->text, $m, 0, $this->position) === 1) {
                $label = trim($m[1]) === '' ? $labelText : $m[1];
                $consumed = strlen($m[0]);
            } else {
                $label = $labelText;
            }

            $key = self::normaliseReferenceLabel($label);

            if (isset($this->references[$key])) {
                $destination = $this->references[$key]['url'];
                $title = $this->references[$key]['title'];
            }
        }

        if ($destination === null) {
            // Nothing matched: the brackets are literal.
            $this->brackets[$bracketIndex]['active'] = false;
            $this->out[] = ']';
            ++$this->position;

            return true;
        }

        // Emphasis inside the label resolves before the link is wrapped, so
        // `[**bold** link](x)` works.
        $this->resolveEmphasis($bracket['delimiterFloor']);

        $inner = implode('', array_slice($this->out, $bracket['index'] + 1));
        array_splice($this->out, $bracket['index']);

        if ($bracket['image']) {
            $this->out[] = $this->renderImage($destination, $inner, $title);
        } else {
            $this->out[] = $this->renderLink($destination, $inner, $title);

            // A link may not contain another link, so every bracket still open
            // outside this one is deactivated.
            foreach ($this->brackets as $index => $other) {
                if ($index < $bracketIndex && !$other['image']) {
                    $this->brackets[$index]['active'] = false;
                }
            }
        }

        array_splice($this->brackets, $bracketIndex);

        // Delimiters inside the label have been consumed.
        array_splice($this->delimiters, $bracket['delimiterFloor']);

        $this->position += $consumed;

        return true;
    }

    /**
     * Parses `(url "title")` starting at the `(`.
     *
     * @return array{0:string,1:string,2:int}|null destination, title, end offset
     */
    private function parseInlineDestination(int $start): ?array
    {
        $i = $start + 1;

        // Optional whitespace.
        while ($i < $this->length && preg_match('/\s/', $this->text[$i]) === 1) {
            ++$i;
        }

        $destination = '';

        if (($this->text[$i] ?? '') === '<') {
            $end = strpos($this->text, '>', $i);

            if ($end === false) {
                return null;
            }

            $destination = substr($this->text, $i + 1, $end - $i - 1);
            $i = $end + 1;
        } else {
            $depth = 0;

            while ($i < $this->length) {
                $char = $this->text[$i];

                if ($char === '\\' && isset($this->text[$i + 1]) && str_contains(self::ESCAPABLE, $this->text[$i + 1])) {
                    $destination .= $this->text[$i + 1];
                    $i += 2;
                    continue;
                }

                if ($char === '(') {
                    ++$depth;
                } elseif ($char === ')') {
                    if ($depth === 0) {
                        break;
                    }
                    --$depth;
                } elseif (preg_match('/\s/', $char) === 1) {
                    break;
                }

                $destination .= $char;
                ++$i;
            }
        }

        while ($i < $this->length && preg_match('/\s/', $this->text[$i]) === 1) {
            ++$i;
        }

        $title = '';
        $quote = $this->text[$i] ?? '';

        if ($quote === '"' || $quote === "'") {
            $end = $i + 1;

            while ($end < $this->length) {
                if ($this->text[$end] === '\\') {
                    $end += 2;
                    continue;
                }
                if ($this->text[$end] === $quote) {
                    break;
                }
                ++$end;
            }

            if ($end < $this->length) {
                $title = substr($this->text, $i + 1, $end - $i - 1);
                $i = $end + 1;
            }
        }

        while ($i < $this->length && preg_match('/\s/', $this->text[$i]) === 1) {
            ++$i;
        }

        if (($this->text[$i] ?? '') !== ')') {
            return null;
        }

        return [$destination, $title, $i + 1];
    }

    private function renderLink(string $destination, string $inner, string $title): string
    {
        if (!Url::isSafe($destination)) {
            // Refuse javascript:, data: and the rest; the label survives as
            // plain text so nothing disappears from the page.
            return $inner;
        }

        $href = Url::normalise($destination);
        $attributes = ' href="' . self::escape($href) . '"';

        if ($title !== '') {
            $attributes .= ' title="' . self::escape($title) . '"';
        }

        // Outbound links open in a new tab and disclaim endorsement; internal
        // ones behave normally.
        if (Url::isExternal($href)) {
            $attributes .= ' rel="nofollow noopener" target="_blank"';
        }

        return '<a' . $attributes . '>' . $inner . '</a>';
    }

    private function renderImage(string $destination, string $altHtml, string $title): string
    {
        // The alt attribute is plain text; any markup that ended up in the
        // label is stripped rather than escaped into it.
        $alt = trim(html_entity_decode(strip_tags($altHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        // A reference to the media library resolves to a responsive figure
        // with a srcset; the editor inserts this form.
        if ($this->media !== null && str_starts_with($destination, 'mtl:media/')) {
            return $this->media->render(substr($destination, 10), $alt, $title);
        }

        if (!Url::isSafe($destination)) {
            return self::escape($alt);
        }

        $attributes = ' src="' . self::escape(Url::normalise($destination)) . '"'
            . ' alt="' . self::escape($alt) . '"'
            . ' loading="lazy" decoding="async"';

        if ($title !== '') {
            $attributes .= ' title="' . self::escape($title) . '"';
        }

        return '<img' . $attributes . '>';
    }

    private function emitFootnoteReference(string $label): void
    {
        $key = self::normaliseReferenceLabel($label);

        if (!isset($this->footnotes[$key])) {
            $this->footnotes[$key] = count($this->footnotes) + 1;
        }

        $number = $this->footnotes[$key];
        $id = self::escape($key);

        $this->out[] = '<sup class="mtl-footnote-ref"><a href="#fn-' . $id . '" id="fnref-' . $id . '">'
            . $number . '</a></sup>';
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * The plain text between two output positions, used to recover a link
     * label for the reference forms.
     */
    private function rawBetween(int $from, int $to): string
    {
        $pieces = array_slice($this->out, $from + 1, $to - $from - 1);

        return html_entity_decode(strip_tags(implode('', $pieces)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public static function normaliseReferenceLabel(string $label): string
    {
        $label = trim(preg_replace('/\s+/u', ' ', $label) ?? $label);

        return mb_strtolower($label, 'UTF-8');
    }

    private static function isPunctuation(string $char): bool
    {
        return preg_match('/[\p{P}\p{S}]/u', $char) === 1;
    }

    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8', false);
    }
}
