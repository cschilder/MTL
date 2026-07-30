<?php
/**
 * The globe.
 *
 * The markup below is the complete page: the canvas is an enhancement layered
 * over a list of trips that is rendered server-side. Without WebGL, without
 * JavaScript, or to a crawler, this page is a readable index of every journey.
 *
 * @var MTL\Core\View $this
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

use MTL\Models\Trip;

$this->layout('layouts/app');

/** @var list<Trip> $trips */
$trips = $this->get('trips', []);
/** @var array<string,mixed> $globeConfig */
$globeConfig = $this->get('globeConfig', []);
?>
<?php $this->start('content') ?>

<div class="mtl-globe"
     data-globe
     data-globe-source="<?= e(path('/api/globe')) ?>"
     data-globe-config="<?= e((string) json_encode($globeConfig, JSON_UNESCAPED_SLASHES)) ?>">

    <canvas class="mtl-globe__canvas"
            data-globe-canvas
            aria-label="<?= e(__('nav.home')) ?>"></canvas>

    <p class="mtl-globe__loading" data-globe-loading>
        <span class="p-icon--spinner u-animation--spin"></span>
        <?= e(__('js.globe.loading')) ?>
    </p>

    <?php // ---- Controls ---------------------------------------------------- ?>
    <div class="mtl-globe__controls" role="toolbar" aria-label="<?= e(__('nav.home')) ?>">
        <button type="button" class="mtl-globe__button" data-globe-action="zoom-in"
                aria-label="<?= e(__('js.globe.zoom_in')) ?>" title="<?= e(__('js.globe.zoom_in')) ?>">
            <?= icon('zoom-in') ?>
        </button>

        <button type="button" class="mtl-globe__button" data-globe-action="zoom-out"
                aria-label="<?= e(__('js.globe.zoom_out')) ?>" title="<?= e(__('js.globe.zoom_out')) ?>">
            <?= icon('zoom-out') ?>
        </button>

        <button type="button" class="mtl-globe__button" data-globe-action="reset"
                aria-label="<?= e(__('js.globe.reset')) ?>" title="<?= e(__('js.globe.reset')) ?>">
            <?= icon('reset') ?>
        </button>

        <span class="mtl-globe__separator" aria-hidden="true"></span>

        <button type="button" class="mtl-globe__button" data-globe-action="rotate"
                aria-pressed="false"
                aria-label="<?= e(__('js.globe.rotate')) ?>" title="<?= e(__('js.globe.rotate')) ?>">
            <?= icon('rotate') ?>
        </button>

        <?php // The fourth dimension: hidden until there are dated stops to scrub. ?>
        <button type="button" class="mtl-globe__button" data-globe-action="timeline"
                aria-pressed="false" hidden
                aria-label="<?= e(__('js.globe.timeline')) ?>" title="<?= e(__('js.globe.timeline')) ?>">
            <?= icon('timeline') ?>
        </button>

        <?php // The fifth: which measure drives marker height and colour. ?>
        <button type="button" class="mtl-globe__button" data-globe-action="layers"
                aria-pressed="false"
                aria-label="<?= e(__('js.globe.layer')) ?>" title="<?= e(__('js.globe.layer')) ?>">
            <?= icon('layers') ?>
        </button>

        <span class="mtl-globe__separator" aria-hidden="true"></span>

        <button type="button" class="mtl-globe__button" data-globe-action="vr" hidden
                aria-pressed="false"
                aria-label="<?= e(__('js.globe.enter_vr')) ?>" title="<?= e(__('js.globe.enter_vr')) ?>">
            <?= icon('vr') ?>
            <span><?= e(__('js.globe.enter_vr')) ?></span>
        </button>
    </div>

    <?php // ---- Timeline ---------------------------------------------------- ?>
    <div class="mtl-globe__timeline" data-globe-timeline hidden>
        <div class="mtl-globe__timeline-head">
            <button type="button" class="mtl-globe__button" data-globe-action="play"
                    aria-pressed="false" aria-label="<?= e(__('js.globe.play')) ?>">
                <?= icon('play', 18) ?>
            </button>

            <span class="mtl-globe__timeline-date" data-globe-date></span>
        </div>

        <label class="mtl-visually-hidden" for="mtl-globe-time"><?= e(__('js.globe.timeline')) ?></label>
        <input type="range" id="mtl-globe-time" class="mtl-globe__timeline-range"
               min="0" max="1000" value="1000" step="1" data-globe-slider>

        <div class="mtl-globe__timeline-ticks" data-globe-ticks aria-hidden="true"></div>
    </div>

    <?php // ---- Layer picker ------------------------------------------------ ?>
    <fieldset class="mtl-globe__layers" data-globe-layers hidden>
        <legend class="mtl-visually-hidden"><?= e(__('js.globe.layer')) ?></legend>

        <?php
        $layers = [
            'none'        => __('js.globe.layer_none'),
            'photos'      => __('js.globe.layer_photos'),
            'rating'      => __('js.globe.layer_rating'),
            'altitude'    => __('js.globe.layer_altitude'),
            'temperature' => __('js.globe.layer_temperature'),
        ];
        ?>

        <?php foreach ($layers as $value => $label): ?>
            <label>
                <input type="radio" name="mtl-globe-layer" value="<?= e($value) ?>"
                       data-globe-layer <?= $value === 'none' ? 'checked' : '' ?>>
                <span><?= e($label) ?></span>
            </label>
        <?php endforeach; ?>

        <div class="mtl-globe__legend-swatch" data-globe-legend hidden></div>
    </fieldset>

    <?php // ---- Marker preview ---------------------------------------------- ?>
    <article class="mtl-globe__tooltip" data-globe-tooltip hidden>
        <img data-tooltip-image alt="" hidden>
        <div class="mtl-globe__tooltip-body">
            <h3 data-tooltip-title></h3>
            <p class="mtl-muted" data-tooltip-meta></p>
            <p><a class="p-button--positive is-small" data-tooltip-link href="#"><?= e(__('app.more')) ?></a></p>
        </div>
    </article>

    <?php // ---- Fallback ----------------------------------------------------
    // Always in the document, hidden by CSS once the globe reports it works.
    // This is what a crawler indexes and what a browser without WebGL shows.
    ?>
    <div class="mtl-globe__fallback">
        <div class="mtl-shell--wide">
            <h1><?= e(setting('site.title', 'MTL')) ?></h1>
            <p class="p-heading--4"><?= e(setting('site.tagline')) ?></p>

            <p class="mtl-muted"><?= e(__('js.globe.unsupported')) ?></p>

            <?php if ($trips === []): ?>
                <p class="mtl-empty"><?= e(__('trip.none')) ?></p>
            <?php else: ?>
                <ul class="mtl-card-grid" style="list-style: none; padding: 0;">
                    <?php foreach ($trips as $trip): ?>
                        <li><?= $this->include('partials/trip-card', ['trip' => $trip]) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php $this->end() ?>
