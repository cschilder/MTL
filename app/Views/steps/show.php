<?php
/**
 * A single travel report.
 *
 * @var MTL\Core\View $this
 * @var MTL\Models\Step $step
 * @var MTL\Models\Trip $trip
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

$this->layout('layouts/app');

/** @var MTL\Models\Step $step */
$step = $this->get('step');
/** @var MTL\Models\Trip $trip */
$trip = $this->get('trip');
/** @var list<MTL\Models\Media> $media */
$media = $this->get('media', []);
/** @var MTL\Models\Step|null $previous */
$previous = $this->get('previous');
/** @var MTL\Models\Step|null $next */
$next = $this->get('next');
/** @var list<MTL\Models\Tag> $tags */
$tags = $this->get('tags', []);
?>
<?php $this->start('content') ?>

<nav class="p-breadcrumbs" aria-label="Breadcrumb">
    <ol class="p-breadcrumbs__items">
        <li class="p-breadcrumbs__item"><a href="<?= e(path('/trips')) ?>"><?= e(__('trip.trips')) ?></a></li>
        <li class="p-breadcrumbs__item"><a href="<?= e($trip->url()) ?>"><?= e($trip->string('title')) ?></a></li>
        <li class="p-breadcrumbs__item"><span aria-current="page"><?= e($step->string('title')) ?></span></li>
    </ol>
</nav>

<header class="mtl-step-header">
    <h1><?= e($step->string('title')) ?></h1>

    <p class="mtl-step-meta">
        <?php if ($step->string('location_name') !== ''): ?>
            <span><?= icon('pin', 16) ?> <?= e($step->string('location_name')) ?></span>
        <?php endif; ?>

        <?php if ($step->occurredLabel() !== ''): ?>
            <span><?= icon('calendar', 16) ?> <?= e($step->occurredLabel()) ?></span>
        <?php endif; ?>

        <?php if ($step->attribute('altitude_m') !== null): ?>
            <span><?= icon('layers', 16) ?> <?= e(number_format((float) $step->float('altitude_m'), 0, ',', '.')) ?> m</span>
        <?php endif; ?>

        <?php if ($step->attribute('temperature_c') !== null): ?>
            <span><?= icon('sun', 16) ?> <?= e(number_format((float) $step->float('temperature_c'), 1, ',', '.')) ?> °C</span>
        <?php endif; ?>

        <?php if ($step->attribute('rating') !== null): ?>
            <span aria-label="<?= e(__('step.rating')) ?>">
                <?= e(str_repeat('★', $step->int('rating'))) ?><?= e(str_repeat('☆', 5 - $step->int('rating'))) ?>
            </span>
        <?php endif; ?>

        <?php if ($this->get('canEdit', false)): ?>
            <span class="mtl-spacer"></span>
            <a class="p-button--base" href="<?= e($step->editUrl()) ?>">
                <?= icon('pencil', 16) ?> <?= e(__('app.edit')) ?>
            </a>
        <?php endif; ?>
    </p>
</header>

<?php $html = (string) $this->get('html', ''); ?>
<?php if ($html !== ''): ?>
    <article class="mtl-prose">
        <?= $html /* rendered by the markdown renderer, which emits only its own tags */ ?>
    </article>
<?php endif; ?>

<?php if ($media !== []): ?>
    <section style="margin-block-start: var(--mtl-space-6);">
        <h2><?= e(__('step.photos')) ?></h2>
        <?= $this->include('partials/gallery', ['media' => $media, 'layout' => 'masonry']) ?>
    </section>
<?php endif; ?>

<?php if ($tags !== []): ?>
    <p style="margin-block-start: var(--mtl-space-5);">
        <?php foreach ($tags as $tag): ?>
            <a class="p-chip" href="<?= e($tag->url()) ?>">
                <span class="p-chip__value"><?= e($tag->string('name')) ?></span>
            </a>
        <?php endforeach; ?>
    </p>
<?php endif; ?>

<nav class="mtl-step-nav" aria-label="<?= e(__('step.steps')) ?>">
    <?php if ($previous !== null): ?>
        <a href="<?= e($previous->url($trip)) ?>" rel="prev">
            <?= icon('chevron-left', 16) ?>
            <span class="mtl-muted"><?= e(__('step.previous')) ?></span><br>
            <?= e($previous->string('title')) ?>
        </a>
    <?php else: ?>
        <span></span>
    <?php endif; ?>

    <?php if ($next !== null): ?>
        <a href="<?= e($next->url($trip)) ?>" rel="next" style="text-align: end;">
            <span class="mtl-muted"><?= e(__('step.next')) ?></span>
            <?= icon('chevron-right', 16) ?><br>
            <?= e($next->string('title')) ?>
        </a>
    <?php endif; ?>
</nav>

<?php $this->end() ?>
