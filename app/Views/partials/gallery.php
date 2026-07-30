<?php
/**
 * A photo and video gallery.
 *
 * @var MTL\Core\View $this
 * @var list<MTL\Models\Media> $media
 * @var string $layout  grid | masonry | story
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

use MTL\Models\Media;

/** @var list<Media> $media */
$media = $this->get('media', []);
$layout = (string) $this->get('layout', 'grid');

if ($media === []) {
    return;
}

$layout = in_array($layout, ['grid', 'masonry', 'story'], true) ? $layout : 'grid';
?>
<div class="mtl-gallery mtl-gallery--<?= e($layout) ?>" data-gallery>
    <?php foreach ($media as $item): ?>
        <?php
        [$width, $height] = $item->dimensions('medium');
        $caption = $item->string('pivot_caption') !== ''
            ? $item->string('pivot_caption')
            : $item->string('title');
        ?>

        <figure>
            <?php if ($item->isVideo()): ?>
                <?php $poster = $item->int('poster_media_id') > 0 ? Media::find($item->int('poster_media_id')) : null; ?>

                <video controls preload="metadata" playsinline
                       <?= $poster !== null ? 'poster="' . e($poster->url('medium')) . '"' : '' ?>
                       <?= $width > 0 ? 'width="' . e((string) $width) . '" height="' . e((string) $height) . '"' : '' ?>>
                    <source src="<?= e($item->url('original')) ?>" type="<?= e($item->string('mime_type')) ?>">
                </video>
            <?php else: ?>
                <img src="<?= e($item->url($layout === 'story' ? 'large' : 'medium')) ?>"
                     srcset="<?= e($item->srcset()) ?>"
                     sizes="<?= $layout === 'story' ? '(min-width: 60rem) 60rem, 100vw' : '(min-width: 60rem) 20rem, 50vw' ?>"
                     alt="<?= e($item->alt()) ?>"
                     <?= $width > 0 ? 'width="' . e((string) $width) . '" height="' . e((string) $height) . '"' : '' ?>
                     loading="lazy"
                     decoding="async"
                     style="<?= e($item->placeholderStyle()) ?>"
                     data-full="<?= e($item->url('large')) ?>">
            <?php endif; ?>

            <?php if ($caption !== ''): ?>
                <figcaption><?= e($caption) ?></figcaption>
            <?php endif; ?>
        </figure>
    <?php endforeach; ?>
</div>
