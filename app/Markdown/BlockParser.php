<?php

declare(strict_types=1);

namespace MTL\Markdown;

defined('MTL_APP') || exit;

/**
 * Block-level markdown: the structure of a document.
 *
 * Handles ATX and setext headings, thematic breaks, fenced and indented code,
 * block quotes, bullet and ordered lists (nested, tight and loose), GFM tables,
 * GFM task lists, footnote definitions, link reference definitions and
 * paragraphs.
 *
 * The parser works line by line and recurses for container blocks. Raw HTML
 * blocks are escaped and rendered as text — see Markdown for the reasoning.
 */
final class BlockParser
{
    /** @var array<string,array{url:string,title:string}> */
    private array $references = [];

    /** @var array<string,string> footnote label => raw markdown */
    private array $footnoteDefinitions = [];

    /** @var array<string,int> footnote label => ordinal, filled during inline parsing */
    private array $footnoteOrder = [];

    /** @var array<string,int> heading slug => times seen, for unique anchors */
    private array $headingSlugs = [];

    /** @var list<array{level:int,text:string,id:string}> */
    private array $headings = [];

    public function __construct(
        private readonly MarkdownOptions $options,
        private readonly ?MediaResolver $media = null,
    ) {
    }

    /**
     * The table of contents collected while rendering, in document order.
     *
     * @return list<array{level:int,text:string,id:string}>
     */
    public function headings(): array
    {
        return $this->headings;
    }

    public function render(string $markdown): string
    {
        $markdown = \MTL\Support\Str::normaliseText($markdown);

        // Tabs expand to the next four-column stop before anything else looks
        // at indentation.
        $markdown = self::expandTabs($markdown);

        $lines = explode("\n", $markdown);

        $lines = $this->extractDefinitions($lines);

        $html = $this->parseBlocks($lines);

        return $html . $this->renderFootnotes();
    }

    // -------------------------------------------------------------------------
    // Pre-pass: link reference and footnote definitions
    // -------------------------------------------------------------------------

