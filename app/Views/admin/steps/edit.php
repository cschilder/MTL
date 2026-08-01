<?php
/**
 * Creating or editing a stop and its travel report.
 *
 * @var MTL\Core\View $this
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

$this->layout('layouts/admin');

/** @var MTL\Models\Step|null $step */
$step = $this->get('step');
/** @var MTL\Models\Trip|null $trip */
$trip = $this->get('trip');
/** @var list<MTL\Models\Media> $media */
$media = $this->get('media', []);
/** @var list<MTL\Models\Tag> $tags */
$tags = $this->get('tags', []);
/** @var array{latitude:?float,longitude:?float,occurred_at:?string} $suggestion */
$suggestion = $this->get('suggestion', ['latitude' => null, 'longitude' => null, 'occurred_at' => null]);

$isNew = $step === null;

$value = static fn (string $field, mixed $default = '') => old($field, $step?->string($field) ?? $default);

/** A datetime-local field wants "Y-m-dTH:i". */
$localDate = static function (string $stored): string {
    if ($stored === '') {
        return '';
    }

    return str_replace(' ', 'T', substr($stored, 0, 16));
};
?>
<?php $this->start('content') ?>

<div class="mtl-page-head">
    <div>
        <h1><?= e($isNew ? __('step.new') : $step->string('title')) ?></h1>
        <?php if ($trip !== null): ?>
            <p class="mtl-muted">
                <a href="<?= e($trip->editUrl()) ?>"><?= e($trip->string('title')) ?></a>
            </p>
        <?php endif; ?>
    </div>

    <div class="mtl-page-head__actions">
        <?php if ($trip !== null): ?>
            <a class="p-button" href="<?= e($trip->editUrl()) ?>"><?= e(__('app.back')) ?></a>
        <?php endif; ?>

        <?php if (!$isNew && can('step.delete', $step)): ?>
            <form method="post" action="<?= e(path('/admin/steps/' . $step->id())) ?>"
                  data-confirm="<?= e(__('js.common.confirm_delete')) ?>">
                <?= csrf_field() ?>
                <?= method_field('DELETE') ?>
                <button type="submit" class="p-button--negative"><?= e(__('app.delete')) ?></button>
            </form>
        <?php endif; ?>
    </div>
</div>

