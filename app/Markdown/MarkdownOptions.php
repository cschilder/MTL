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
}