    /**
     * Removes `[label]: url "title"` and `[^label]: text` from the line list.
     *
     * Doing this first means a reference may be defined after its use, which
     * is the whole point of the syntax.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function extractDefinitions(array $lines): array
    {
        $remaining = [];
        $count = count($lines);

        for ($i = 0; $i < $count; ++$i) {
            $line = $lines[$i];

            // Footnote definition. Its body continues onto indented lines.
            if (preg_match('/^ {0,3}\[\^([^\]\s]{1,64})\]:\s*(.*)$/', $line, $m) === 1) {
                $label = InlineParser::normaliseReferenceLabel($m[1]);
                $body = [$m[2]];

                while ($i + 1 < $count) {
                    $next = $lines[$i + 1];

                    if (trim($next) === '') {
                        // A blank line only continues the footnote if an
                        // indented line follows it.
                        if (isset($lines[$i + 2]) && preg_match('/^ {4,}\S/', $lines[$i + 2]) === 1) {
                            $body[] = '';
                            ++$i;
                            continue;
                        }
                        break;
                    }

                    if (preg_match('/^ {4,}/', $next) === 1) {
                        $body[] = substr($next, 4);
                        ++$i;
                        continue;
                    }

                    break;
                }

                $this->footnoteDefinitions[$label] = implode("\n", $body);
                continue;
            }

            // Link reference definition, which must not be inside a fence.
            if (preg_match('/^ {0,3}\[([^\]]{1,999})\]:\s*(\S+)(?:\s+(?:"([^"]*)"|\'([^\']*)\'|\(([^)]*)\)))?\s*$/', $line, $m) === 1) {
                $key = InlineParser::normaliseReferenceLabel($m[1]);

                // A label may not start with ^ (that is a footnote) and the
                // first definition of a label wins.
                if (!str_starts_with($m[1], '^') && !isset($this->references[$key])) {
                    $this->references[$key] = [
                        'url'   => trim($m[2], '<>'),
                        'title' => $m[3] ?? $m[4] ?? $m[5] ?? '',
                    ];
                }

                continue;
            }

            // Skip over fenced code so a definition-looking line inside it is
            // left alone.
            if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $m) === 1) {
                $fence = $m[1][0];
                $fenceLength = strlen($m[1]);
                $remaining[] = $line;

                while (++$i < $count) {
                    $remaining[] = $lines[$i];

                    if (preg_match('/^ {0,3}' . preg_quote($fence, '/') . '{' . $fenceLength . ',}\s*$/', $lines[$i]) === 1) {
                        break;
                    }
                }

                continue;
            }

            $remaining[] = $line;
        }

        return $remaining;
    }

    // -------------------------------------------------------------------------
    // Block loop
    // -------------------------------------------------------------------------

    /**
     * @param list<string> $lines
     */
    private function parseBlocks(array $lines): string
    {
        $html = [];
        $count = count($lines);
        $i = 0;

        while ($i < $count) {
            $line = $lines[$i];

            if (trim($line) === '') {
                ++$i;
                continue;
            }

            // --- Fenced code ---------------------------------------------------
            if (preg_match('/^( {0,3})(`{3,}|~{3,})[ \t]*([^`]*)$/', $line, $m) === 1) {
                [$block, $i] = $this->readFencedCode($lines, $i, $m);
                $html[] = $block;
                continue;
            }

            // --- ATX heading ---------------------------------------------------
            if (preg_match('/^ {0,3}(#{1,6})(?:[ \t]+(.*?))?(?:[ \t]+#+)?[ \t]*$/', $line, $m) === 1) {
                $html[] = $this->renderHeading(strlen($m[1]), $m[2] ?? '');
                ++$i;
                continue;
            }

            // --- Thematic break ------------------------------------------------
            if (preg_match('/^ {0,3}((\*[ \t]*){3,}|(-[ \t]*){3,}|(_[ \t]*){3,})$/', $line) === 1) {
                $html[] = "<hr>\n";
                ++$i;
                continue;
            }

            // --- Block quote ---------------------------------------------------
            if (preg_match('/^ {0,3}>/', $line) === 1) {
                [$block, $i] = $this->readBlockQuote($lines, $i);
                $html[] = $block;
                continue;
            }

            // --- List ----------------------------------------------------------
            if (self::listMarker($line) !== null) {
                [$block, $i] = $this->readList($lines, $i);
                $html[] = $block;
                continue;
            }

            // --- Table ---------------------------------------------------------
            if (isset($lines[$i + 1]) && self::isTableDelimiter($lines[$i + 1]) && str_contains($line, '|')) {
                [$block, $next] = $this->readTable($lines, $i);

                if ($block !== null) {
                    $html[] = $block;
                    $i = $next;
                    continue;
                }
            }

            // --- Indented code -------------------------------------------------
            if (preg_match('/^ {4,}\S/', $line) === 1) {
                [$block, $i] = $this->readIndentedCode($lines, $i);
                $html[] = $block;
                continue;
            }

            // --- Paragraph or setext heading -----------------------------------
            [$block, $i] = $this->readParagraph($lines, $i);
            $html[] = $block;
        }

        return implode('', $html);
    }

    // -------------------------------------------------------------------------
    // Individual blocks
    // -------------------------------------------------------------------------

    /**
     * @param list<string>             $lines
     * @param array{0:string,1:string,2:string,3:string} $match
     *
     * @return array{0:string,1:int}
     */
    private function readFencedCode(array $lines, int $start, array $match): array
    {
        $indent = strlen($match[1]);
        $fenceChar = $match[2][0];
        $fenceLength = strlen($match[2]);
        $info = trim($match[3]);

        $content = [];
        $i = $start + 1;
        $count = count($lines);

        while ($i < $count) {
            if (preg_match('/^ {0,3}' . preg_quote($fenceChar, '/') . '{' . $fenceLength . ',}[ \t]*$/', $lines[$i]) === 1) {
                ++$i;
                break;
            }

            // The opening fence's indentation is removed from each line, but
            // only as much of it as is actually there.
            $line = $lines[$i];
            $strip = 0;
            while ($strip < $indent && ($line[$strip] ?? '') === ' ') {
                ++$strip;
            }

            $content[] = substr($line, $strip);
            ++$i;
        }

        // The info string's first word is the language.
        $language = preg_split('/\s+/', $info)[0] ?? '';
        $language = preg_match('/^[A-Za-z0-9_+#.-]{1,24}$/', $language) === 1 ? strtolower($language) : '';

        $attributes = $language === '' ? '' : ' class="language-' . InlineParser::escape($language) . '"';

        return [
            "<pre><code{$attributes}>" . InlineParser::escape(implode("\n", $content)) . "</code></pre>\n",
            $i,
        ];
    }

