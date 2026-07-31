<?php
/**
 * A trip: its introduction, its stops in order, and its albums.
 *
 * @var MTL\Core\View $this
 * @var MTL\Models\Trip $trip
 * @var list<MTL\Models\Step> $steps
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

$this->layout('layouts/app');

/** @var MTL\Models\Trip $trip */
$trip = $this->get('trip');
/** @var list<MTL\Models\Step> $steps */
$steps = $this->get('steps', []);
/** @var list<MTL\Models\Album> $albums */
$albums = $this->get('albums', []);
/** @var MTL\Models\Media|null $cover */
$cover = $this->get('cover');
?>
<?php $this->start('content') ?>

<?php if ($cover !== null): ?>
    <div class="mtl-hero">
        <img src="<?= e($cover->url('large')) ?>"
             srcset="<?= e($cover->srcset()) ?>"
             sizes="100vw"
             alt=""
             style="<?= e($cover->placeholderStyle()) ?>">

        <div class="mtl-hero__body">
            <h1><?= e($trip->string('title')) ?></h1>
            <?php if ($trip->dateRange() !== ''): ?>
                <p><?= e($trip->dateRange()) ?></p>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <div class="mtl-page-head">
        <div>
            <h1><?= e($trip->string('title')) ?></h1>
            <?php if ($trip->dateRange() !== ''): ?>
                <p class="mtl-muted"><?= e($trip->dateRange()) ?></p>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<div class="mtl-row" style="margin-block: var(--mtl-space-5);">
    <?php if ($trip->int('step_count') > 0): ?>
        <span><?= icon('pin', 16) ?> <?= e(__('js.globe.steps', ['count' => $trip->int('step_count')])) ?></span>
    <?php endif; ?>

    <?php if ((float) $trip->float('distance_km', 0.0) > 0): ?>
        <span><?= icon('route', 16) ?> <?= e(number_format((float) $trip->float('distance_km'), 0, ',', '.')) ?> km</span>
    <?php endif; ?>

    <?php if ($trip->countryCodes() !== []): ?>
        <span><?= icon('globe', 16) ?> <?= e(implode(', ', $trip->countryCodes())) ?></span>
    <?php endif; ?>

    <?php if ($trip->int('media_count') > 0): ?>
        <span><?= icon('image', 16) ?> <?= e((string) $trip->int('media_count')) ?></span>
    <?php endif; ?>

    <span class="mtl-spacer"></span>

    <?php if ($this->get('canEdit', false)): ?>
        <a class="p-button--base" href="<?= e($trip->editUrl()) ?>">
            <?= icon('pencil', 16) ?> <?= e(__('app.edit')) ?>
        </a>
    <?php endif; ?>
</div>

<?php $body = $trip->string('body_html'); ?>
<?php if ($body !== ''): ?>
    <div class="mtl-prose" style="max-inline-size: 46rem;">
        <?= $body /* rendered and sanitised by the markdown renderer on save */ ?>
    </div>
<?php endif; ?>

<?php // ---- Stops ------------------------------------------------------------ ?>
<h2 style="margin-block-start: var(--mtl-space-7);"><?= e(__('trip.steps')) ?></h2>

<?php if ($steps === []): ?>
    <p class="mtl-empty"><?= e(__('trip.no_steps')) ?></p>
<?php else: ?>
    <ol class="mtl-step-list">
        <?php foreach ($steps as $step): ?>
            <li class="mtl-step-list__item">
                <span class="mtl-step-list__marker" aria-hidden="true">
                    <span class="mtl-step-list__dot" style="background: <?= e($trip->string('color', '#2ec27e')) ?>"></span>
                </span>

                <div>
                    <h3 style="margin: 0;">
                        <a href="<?= e($step->url($trip)) ?>"><?= e($step->string('title')) ?></a>
                        <?php if (!$step->isPublished()): ?>
                            <span class="mtl-status mtl-status--draft"><?= e($step->string('status')) ?></span>
                        <?php endif; ?>
                    </h3>

                    <p class="mtl-muted" style="margin: var(--mtl-space-1) 0;">
                        <?php if ($step->string('location_name') !== ''): ?>
                            <?= icon('pin', 14) ?> <?= e($step->string('location_name')) ?>
                        <?php endif; ?>

                        <?php if ($step->occurredLabel() !== ''): ?>
                            · <?= e($step->occurredLabel()) ?>
                        <?php endif; ?>

                        <?php if ($step->int('media_count') > 0): ?>
                            · <?= icon('image', 14) ?> <?= e((string) $step->int('media_count')) ?>
                        <?php endif; ?>
                    </p>

                    <?php $excerpt = $step->excerpt(220); ?>
                    <?php if ($excerpt !== ''): ?>
                        <p><?= e($excerpt) ?></p>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ol>
<?php endif; ?>

<?php // ---- Albums ----------------------------------------------------------- ?>
<?php if ($albums !== []): ?>
    <h2 style="margin-block-start: var(--mtl-space-7);"><?= e(__('album.albums')) ?></h2>

    <div class="mtl-card-grid">
        <?php foreach ($albums as $album): ?>
            <?php $albumCover = $album->coverMedia(); ?>
            <article class="mtl-card">
                <?php if ($albumCover !== null): ?>
                    <img class="mtl-card__media" src="<?= e($albumCover->url('small')) ?>" alt="" loading="lazy"
                         style="<?= e($albumCover->placeholderStyle()) ?>">
                <?php endif; ?>
                <div class="mtl-card__body">
                    <h3><a href="<?= e($album->url()) ?>"><?= e($album->string('title')) ?></a></h3>
                    <p class="mtl-card__meta"><?= e((string) $album->int('media_count')) ?> <?= e(mb_strtolower(__('media.media'))) ?></p>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php // ---- Share link ------------------------------------------------------- ?>
<?php if ($this->get('canEdit', false) && $trip->shareUrl() !== null): ?>
    <section style="margin-block-start: var(--mtl-space-7);">
        <h2><?= e(__('trip.share_link')) ?></h2>
        <p class="mtl-muted"><?= e(__('trip.share_hint')) ?></p>

        <div class="mtl-row">
            <input type="text" readonly value="<?= e($trip->shareUrl()) ?>" style="margin: 0; flex: 1; min-inline-size: 16rem;">
            <button type="button" class="p-button" data-copy="<?= e($trip->shareUrl()) ?>">
                <?= icon('copy', 16) ?> <?= e(__('app.more')) ?>
            </button>
        </div>
    </section>
<?php endif; ?>

<?php $this->end() ?>
