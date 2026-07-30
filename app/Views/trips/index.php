<?php
/**
 * The trip overview.
 *
 * @var MTL\Core\View $this
 * @var list<MTL\Models\Trip> $trips
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

$this->layout('layouts/app');

/** @var list<MTL\Models\Trip> $trips */
$trips = $this->get('trips', []);
/** @var array{trips:int,steps:int,countries:int,distance_km:float,photos:int} $statistics */
$statistics = $this->get('statistics', []);
?>
<?php $this->start('content') ?>

<div class="mtl-page-head">
    <div>
        <h1><?= e(__('trip.trips')) ?></h1>

        <?php if (($statistics['trips'] ?? 0) > 0): ?>
            <p class="mtl-muted">
                <?= e((string) $statistics['trips']) ?> <?= e(mb_strtolower(__('trip.trips'))) ?> ·
                <?= e((string) $statistics['steps']) ?> <?= e(mb_strtolower(__('step.steps'))) ?> ·
                <?= e((string) $statistics['countries']) ?> <?= e(mb_strtolower(__('trip.countries'))) ?> ·
                <?= e(number_format((float) ($statistics['distance_km'] ?? 0), 0, ',', '.')) ?> km
            </p>
        <?php endif; ?>
    </div>

    <div class="mtl-page-head__actions">
        <a class="p-button" href="<?= e(path('/')) ?>">
            <?= icon('globe', 16) ?> <?= e(__('nav.home')) ?>
        </a>

        <?php if (can('trip.create')): ?>
            <a class="p-button--positive" href="<?= e(path('/admin/trips/new')) ?>">
                <?= icon('plus', 16) ?> <?= e(__('trip.new')) ?>
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($trips === []): ?>
    <p class="mtl-empty"><?= e(__('trip.none')) ?></p>
<?php else: ?>
    <div class="mtl-card-grid">
        <?php foreach ($trips as $trip): ?>
            <?= $this->include('partials/trip-card', ['trip' => $trip]) ?>
        <?php endforeach; ?>
    </div>

    <?= $this->include('partials/pagination') ?>
<?php endif; ?>

<?php $this->end() ?>