    /**
     * @param list<string> $lines
     *
     * @return array{0:string,1:int}
     */
    private function readIndentedCode(array $lines, int $start): array
    {
        $content = [];
        $i = $start;
        $count = count($lines);

        while ($i < $count) {
            $line = $lines[$i];

            if (trim($line) === '') {
                // A blank line belongs to the block only if indented code
                // continues after it.
                $lookahead = $i + 1;
                while ($lookahead < $count && trim($lines[$lookahead]) === '') {
                    ++$lookahead;
                }

                if ($lookahead < $count && preg_match('/^ {4,}/', $lines[$lookahead]) === 1) {
                    $content[] = '';
                    ++$i;
                    continue;
                }

                break;
            }

            if (preg_match('/^ {4,}/', $line) !== 1) {
                break;
            }

            $content[] = substr($line, 4);
            ++$i;
        }

        return [
            '<pre><code>' . InlineParser::escape(implode("\n", $content)) . "</code></pre>\n",
            $i,
        ];
    }

    /**
     * @param list<string> $lines
     *
     * @return array{0:string,1:int}
     */
    private function readBlockQuote(array $lines, int $start): array
    {
        $inner = [];
        $i = $start;
        $count = count($lines);

        while ($i < $count) {
            $line = $lines[$i];

            if (preg_match('/^ {0,3}> ?(.*)$/', $line, $m) === 1) {
                $inner[] = $m[1];
                ++$i;
                continue;
            }

            // Lazy continuation: a plain paragraph line directly below a quoted
            // line stays inside the quote.
            if (trim($line) !== ''
                && $inner !== []
                && trim(end($inner)) !== ''
                && self::listMarker($line) === null
                && preg_match('/^ {0,3}(#{1,6}\s|>|`{3,}|~{3,}|(\*[ \t]*){3,}$|(-[ \t]*){3,}$)/', $line) !== 1
            ) {
                $inner[] = $line;
                ++$i;
                continue;
            }

            break;
        }

        return ["<blockquote>\n" . $this->parseBlocks($inner) . "</blockquote>\n", $i];
    }

    /**
     * Reads a whole list, including nested content.
     *
     * @param list<string> $lines
     *
     * @return array{0:string,1:int}
     */
    private function readList(array $lines, int $start): array
    {
        $first = self::listMarker($lines[$start]);

        if ($first === null) {
            return ['', $start + 1];
        }

        $ordered = $first['ordered'];
        $startNumber = $first['number'];

        /** @var list<list<string>> $items each item's lines, de-indented */
        $items = [];
        $loose = false;

        $i = $start;
        $count = count($lines);
        $pendingBlank = false;

        while ($i < $count) {
            $line = $lines[$i];
            $marker = self::listMarker($line);

            if ($marker === null) {
                if (trim($line) === '') {
                    $pendingBlank = true;
                    ++$i;
                    continue;
                }

                // Continuation lines were already absorbed into the previous
                // item below, so anything reaching here ends the list.
                break;
            }

            // A different marker type starts a new list rather than a new item.
            if ($marker['ordered'] !== $ordered) {
                break;
            }

            if ($pendingBlank && $items !== []) {
                $loose = true;
            }

            $contentIndent = $marker['indent'] + $marker['width'];
            $itemLines = [$marker['content']];
            ++$i;

            $sawBlankInItem = false;

            while ($i < $count) {
                $next = $lines[$i];

                if (trim($next) === '') {
                    $sawBlankInItem = true;
                    $itemLines[] = '';
                    ++$i;
                    continue;
                }

                $nextIndentation = strspn($next, ' ');

                if ($nextIndentation >= $contentIndent) {
                    if ($sawBlankInItem) {
                        // Content after a blank line inside an item makes the
                        // whole list loose, which is what turns the items into
                        // paragraphs.
                        $loose = true;
                        $sawBlankInItem = false;
                    }

                    $itemLines[] = substr($next, $contentIndent);
                    ++$i;
                    continue;
                }

                // A new marker at this level ends the item.
                if (self::listMarker($next) !== null) {
                    break;
                }

                // Lazy continuation of the item's paragraph.
                if (!$sawBlankInItem
                    && preg_match('/^ {0,3}(#{1,6}\s|>|`{3,}|~{3,})/', $next) !== 1
                ) {
                    $itemLines[] = ltrim($next);
                    ++$i;
                    continue;
                }

                break;
            }

            // Drop trailing blanks that belong to the separation, not the item.
            while ($itemLines !== [] && trim(end($itemLines)) === '') {
                array_pop($itemLines);
                $pendingBlank = true;
            }

            $items[] = $itemLines;
            $pendingBlank = $pendingBlank || $sawBlankInItem;
        }

        return [$this->renderList($items, $ordered, $startNumber, $loose), $i];
    }