<form method="post" action="<?= e((string) $this->get('action')) ?>">
    <?= csrf_field() ?>
    <?php if (!$isNew): ?>
        <?= method_field('PUT') ?>
    <?php endif; ?>

    <div class="mtl-editor-layout">
        <div class="mtl-stack">
            <div>
                <label for="title"><?= e(__('step.title')) ?></label>
                <input type="text" id="title" name="title" required maxlength="180"
                       value="<?= e((string) $value('title')) ?>" autofocus>
                <?php foreach (errors('title') as $message): ?>
                    <p class="p-form-validation__message"><?= e($message) ?></p>
                <?php endforeach; ?>
            </div>

            <div>
                <p class="p-form__label"><?= e(__('step.report')) ?></p>
                <?= $this->include('partials/editor', [
                    'name'        => 'body_md',
                    'value'       => (string) $value('body_md'),
                    'label'       => __('step.report'),
                    'autosaveKey' => $isNew ? 'step:new' : 'step:' . $step->id(),
                ]) ?>
            </div>

            <?php // ---- Gallery ------------------------------------------------ ?>
            <?php if (!$isNew): ?>
                <section>
                    <h2><?= e(__('step.photos')) ?></h2>

                    <div class="mtl-dropzone"
                         data-uploader
                         data-uploader-target="step"
                         data-uploader-target-id="<?= e((string) $step->id()) ?>"
                         data-uploader-list="[data-upload-list]">
                        <?= icon('upload', 28) ?>
                        <span><?= e(__('media.drop_here')) ?></span>
                        <input type="file" multiple accept="image/*,video/*"
                               aria-label="<?= e(__('media.upload')) ?>">
                    </div>

                    <div class="mtl-uploads" data-upload-list></div>

                    <?php if ($media !== []): ?>
                        <ol class="mtl-sortable" style="margin-block-start: var(--mtl-space-4);"
                            data-sortable
                            data-sortable-endpoint="<?= e(path('/admin/steps/' . $step->id() . '/media/order')) ?>"
                            data-sortable-field="order">
                            <?php foreach ($media as $item): ?>
                                <li class="mtl-sortable__item"
                                    data-sortable-item="<?= e((string) $item->id()) ?>"
                                    data-sortable-label="<?= e($item->displayTitle()) ?>">
                                    <button type="button" class="mtl-sortable__handle" data-sortable-handle
                                            aria-label="<?= e(__('app.edit')) ?>">
                                        <?= icon('drag', 18) ?>
                                    </button>

                                    <img src="<?= e($item->url('thumb')) ?>" alt=""
                                         style="inline-size: 3rem; aspect-ratio: 1; object-fit: cover; border-radius: var(--mtl-radius);">

                                    <span style="flex: 1; min-inline-size: 0;" class="mtl-truncate">
                                        <a href="<?= e(path('/admin/media/' . $item->id())) ?>">
                                            <?= e($item->displayTitle()) ?>
                                        </a>
                                    </span>

                                    <?php // Detaching removes it from this step, not from the library. ?>
                                    <button type="submit" class="p-button--base"
                                            form="detach-<?= e((string) $item->id()) ?>"
                                            aria-label="<?= e(__('app.delete')) ?>">
                                        <?= icon('close', 16) ?>
                                    </button>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </div>

        <aside class="mtl-editor-layout__side mtl-stack">
            <fieldset class="mtl-form__section">
                <legend><?= e(__('step.location')) ?></legend>

                <label for="location_name"><?= e(__('step.location')) ?></label>
                <div class="mtl-form__row" style="align-items: end;">
                    <input type="text" id="location_name" name="location_name" maxlength="180"
                           value="<?= e((string) $value('location_name')) ?>" style="flex: 1;">
                    <button type="button" class="p-button"
                            data-geocode
                            data-geocode-endpoint="<?= e(path('/admin/api/geocode')) ?>"
                            data-geocode-query="#location_name"
                            data-geocode-latitude="#latitude"
                            data-geocode-longitude="#longitude"
                            data-geocode-country="#country_code"
                            style="margin: 0;">
                        <?= e(__('step.geocode_button')) ?>
                    </button>
                </div>
                <ul class="mtl-geocode" data-geocode-results hidden></ul>
                <p class="p-form-help-text"><?= e(__('step.geocode_hint')) ?></p>

                <div class="mtl-form__row">
                    <div>
                        <label for="latitude"><?= e(__('step.latitude')) ?></label>
                        <input type="number" id="latitude" name="latitude" step="0.0000001" min="-90" max="90"
                               value="<?= e((string) $value('latitude')) ?>">
                    </div>
                    <div>
                        <label for="longitude"><?= e(__('step.longitude')) ?></label>
                        <input type="number" id="longitude" name="longitude" step="0.0000001" min="-180" max="180"
                               value="<?= e((string) $value('longitude')) ?>">
                    </div>
                </div>

                <?php if ($suggestion['latitude'] !== null): ?>
                    <?php
                    // A photo attached to this stop carries a geotag; offering
                    // it saves looking a coordinate up by hand.
                    ?>
                    <button type="button" class="p-button"
                            onclick="document.getElementById('latitude').value='<?= e((string) $suggestion['latitude']) ?>';document.getElementById('longitude').value='<?= e((string) $suggestion['longitude']) ?>';">
                        <?= icon('pin', 16) ?> <?= e(__('step.use_photo_location')) ?>
                    </button>
                <?php endif; ?>

                <div class="mtl-form__row">
                    <div>
                        <label for="country_code"><?= e(__('step.country')) ?></label>
                        <input type="text" id="country_code" name="country_code" maxlength="2"
                               placeholder="IS" style="text-transform: uppercase;"
                               value="<?= e((string) $value('country_code')) ?>">
                    </div>
                    <div>
                        <label for="altitude_m"><?= e(__('step.altitude')) ?></label>
                        <input type="number" id="altitude_m" name="altitude_m" step="1"
                               value="<?= e((string) $value('altitude_m')) ?>">
                    </div>
                </div>
            </fieldset>

            <fieldset class="mtl-form__section">
                <legend><?= e(__('step.occurred_at')) ?></legend>

                <label for="occurred_at"><?= e(__('step.occurred_at')) ?></label>
                <input type="datetime-local" id="occurred_at" name="occurred_at"
                       value="<?= e($localDate((string) $value('occurred_at'))) ?>">

                <label for="timezone"><?= e(__('app.language')) ?></label>
                <input type="text" id="timezone" name="timezone" maxlength="64"
                       placeholder="Atlantic/Reykjavik"
                       value="<?= e((string) $value('timezone')) ?>">
                <p class="p-form-help-text"><?= e(__('app.optional')) ?></p>
            </fieldset>

            <fieldset class="mtl-form__section">
                <legend><?= e(__('trip.status')) ?></legend>

                <label for="status"><?= e(__('trip.status')) ?></label>
                <select id="status" name="status">
                    <?php foreach (['draft', 'published'] as $status): ?>
                        <option value="<?= e($status) ?>" <?= $value('status', 'draft') === $status ? 'selected' : '' ?>>
                            <?= e($status) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="visibility"><?= e(__('trip.visibility')) ?></label>
                <select id="visibility" name="visibility">
                    <?php foreach (['inherit', 'public', 'private'] as $visibility): ?>
                        <option value="<?= e($visibility) ?>" <?= $value('visibility', 'inherit') === $visibility ? 'selected' : '' ?>>
                            <?= e($visibility) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </fieldset>

            <fieldset class="mtl-form__section">
                <legend><?= e(__('step.weather')) ?></legend>

                <div class="mtl-form__row">
                    <div>
                        <label for="temperature_c"><?= e(__('step.temperature')) ?></label>
                        <input type="number" id="temperature_c" name="temperature_c" step="0.1"
                               value="<?= e((string) $value('temperature_c')) ?>">
                    </div>
                    <div>
                        <label for="rating"><?= e(__('step.rating')) ?></label>
                        <select id="rating" name="rating">
                            <option value=""><?= e(__('app.none')) ?></option>
                            <?php for ($i = 1; $i <= 5; ++$i): ?>
                                <option value="<?= e((string) $i) ?>" <?= (string) $value('rating') === (string) $i ? 'selected' : '' ?>>
                                    <?= e(str_repeat('★', $i)) ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>

                <label for="weather"><?= e(__('step.weather')) ?></label>
                <input type="text" id="weather" name="weather" maxlength="200"
                       value="<?= e((string) $value('weather')) ?>">

                <label for="tags"><?= e(__('nav.tags')) ?></label>
                <input type="text" id="tags" name="tags"
                       value="<?= e(implode(', ', array_map(static fn ($t) => $t->string('name'), $tags))) ?>">
            </fieldset>
        </aside>
    </div>

    <div class="mtl-form-bar">
        <button type="submit" class="p-button--positive" style="margin: 0;"><?= e(__('app.save')) ?></button>

        <?php if (!$isNew && $trip !== null): ?>
            <a class="p-button" href="<?= e($step->url($trip)) ?>" style="margin: 0;"><?= e(__('app.more')) ?></a>
        <?php endif; ?>
    </div>
</form>

<?php // Detach forms live outside the main form: nesting forms is invalid HTML. ?>
<?php if (!$isNew): ?>
    <?php foreach ($media as $item): ?>
        <form id="detach-<?= e((string) $item->id()) ?>" method="post" hidden
              action="<?= e(path('/admin/steps/' . $step->id() . '/media/' . $item->id())) ?>">
            <?= csrf_field() ?>
            <?= method_field('DELETE') ?>
        </form>
    <?php endforeach; ?>
<?php endif; ?>

<?php $this->end() ?>
