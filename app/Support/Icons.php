<?php

declare(strict_types=1);

namespace MTL\Support;

defined('MTL_APP') || exit;

/**
 * Inline SVG icons.
 *
 * Vanilla ships an icon set as CSS background images, which works for its own
 * patterns but cannot inherit `currentcolor` — a problem for the globe's
 * controls, which sit on a dark overlay, and for the editor toolbar, which
 * indicates active formatting by colour. These are drawn inline instead.
 *
 * Every path is on a 24×24 grid with a 2px stroke, so they sit together
 * evenly. Icons that Vanilla already provides are not duplicated here.
 */
final class Icons
{
    /**
     * Path data, keyed by icon name. Everything is stroked rather than filled
     * so a single set works at any size and in either theme.
     *
     * @var array<string,string>
     */
    private const PATHS = [
        // --- Editor formatting -------------------------------------------------
        'bold'        => '<path d="M7 5h6a3.5 3.5 0 0 1 0 7H7zM7 12h7a3.5 3.5 0 0 1 0 7H7z"/>',
        'italic'      => '<path d="M15 5h-5M14 19H9M13.5 5l-3 14"/>',
        'strike'      => '<path d="M5 12h14M8.5 8.5A3 3 0 0 1 11.5 6h1a3 3 0 0 1 3 2.6M15.5 15.5a3 3 0 0 1-3 2.5h-1a3 3 0 0 1-3-2.6"/>',
        'code'        => '<path d="m9 8-5 4 5 4M15 8l5 4-5 4"/>',
        'code-block'  => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="m9 10-2.5 2L9 14M15 10l2.5 2L15 14"/>',
        'link'        => '<path d="M10.5 13.5a4 4 0 0 0 5.7 0l2.6-2.6a4 4 0 1 0-5.7-5.7l-1.3 1.3"/><path d="M13.5 10.5a4 4 0 0 0-5.7 0l-2.6 2.6a4 4 0 1 0 5.7 5.7l1.3-1.3"/>',
        'quote'       => '<path d="M9 7H5v5h4v5H5M19 7h-4v5h4v5h-4"/>',
        'list-bullet' => '<path d="M9 6h11M9 12h11M9 18h11M4.5 6h.01M4.5 12h.01M4.5 18h.01"/>',
        'list-ordered' => '<path d="M10 6h10M10 12h10M10 18h10M4 5.5 5.5 5v4M4 13h2.5L4 16.5h2.5"/>',
        'list-task'   => '<path d="M11 6h9M11 12h9M11 18h9M3 6.5 4.2 8 7 5M3 17.5 4.2 19 7 16"/>',
        'heading'     => '<path d="M6 5v14M18 5v14M6 12h12"/>',
        'divider'     => '<path d="M4 12h16M7 7h10M7 17h10" opacity=".55"/>',
        'table'       => '<rect x="3" y="5" width="18" height="14" rx="1.5"/><path d="M3 10h18M3 15h18M9 5v14M15 5v14"/>',
        'image'       => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8.5" cy="10" r="1.5"/><path d="m4 17 4.5-4.5L13 17M14 15l2.5-2.5L21 17"/>',
        'markdown'    => '<rect x="2" y="6" width="20" height="12" rx="2"/><path d="M6 15V9l3 3 3-3v6M17 9v5M14.5 12 17 14.5 19.5 12"/>',
        'eye'         => '<path d="M2 12s3.6-6 10-6 10 6 10 6-3.6 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="2.6"/>',
        'undo'        => '<path d="M4 9h9a5 5 0 0 1 0 10H8M4 9l4-4M4 9l4 4"/>',
        'redo'        => '<path d="M20 9h-9a5 5 0 0 0 0 10h5M20 9l-4-4M20 9l-4 4"/>',

        // --- Globe -------------------------------------------------------------
        'globe'       => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.6 3.8 5.6 3.8 9s-1.3 6.4-3.8 9c-2.5-2.6-3.8-5.6-3.8-9S9.5 5.6 12 3Z"/>',
        'zoom-in'     => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4M11 8v6M8 11h6"/>',
        'zoom-out'    => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4M8 11h6"/>',
        'rotate'      => '<path d="M20 12a8 8 0 1 1-2.6-5.9"/><path d="M20 4v4h-4"/>',
        'reset'       => '<path d="M4 12a8 8 0 1 0 2.6-5.9"/><path d="M4 4v4h4"/>',
        'vr'          => '<path d="M3 9.5A2.5 2.5 0 0 1 5.5 7h13A2.5 2.5 0 0 1 21 9.5v4a2.5 2.5 0 0 1-2.5 2.5h-2.7a2 2 0 0 1-1.6-.8l-1-1.3a1.5 1.5 0 0 0-2.4 0l-1 1.3a2 2 0 0 1-1.6.8H5.5A2.5 2.5 0 0 1 3 13.5Z"/>',
        'timeline'    => '<path d="M3 12h18M7 9v6M12 7v10M17 9v6"/>',
        'layers'      => '<path d="m12 3 9 5-9 5-9-5Z"/><path d="m3 13 9 5 9-5M3 16.5l9 5 9-5" opacity=".5"/>',
        'sun'         => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M19.1 4.9l-1.4 1.4M6.3 17.7l-1.4 1.4"/>',
        'pin'         => '<path d="M12 21s7-6.3 7-11a7 7 0 1 0-14 0c0 4.7 7 11 7 11Z"/><circle cx="12" cy="10" r="2.6"/>',

        // --- Interface ---------------------------------------------------------
        'menu'        => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'close'       => '<path d="M6 6l12 12M18 6 6 18"/>',
        'check'       => '<path d="m4 12.5 5 5L20 6.5"/>',
        'plus'        => '<path d="M12 5v14M5 12h14"/>',
        'trash'       => '<path d="M4 7h16M9 7V5.5A1.5 1.5 0 0 1 10.5 4h3A1.5 1.5 0 0 1 15 5.5V7M6.5 7l.8 12A1.6 1.6 0 0 0 8.9 20.5h6.2a1.6 1.6 0 0 0 1.6-1.5l.8-12"/>',
        'pencil'      => '<path d="M4 20h4l10.5-10.5a2.1 2.1 0 0 0-3-3L5 17v3Z"/><path d="m14.5 6.5 3 3"/>',
        'search'      => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
        'upload'      => '<path d="M12 16V4M8 8l4-4 4 4M4 16v2.5A1.5 1.5 0 0 0 5.5 20h13a1.5 1.5 0 0 0 1.5-1.5V16"/>',
        'download'    => '<path d="M12 4v12M8 12l4 4 4-4M4 16v2.5A1.5 1.5 0 0 0 5.5 20h13a1.5 1.5 0 0 0 1.5-1.5V16"/>',
        'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
        'chevron-right' => '<path d="m9 6 6 6-6 6"/>',
        'chevron-left' => '<path d="m15 6-6 6 6 6"/>',
        'chevron-up'  => '<path d="m6 15 6-6 6 6"/>',
        'drag'        => '<path d="M9 6h.01M9 12h.01M9 18h.01M15 6h.01M15 12h.01M15 18h.01"/>',
        'settings'    => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-2.7 1.1v.3a2 2 0 1 1-4 0v-.2a1.6 1.6 0 0 0-2.8-1.1l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.6 1.6 0 0 0 3.5 15a2 2 0 1 1 0-4h.2a1.6 1.6 0 0 0 1.1-2.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1A1.6 1.6 0 0 0 11 4.3V4a2 2 0 1 1 4 0v.2a1.6 1.6 0 0 0 2.7 1.1l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0 1.1 2.7h.3a2 2 0 1 1 0 4h-.2a1.6 1.6 0 0 0-1.3 1.2Z"/>',
        'user'        => '<circle cx="12" cy="8" r="4"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/>',
        'users'       => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M16 5.2a3.5 3.5 0 0 1 0 5.6M18 14.2a6.5 6.5 0 0 1 3.5 5.8"/>',
        'photo-stack' => '<rect x="7" y="3" width="14" height="14" rx="2"/><path d="M3 7v12a2 2 0 0 0 2 2h12"/><circle cx="12" cy="8" r="1.5"/><path d="m8 15 3.5-3.5L15 15"/>',
        'route'       => '<circle cx="6" cy="18" r="2.5"/><circle cx="18" cy="6" r="2.5"/><path d="M8.5 18h5a4 4 0 0 0 0-8h-3a4 4 0 0 1 0-8h5" transform="translate(0 2)"/>',
        'calendar'    => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
        'clock'       => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.2 2"/>',
        'info'        => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
        'warning'     => '<path d="M10.3 4.3 2.6 17.4A2 2 0 0 0 4.3 20.4h15.4a2 2 0 0 0 1.7-3L13.7 4.3a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 16.5h.01"/>',
        'external'    => '<path d="M14 4h6v6M20 4l-8.5 8.5"/><path d="M18 14v4.5A1.5 1.5 0 0 1 16.5 20h-11A1.5 1.5 0 0 1 4 18.5v-11A1.5 1.5 0 0 1 5.5 6H10"/>',
        'copy'        => '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4.5A1.5 1.5 0 0 1 3 13.5v-9A1.5 1.5 0 0 1 4.5 3h9A1.5 1.5 0 0 1 15 4.5V5"/>',
        'sign-out'    => '<path d="M15 4h3.5A1.5 1.5 0 0 1 20 5.5v13a1.5 1.5 0 0 1-1.5 1.5H15"/><path d="M11 8l-4 4 4 4M7 12h9"/>',
        'shield'      => '<path d="M12 3 4.5 6v5.5c0 4.5 3.1 8.4 7.5 9.5 4.4-1.1 7.5-5 7.5-9.5V6Z"/><path d="m8.8 12 2.2 2.2 4.2-4.4"/>',
        'tag'         => '<path d="M3 11V4.5A1.5 1.5 0 0 1 4.5 3H11l9 9-7.5 7.5Z"/><circle cx="7.5" cy="7.5" r="1.3"/>',
        'sparkle'     => '<path d="m12 3 1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8Z"/><path d="M18.5 15.5 19 17l1.5.5-1.5.5-.5 1.5-.5-1.5L16.5 17l1.5-.5Z"/>',
    ];