    /**
     * @param list<list<string>> $items
     */
    private function renderList(array $items, bool $ordered, int $startNumber, bool $loose): string
    {
        $tag = $ordered ? 'ol' : 'ul';
        $attributes = '';

        // A checkbox may appear on any item, and a list that has one anywhere
        // is styled as a task list — GFM does not require every item to carry
        // one, and a mixed list is common in a packing list.
        $isTaskList = false;

        if (!$ordered) {
            foreach ($items as $itemLines) {
                if (preg_match('/^\[([ xX])\]\s+/', $itemLines[0] ?? '') === 1) {
                    $isTaskList = true;
                    $attributes = ' class="mtl-task-list"';
                    break;
                }
            }
        }

        if ($ordered && $startNumber !== 1) {
            $attributes .= ' start="' . $startNumber . '"';
        }

        $html = '<' . $tag . $attributes . ">\n";

        foreach ($items as $itemLines) {
            $itemAttributes = '';
            $checkbox = '';

            if ($isTaskList && preg_match('/^\[([ xX])\]\s+(.*)$/', $itemLines[0] ?? '', $m) === 1) {
                $checked = strtolower($m[1]) === 'x';
                $itemLines[0] = $m[2];
                $itemAttributes = ' class="mtl-task-list-item"';

                // Disabled: the checkbox reflects the source and is not a
                // control. The editor changes the markdown instead.
                $checkbox = '<input type="checkbox" disabled' . ($checked ? ' checked' : '') . '> ';
            }

            $content = $this->parseBlocks($itemLines);

            if (!$loose) {
                // A tight list renders its items without paragraph wrappers.
                $content = preg_replace('#^<p>(.*?)</p>\n#s', '$1', $content, 1) ?? $content;
                $content = trim($content);
            }

            $html .= '<li' . $itemAttributes . '>' . $checkbox . $content . "</li>\n";
        }

        return $html . '</' . $tag . ">\n";
    }

    /**
     * @param list<string> $lines
     *
     * @return array{0:string|null,1:int}
     */
    private function readTable(array $lines, int $start): array
    {
        $headerCells = self::splitTableRow($lines[$start]);
        $alignments = self::parseTableAlignments($lines[$start + 1]);

        // The delimiter row has to have the same number of columns as the
        // header, otherwise this is an ordinary paragraph that happens to
        // contain pipes.
        if ($alignments === null || count($headerCells) !== count($alignments)) {
            return [null, $start];
        }

        $columns = count($headerCells);

        $html = "<div class=\"mtl-table-scroll\">\n<table>\n<thead>\n<tr>\n";

        foreach ($headerCells as $index => $cell) {
            $style = $alignments[$index] === '' ? '' : ' style="text-align: ' . $alignments[$index] . '"';
            $html .= '<th' . $style . '>' . $this->inline($cell) . "</th>\n";
        }

        $html .= "</tr>\n</thead>\n";

        $i = $start + 2;
        $count = count($lines);
        $body = '';

        while ($i < $count) {
            $line = $lines[$i];

            if (trim($line) === '' || !str_contains($line, '|')) {
                break;
            }

            $cells = self::splitTableRow($line);

            $body .= "<tr>\n";

            for ($column = 0; $column < $columns; ++$column) {
                // Rows may be short or long; missing cells are empty and extra
                // ones are dropped, as GFM specifies.
                $cell = $cells[$column] ?? '';
                $style = $alignments[$column] === '' ? '' : ' style="text-align: ' . $alignments[$column] . '"';
                $body .= '<td' . $style . ' data-label="' . InlineParser::escape(strip_tags($this->inline($headerCells[$column]))) . '">'
                    . $this->inline($cell) . "</td>\n";
            }

            $body .= "</tr>\n";
            ++$i;
        }

        if ($body !== '') {
            $html .= "<tbody>\n" . $body . "</tbody>\n";
        }

        return [$html . "</table>\n</div>\n", $i];
    }

