<?php

declare(strict_types=1);

namespace MTL\Markdown;

use MTL\Models\Media;

defined('MTL_APP') || exit;

/**
 * Turns `![alt](mtl:media/{uuid} "caption")` into a responsive figure.
 *
 * The editor inserts this form rather than a plain URL so that the rendered
 * markup can gain a srcset, intrinsic dimensions and a placeholder colour
 * without every existing report having to be rewritten. It also means a photo
 * stays linked to its library record: replacing the file updates every report
 * that shows it.
 */
final class MediaResolver
{
    /** @var array<string,Media|false> uuid => record, false when missing */
    private array $cache = [];

    /** @var list<int> ids of the media actually referenced, in order */
    private array $used = [];

    /** @return list<int> */
    public function usedMediaIds(): array
    {
        return array_values(array_unique($this->used));
    }

    public function render(string $reference, string $alt, string $title): string
    {
        // The reference may carry a variant hint: "uuid" or "uuid@large".
        [$uuid, $variant] = array_pad(explode('@', $reference, 2), 2, 'large');

        $media = $this->lookup(trim($uuid));

        if ($media === null) {
            // A deleted or mistyped reference must not leave a broken image
            // icon in the middle of a report.
            return '<span class="mtl-missing-media" title="' . InlineParser::escape($reference) . '">'
                . InlineParser::escape($alt !== '' ? $alt : '[afbeelding niet gevonden]')
                . '</span>';
        }

        $this->used[] = $media->id();

        if ($media->isVideo()) {
            return $this->renderVideo($media, $alt, $title);
        }

        return $this->renderImage($media, $alt, $title, $variant);
    }

    private function renderImage(Media $media, string $alt, string $title, string $variant): string
    {
        $variant = in_array($variant, Media::VARIANTS, true) ? $variant : 'large';

        [$width, $height] = $media->dimensions($variant);

        $altText = $alt !== '' ? $alt : $media->alt();

        $attributes = ' src="' . InlineParser::escape($media->url($variant)) . '"'
            . ' alt="' . InlineParser::escape($altText) . '"'
            . ' loading="lazy" decoding="async"';

        if ($width > 0 && $height > 0) {
            // Intrinsic dimensions reserve the right box before the image
            // arrives, so the report text does not jump as photos load.
            $attributes .= ' width="' . $width . '" height="' . $height . '"';
        }

        $srcset = $media->srcset();

        if ($srcset !== '') {
            $attributes .= ' srcset="' . InlineParser::escape($srcset) . '"'
                . ' sizes="(min-width: 46rem) 44rem, 100vw"';
        }

        $style = $media->placeholderStyle();

        if ($style !== '') {
            $attributes .= ' style="' . InlineParser::escape($style) . '"';
        }

        $caption = $title !== '' ? $title : $media->string('title');

        $image = '<img' . $attributes . '>';

        // A figure only when there is something to caption; otherwise the
        // extra element just adds noise to the document outline.
        if ($caption === '') {
            return $image;
        }

        return '<figure>' . $image
            . '<figcaption>' . InlineParser::escape($caption) . '</figcaption>'
            . '</figure>';
    }

    private function renderVideo(Media $media, string $alt, string $title): string
    {
        $poster = '';
        $posterId = $media->int('poster_media_id');

        if ($posterId > 0) {
            $posterMedia = Media::find($posterId);

            if ($posterMedia !== null && !$posterMedia->isDeleted()) {
                $poster = ' poster="' . InlineParser::escape($posterMedia->url('medium')) . '"';
            }
        }

        $attributes = ' controls preload="metadata" playsinline'
            . $poster
            . ' src="' . InlineParser::escape($media->url('original')) . '"';

        [$width, $height] = [$media->int('width'), $media->int('height')];

        if ($width > 0 && $height > 0) {
            $attributes .= ' width="' . $width . '" height="' . $height . '"';
        }

        $video = '<video' . $attributes . '></video>';

        $caption = $title !== '' ? $title : ($alt !== '' ? $alt : $media->string('title'));

        if ($caption === '') {
            return $video;
        }

        return '<figure>' . $video . '<figcaption>' . InlineParser::escape($caption) . '</figcaption></figure>';
    }

    private function lookup(string $uuid): ?Media
    {
        if (array_key_exists($uuid, $this->cache)) {
            return $this->cache[$uuid] === false ? null : $this->cache[$uuid];
        }

        // Accept a numeric id too: it is what the media library shows, and
        // pasting one by hand is a reasonable thing to do.
        $media = ctype_digit($uuid) ? Media::find((int) $uuid) : Media::findByUuid($uuid);

        if ($media === null || $media->isDeleted()) {
            $this->cache[$uuid] = false;

            return null;
        }

        $this->cache[$uuid] = $media;

        return $media;
    }
}