    /**
     * Renders an icon as inline SVG.
     *
     * @param array<string,string> $attributes extra attributes for the <svg>
     */
    public static function svg(string $name, int $size = 20, array $attributes = []): string
    {
        $path = self::PATHS[$name] ?? null;

        if ($path === null) {
            return '';
        }

        $defaults = [
            'width'            => (string) $size,
            'height'           => (string) $size,
            'viewBox'          => '0 0 24 24',
            'fill'             => 'none',
            'stroke'           => 'currentColor',
            'stroke-width'     => '1.8',
            'stroke-linecap'   => 'round',
            'stroke-linejoin'  => 'round',
            // Icons here always sit next to a text label or inside a control
            // that carries its own accessible name.
            'aria-hidden'      => 'true',
            'focusable'        => 'false',
        ];

        // The 'bold' glyph is a filled shape; stroking it looks wrong.
        if ($name === 'bold' || $name === 'sparkle') {
            $defaults['fill'] = 'currentColor';
            $defaults['stroke'] = 'none';
        }

        $merged = array_merge($defaults, $attributes);

        $rendered = '';
        foreach ($merged as $key => $value) {
            $rendered .= ' ' . $key . '="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"';
        }

        return '<svg' . $rendered . '>' . $path . '</svg>';
    }

    public static function exists(string $name): bool
    {
        return isset(self::PATHS[$name]);
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::PATHS);
    }
}