    /**
     * @param list<string> $lines
     *
     * @return array{0:string,1:int}
     */
    private function readParagraph(array $lines, int $start): array
    {
        $content = [];
        $i = $start;
        $count = count($lines);

        while ($i < $count) {
            $line = $lines[$i];

            if (trim($line) === '') {
                break;
            }

            // Setext heading: the underline applies to the paragraph so far.
            if ($content !== [] && preg_match('/^ {0,3}(=+|-+)[ \t]*$/', $line, $m) === 1) {
                // A run of dashes is a thematic break when the paragraph is
                // empty, but here there is text above it.
                $level = str_starts_with($m[1], '=') ? 1 : 2;

                return [$this->renderHeading($level, implode("\n", $content)), $i + 1];
            }

            // Another block starting interrupts the paragraph.
            if (preg_match('/^ {0,3}(#{1,6}[ \t]|>|`{3,}|~{3,})/', $line) === 1
                || preg_match('/^ {0,3}((\*[ \t]*){3,}|(-[ \t]*){3,}|(_[ \t]*){3,})$/', $line) === 1
            ) {
                break;
            }

            // A list marker interrupts a paragraph only when the list would
            // start with item 1, so "the year 2026. It was" stays prose.
            $marker = self::listMarker($line);
            if ($marker !== null && ($content === [] || !$marker['ordered'] || $marker['number'] === 1)) {
                if ($marker['content'] !== '') {
                    break;
                }
            }

            $content[] = ltrim($line);
            ++$i;
        }

        if ($content === []) {
            return ['', $start + 1];
        }

        return ['<p>' . $this->inline(implode("\n", $content)) . "</p>\n", $i];
    }

    // -------------------------------------------------------------------------
    // Rendering helpers
    // -------------------------------------------------------------------------

    private function renderHeading(int $level, string $text): string
    {
        $text = trim($text);
        $rendered = $this->inline($text);

        // Headings in a travel report start at h2: the page's h1 is the trip
        // or step title, which the template renders.
        $level = min(6, $level + $this->options->headingOffset);

        if (!$this->options->headingAnchors) {
            return "<h{$level}>{$rendered}</h{$level}>\n";
        }

        $id = $this->uniqueSlug(strip_tags($rendered));

        $this->headings[] = ['level' => $level, 'text' => trim(strip_tags($rendered)), 'id' => $id];

        return "<h{$level} id=\"{$id}\">{$rendered}"
            . '<a class="mtl-heading-anchor" href="#' . $id . '" aria-hidden="true" tabindex="-1">#</a>'
            . "</h{$level}>\n";
    }

    private function uniqueSlug(string $text): string
    {
        $base = \MTL\Support\Str::slug(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 60);

        if ($base === '') {
            $base = 'section';
        }

        $seen = $this->headingSlugs[$base] ?? 0;
        $this->headingSlugs[$base] = $seen + 1;

        return $seen === 0 ? $base : $base . '-' . $seen;
    }

    private function inline(string $text): string
    {
        $parser = new InlineParser($this->references, $this->footnoteOrder, $this->media);
        $html = $parser->parse($text);

        $this->footnoteOrder = $parser->usedFootnotes();

        return $html;
    }

    private function renderFootnotes(): string
    {
        if ($this->footnoteOrder === []) {
            return '';
        }

        $html = "<section class=\"mtl-footnotes\">\n<ol>\n";

        // Numbered by first use, not by definition order.
        asort($this->footnoteOrder);

        foreach ($this->footnoteOrder as $label => $number) {
            $definition = $this->footnoteDefinitions[$label] ?? '';
            $id = InlineParser::escape((string) $label);

            $body = $definition === ''
                ? '<p>' . InlineParser::escape('[' . $label . ']') . '</p>'
                : $this->parseBlocks(explode("\n", $definition));

            // The back-reference is appended inside the last paragraph so it
            // sits on the same line as the note's text.
            $backlink = ' <a href="#fnref-' . $id . '" class="mtl-footnote-back" aria-label="back to text">↩</a>';

            if (str_ends_with(rtrim($body), '</p>')) {
                $body = preg_replace('#</p>\s*$#', $backlink . "</p>\n", $body) ?? $body . $backlink;
            } else {
                $body .= '<p>' . $backlink . '</p>';
            }

            $html .= '<li id="fn-' . $id . '">' . $body . "</li>\n";
        }

        return $html . "</ol>\n</section>\n";
    }

    // -------------------------------------------------------------------------
    // Line classification
    // -------------------------------------------------------------------------

