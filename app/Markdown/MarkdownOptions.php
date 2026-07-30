<?php

declare(strict_types=1);

namespace MTL\Markdown;

defined('MTL_APP') || exit;

/**
 * How a particular piece of markdown should be rendered.
 *
 * Different places want different things: a travel report wants headings and
 * footnotes, a photo caption wants emphasis and links and nothing else.
 */
final class MarkdownOptions
{
    public function __construct(
        /**
         * Added to every heading level, so `#` in a report becomes an <h2>
         * below the page's own <h1>.
         */
        public readonly int $headingOffset = 1,

        /** Give headings an id and a permalink anchor. */
        public readonly bool $headingAnchors = true,

        /** Collect a table of contents while rendering. */
        public readonly bool $collectHeadings = true,
    ) {
    }

    /** Full document: a trip introduction or a step's travel report. */
    public static function document(): self
    {
        return new self(headingOffset: 1, headingAnchors: true, collectHeadings: true);
    }

    /**
     * A short piece of text — a caption, a summary, an album description.
     * Headings are still parsed but get no anchors, since there is no page to
     * link into.
     */
    public static function snippet(): self
    {
        return new self(headingOffset: 2, headingAnchors: false, collectHeadings: false);
    }

    /**
     * What the editor's rich surface is filled with.
     *
     * It differs from a document in two ways, and both exist because the editor
     * converts the surface back to markdown when it saves.
     *
     * No heading anchors: a permalink inside a heading is indistinguishable from
     * a link the author typed, so it was written back out as `[#](#een-kop)` —
     * one more on every save.
     *
     * No heading offset either. A document shifts `#` down to an <h2> so it sits
     * below the page's own <h1>, but HTML stops at <h6>, so `#####` and `######`
     * both land on <h6> and the shift cannot be undone: a level-six heading came
     * back as level five. Rendering the surface unshifted makes the mapping
     * one-to-one, which is what a round trip needs.
     */
    public static function editing(): self
    {
        return new self(headingOffset: 0, headingAnchors: false, collectHeadings: false);
    }
}
