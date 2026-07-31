<?php
/**
 * A trip tile.
 *
 * @var MTL\Core\View $this
 * @var MTL\Models\Trip $trip
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

/** @var MTL\Models\Trip $trip */
$trip = $this->get('trip');
$cover = $trip->coverMedia();
?>
<article class="mtl-card" style="--mtl-card-accent: <?= e($trip->string('color', '#2ec27e')) ?>">
    <div class="mtl-card__accent"></div>

    <?php if ($cover !== null): ?>
        <img class="mtl-card__media"
             src="<?= e($cover->url('small')) ?>"
             srcset="<?= e($cover->srcset()) ?>"
             sizes="(min-width: 60rem) 20rem, 100vw"
             alt=""
             loading="lazy"
             decoding="async"
             style="<?= e($cover->placeholderStyle()) ?>">
    <?php endif; ?>

    <div class="mtl-card__body">
        <h3>
            <?php // The link stretches over the whole card; see _content.scss. ?>
            <a href="<?= e($trip->url()) ?>"><?= e($trip->string('title')) ?></a>
        </h3>

        <?php $excerpt = $trip->excerpt(140); ?>
        <?php if ($excerpt !== ''): ?>
            <p class="mtl-muted"><?= e($excerpt) ?></p>
        <?php endif; ?>

        <p class="mtl-card__meta">
            <?php if ($trip->dateRange() !== ''): ?>
                <span><?= icon('calendar', 14) ?> <?= e($trip->dateRange()) ?></span>
            <?php endif; ?>

            <?php if ($trip->int('step_count') > 0): ?>
                <span><?= icon('pin', 14) ?> <?= e(__('js.globe.steps', ['count' => $trip->int('step_count')])) ?></span>
            <?php endif; ?>

            <?php if ((float) $trip->float('distance_km', 0.0) > 0): ?>
                <span><?= icon('route', 14) ?> <?= e(number_format((float) $trip->float('distance_km'), 0, ',', '.')) ?> km</span>
            <?php endif; ?>

            <?php if (!$trip->isPublished()): ?>
                <span class="mtl-status mtl-status--draft"><?= e(__('trip.status')) ?>: <?= e($trip->string('status')) ?></span>
            <?php endif; ?>
        </p>
    </div>
</article>