    /**
     * Recognises a list item marker.
     *
     * @return array{ordered:bool,number:int,indent:int,width:int,content:string}|null
     */
    private static function listMarker(string $line): ?array
    {
        // Up to three spaces of indentation; four would be code.
        if (preg_match('/^( {0,3})([-+*])([ \t]+)(.*)$/', $line, $m) === 1) {
            // A line of only dashes is a thematic break, not a list.
            if (preg_match('/^ {0,3}((\*[ \t]*){3,}|(-[ \t]*){3,})$/', $line) === 1) {
                return null;
            }

            return [
                'ordered' => false,
                'number'  => 1,
                'indent'  => strlen($m[1]),
                'width'   => 1 + strlen($m[3]),
                'content' => $m[4],
            ];
        }

        // An empty item: "-" on its own line.
        if (preg_match('/^( {0,3})([-+*])[ \t]*$/', $line, $m) === 1) {
            if (preg_match('/^ {0,3}((\*[ \t]*){3,}|(-[ \t]*){3,})$/', $line) === 1) {
                return null;
            }

            return ['ordered' => false, 'number' => 1, 'indent' => strlen($m[1]), 'width' => 2, 'content' => ''];
        }

        if (preg_match('/^( {0,3})(\d{1,9})([.)])([ \t]+)(.*)$/', $line, $m) === 1) {
            return [
                'ordered' => true,
                'number'  => (int) $m[2],
                'indent'  => strlen($m[1]),
                'width'   => strlen($m[2]) + 1 + strlen($m[4]),
                'content' => $m[5],
            ];
        }

        if (preg_match('/^( {0,3})(\d{1,9})([.)])[ \t]*$/', $line, $m) === 1) {
            return [
                'ordered' => true,
                'number'  => (int) $m[2],
                'indent'  => strlen($m[1]),
                'width'   => strlen($m[2]) + 2,
                'content' => '',
            ];
        }

        return null;
    }

    private static function isTableDelimiter(string $line): bool
    {
        return preg_match('/^ {0,3}\|?[ \t]*:?-{1,}:?[ \t]*(\|[ \t]*:?-{1,}:?[ \t]*)*\|?[ \t]*$/', $line) === 1
            && str_contains($line, '-');
    }

    /**
     * @return list<string>|null one of '', 'left', 'center', 'right' per column
     */
    private static function parseTableAlignments(string $line): ?array
    {
        if (!self::isTableDelimiter($line)) {
            return null;
        }

        $alignments = [];

        foreach (self::splitTableRow($line) as $cell) {
            $cell = trim($cell);

            $left = str_starts_with($cell, ':');
            $right = str_ends_with($cell, ':');

            $alignments[] = match (true) {
                $left && $right => 'center',
                $right          => 'right',
                $left           => 'left',
                default         => '',
            };
        }

        return $alignments;
    }

    /**
     * Splits a table row on unescaped pipes.
     *
     * @return list<string>
     */
    private static function splitTableRow(string $line): array
    {
        $line = trim($line);

        // The outer pipes are optional and are not column separators.
        $line = preg_replace('/^\|/', '', $line) ?? $line;
        $line = preg_replace('/\|$/', '', $line) ?? $line;

        $cells = [];
        $current = '';
        $length = strlen($line);

        for ($i = 0; $i < $length; ++$i) {
            $char = $line[$i];

            if ($char === '\\' && ($line[$i + 1] ?? '') === '|') {
                $current .= '|';
                ++$i;
                continue;
            }

            if ($char === '|') {
                $cells[] = trim($current);
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $cells[] = trim($current);

        return $cells;
    }

    /**
     * Expands tabs to four-column tab stops.
     *
     * Indentation is measured in columns, so a tab is not simply four spaces —
     * it advances to the next multiple of four.
     */
    private static function expandTabs(string $text): string
    {
        if (!str_contains($text, "\t")) {
            return $text;
        }

        $out = [];

        foreach (explode("\n", $text) as $line) {
            if (!str_contains($line, "\t")) {
                $out[] = $line;
                continue;
            }

            $expanded = '';
            $column = 0;
            $length = strlen($line);

            for ($i = 0; $i < $length; ++$i) {
                if ($line[$i] === "\t") {
                    $spaces = 4 - ($column % 4);
                    $expanded .= str_repeat(' ', $spaces);
                    $column += $spaces;
                    continue;
                }

                $expanded .= $line[$i];
                ++$column;
            }

            $out[] = $expanded;
        }

        return implode("\n", $out);
    }
}
