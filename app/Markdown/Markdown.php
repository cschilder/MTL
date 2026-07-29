<?php

declare(strict_types=1);

namespace MTL\Markdown;

defined('MTL_APP') || exit;

/**
 * The markdown facade.
 *
 * MTL follows CommonMark 0.31 with the GitHub Flavored Markdown extensions
 * that a travel report actually uses: tables, strikethrough, task lists,
 * bare-URL autolinks and footnotes.
 *
 * **Raw HTML in the source is escaped, not passed through.** Markdown's own
 * specification allows inline HTML, but accepting it here would mean running
 * every report through a sanitiser and trusting that sanitiser against every
 * future browser quirk. Escaping instead gives a single, checkable rule: the
 * only tags in the output are the ones this renderer emitted. The editor
 * covers every construct the syntax offers, so nothing is lost by it.
 *
 * Rendered HTML is stored alongside the markdown in the database, so a page
 * view never runs the parser. Rendering happens on save and whenever a report
 * is previewed.
 */
final class Markdown
{
    /**
     * Renders markdown to HTML.
     */
    public static function render(string $markdown, ?MarkdownOptions $options = null): string
    {
        return self::renderWithContext($markdown, $options)['html'];
    }

    /**
     * Renders and also reports what the document contained: its headings, the
     * media it referenced, and a plain-text excerpt.
     *
     * The caller uses this on save to keep the search index, the media usage
     * counters and the stored excerpt in step with the text.
     *
     * @return array{html:string,headings:list<array{level:int,text:string,id:string}>,media:list<int>,text:string}
     */
    public static function renderWithContext(string $markdown, ?MarkdownOptions $options = null): array
    {
        $options ??= MarkdownOptions::document();

        if (trim($markdown) === '') {
            return ['html' => '', 'headings' => [], 'media' => [], 'text' => ''];
        }

        // A runaway document should fail loudly rather than exhaust the memory
        // limit halfway through a save.
        if (strlen($markdown) > 2_000_000) {
            throw new \InvalidArgumentException('This document is too large to render (over 2 MB of markdown).');
        }

        $media = new MediaResolver();
        $parser = new BlockParser($options, $media);

        $html = $parser->render($markdown);

        return [
            'html'     => $html,
            'headings' => $options->collectHeadings ? $parser->headings() : [],
            'media'    => $media->usedMediaIds(),
            'text'     => \MTL\Support\Str::stripMarkdown($markdown),
        ];
    }

    /**
     * Renders a short piece of text: a caption, a summary, an album blurb.
     */
    public static function renderSnippet(string $markdown): string
    {
        return self::render($markdown, MarkdownOptions::snippet());
    }

    /**
     * Renders and strips to a single line, for a meta description or a listing.
     */
    public static function toText(string $markdown, int $length = 200): string
    {
        return \MTL\Support\Str::excerpt(\MTL\Support\Str::stripMarkdown($markdown), $length);
    }

    /**
     * Builds a nested table of contents from a flat heading list.
     *
     * @param list<array{level:int,text:string,id:string}> $headings
     *
     * @return string HTML, or '' when there is nothing worth showing
     */
    public static function tableOfContents(array $headings, int $minimum = 3): string
    {
        if (count($headings) < $minimum) {
            return '';
        }

        $topLevel = min(array_column($headings, 'level'));
        $html = '';
        $depth = 0;

        foreach ($headings as $heading) {
            // Only two levels deep: beyond that a contents list is harder to
            // read than the document it summarises.
            $relative = min(1, $heading['level'] - $topLevel);

            while ($depth < $relative) {
                $html .= "<ul>\n";
                ++$depth;
            }

            while ($depth > $relative) {
                $html .= "</ul>\n";
                --$depth;
            }

            $html .= '<li><a href="#' . InlineParser::escape($heading['id']) . '">'
                . InlineParser::escape($heading['text']) . "</a></li>\n";
        }

        while ($depth > 0) {
            $html .= "</ul>\n";
            --$depth;
        }

        return '<nav class="mtl-toc" aria-label="Inhoud"><ul>' . $html . '</ul></nav>';
    }
}
