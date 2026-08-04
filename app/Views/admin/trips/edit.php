<?php
/**
 * Creating or editing a trip.
 *
 * @var MTL\Core\View $this
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

$this->layout('layouts/admin');

/** @var MTL\Models\Trip|null $trip */
$trip = $this->get('trip');
/** @var list<MTL\Models\Step> $steps */
$steps = $this->get('steps', []);
/** @var list<MTL\Models\Tag> $tags */
$tags = $this->get('tags', []);
/** @var MTL\Models\Media|null $cover */
$cover = $this->get('cover');

$isNew = $trip === null;

$value = static fn (string $field, mixed $default = '') => old($field, $trip?->string($field) ?? $default);
?>
<?php $this->start('content') ?>

<div class="mtl-page-head">
    <div>
        <h1><?= e($isNew ? __('trip.new') : $trip->string('title')) ?></h1>
        <?php if (!$isNew): ?>
            <p class="mtl-muted">
                <a href="<?= e($trip->url()) ?>"><?= e($trip->url()) ?></a>
            </p>
        <?php endif; ?>
    </div>

    <div class="mtl-page-head__actions">
        <a class="p-button" href="<?= e(path('/admin/trips')) ?>"><?= e(__('app.back')) ?></a>

        <?php if (!$isNew && can('trip.delete', $trip)): ?>
            <form method="post" action="<?= e(path('/admin/trips/' . $trip->id())) ?>"
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
                <label for="title"><?= e(__('trip.title')) ?></label>
                <input type="text" id="title" name="title" required maxlength="180"
                       value="<?= e((string) $value('title')) ?>" autofocus>
                <?php foreach (errors('title') as $message): ?>
                    <p class="p-form-validation__message"><?= e($message) ?></p>
                <?php endforeach; ?>
            </div>

            <div>
                <label for="summary"><?= e(__('trip.summary')) ?></label>
                <textarea id="summary" name="summary" rows="2" maxlength="500"><?= e((string) $value('summary')) ?></textarea>
                <p class="p-form-help-text"><?= e(__('app.optional')) ?></p>
            </div>

            <div>
                <p class="p-form__label"><?= e(__('trip.story')) ?></p>
                <?= $this->include('partials/editor', [
                    'name'        => 'body_md',
                    'value'       => (string) $value('body_md'),
                    'label'       => __('trip.story'),
                    'autosaveKey' => $isNew ? 'trip:new' : 'trip:' . $trip->id(),
                ]) ?>
            </div>
        </div>

        <aside class="mtl-editor-layout__side mtl-stack">
            <fieldset class="mtl-form__section">
                <legend><?= e(__('trip.status')) ?></legend>

                <label for="status"><?= e(__('trip.status')) ?></label>
                <select id="status" name="status">
                    <?php foreach (['draft', 'published', 'archived'] as $status): ?>
                        <option value="<?= e($status) ?>" <?= $value('status', 'draft') === $status ? 'selected' : '' ?>>
                            <?= e($status) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="visibility"><?= e(__('trip.visibility')) ?></label>
                <select id="visibility" name="visibility">
                    <?php foreach (['private', 'unlisted', 'public'] as $visibility): ?>
                        <option value="<?= e($visibility) ?>" <?= $value('visibility', 'private') === $visibility ? 'selected' : '' ?>>
                            <?= e($visibility) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="p-form-help-text"><?= e(__('trip.share_hint')) ?></p>
            </fieldset>

            <?php if (!$isNew): ?>
                <?php
                // What the globe will actually do with this trip, spelled out.
                // The globe silently skips a trip whose stops have no
                // coordinates, and "I added a trip but the globe is empty" is
                // the support question that taught us to say so here.
                $placed = 0;
                $draftSteps = 0;
                foreach ($steps as $listStep) {
                    if ($listStep->latitude() !== null && $listStep->longitude() !== null) {
                        $placed++;
                    }
                    if ($listStep->string('status') !== 'published') {
                        $draftSteps++;
                    }
                }
                ?>
                <fieldset class="mtl-form__section">
                    <legend><?= e(__('trip.globe')) ?></legend>

                    <?php if ($steps !== [] && $placed === 0): ?>
                        <div class="p-notification--caution">
                            <div class="p-notification__content">
                                <p class="p-notification__message"><?= e(__('trip.globe_none')) ?></p>
                            </div>
                        </div>
                    <?php elseif ($steps !== []): ?>
                        <p><?= e(__('trip.globe_placed', ['placed' => $placed, 'total' => count($steps)])) ?></p>
                    <?php endif; ?>

                    <?php if ($placed < count($steps)): ?>
                        <?php // Submits its own form (declared after the main
                              // one — forms cannot nest) via the form attribute. ?>
                        <button type="submit" form="mtl-geocode-all" class="p-button" style="margin: 0;">
                            <?= e(__('trip.geocode_all')) ?>
                        </button>
                        <p class="p-form-help-text"><?= e(__('trip.geocode_all_hint')) ?></p>
                    <?php endif; ?>

                    <?php if ($trip->string('status') !== 'published'): ?>
                        <p class="mtl-muted"><?= e(__('trip.globe_draft')) ?></p>
                    <?php elseif ($trip->string('visibility') === 'private'): ?>
                        <p class="mtl-muted"><?= e(__('trip.globe_private')) ?></p>
                    <?php endif; ?>

                    <?php // The quiet second half of "my published trip is not
                          // on the globe": every *stop* has its own status,
                          // and the form defaults it to draft. ?>
                    <?php if ($draftSteps > 0 && $trip->string('status') === 'published'): ?>
                        <div class="p-notification--caution">
                            <div class="p-notification__content">
                                <p class="p-notification__message"><?= e(__('trip.globe_draft_steps', ['count' => $draftSteps])) ?></p>
                            </div>
                        </div>
                        <button type="submit" form="mtl-publish-steps" class="p-button" style="margin: 0;">
                            <?= e(__('trip.publish_steps')) ?>
                        </button>
                    <?php endif; ?>
                </fieldset>
            <?php endif; ?>

            <fieldset class="mtl-form__section">
                <legend><?= e(__('trip.start_date')) ?></legend>

                <div class="mtl-form__row">
                    <div>
                        <label for="start_date"><?= e(__('trip.start_date')) ?></label>
                        <input type="date" id="start_date" name="start_date"
                               value="<?= e(substr((string) $value('start_date'), 0, 10)) ?>">
                    </div>
                    <div>
                        <label for="end_date"><?= e(__('trip.end_date')) ?></label>
                        <input type="date" id="end_date" name="end_date"
                               value="<?= e(substr((string) $value('end_date'), 0, 10)) ?>">
                    </div>
                </div>
            </fieldset>

            <fieldset class="mtl-form__section">
                <legend><?= e(__('trip.cover')) ?></legend>

                <img data-cover-preview src="<?= e($cover?->url('small') ?? '') ?>"
                     alt="" <?= $cover === null ? 'hidden' : '' ?>
                     style="inline-size: 100%; border-radius: var(--mtl-radius);">

                <input type="hidden" id="cover_media_id" name="cover_media_id"
                       value="<?= e((string) ($trip?->int('cover_media_id') ?: '')) ?>">

                <button type="button" class="p-button"
                        data-pick-media="single"
                        data-pick-target="#cover_media_id"
                        data-pick-preview="[data-cover-preview]">
                    <?= icon('image', 16) ?> <?= e(__('media.select')) ?>
                </button>
            </fieldset>

            <fieldset class="mtl-form__section">
                <legend><?= e(__('trip.color')) ?></legend>

                <input type="color" id="color" name="color"
                       value="<?= e((string) $value('color', '#2ec27e')) ?>"
                       style="inline-size: 4rem; block-size: 2.5rem; padding: 2px;">
                <p class="p-form-help-text"><?= e(__('trip.color')) ?></p>

                <label for="tags"><?= e(__('nav.tags')) ?></label>
                <input type="text" id="tags" name="tags"
                       value="<?= e(implode(', ', array_map(static fn ($t) => $t->string('name'), $tags))) ?>"
                       placeholder="ijsland, winter">
            </fieldset>
        </aside>
    </div>

    <div class="mtl-form-bar">
        <button type="submit" class="p-button--positive" style="margin: 0;"><?= e(__('app.save')) ?></button>

        <?php if (!$isNew): ?>
            <a class="p-button" href="<?= e($trip->url()) ?>" style="margin: 0;"><?= e(__('app.more')) ?></a>
        <?php endif; ?>
    </div>
</form>

<?php if (!$isNew): ?>
    <form id="mtl-geocode-all" method="post"
          action="<?= e(path('/admin/trips/' . $trip->id() . '/geocode')) ?>">
        <?= csrf_field() ?>
    </form>
    <form id="mtl-publish-steps" method="post"
          action="<?= e(path('/admin/trips/' . $trip->id() . '/publish-steps')) ?>">
        <?= csrf_field() ?>
    </form>
<?php endif; ?>

<?php // ---- Stops ------------------------------------------------------------ ?>
<?php if (!$isNew): ?>
    <section style="margin-block-start: var(--mtl-space-7);">
        <div class="mtl-page-head">
            <h2><?= e(__('trip.steps')) ?></h2>
            <div class="mtl-page-head__actions">
                <a class="p-button--positive" href="<?= e(path('/admin/trips/' . $trip->id() . '/steps/new')) ?>">
                    <?= icon('plus', 16) ?> <?= e(__('step.new')) ?>
                </a>
            </div>
        </div>

        <?php if ($steps === []): ?>
            <p class="mtl-empty"><?= e(__('trip.no_steps')) ?></p>
        <?php else: ?>
            <ol class="mtl-sortable"
                data-sortable
                data-sortable-endpoint="<?= e(path('/admin/trips/' . $trip->id() . '/reorder')) ?>"
                data-sortable-field="order">
                <?php foreach ($steps as $step): ?>
                    <li class="mtl-sortable__item"
                        data-sortable-item="<?= e((string) $step->id()) ?>"
                        data-sortable-label="<?= e($step->string('title')) ?>">
                        <button type="button" class="mtl-sortable__handle" data-sortable-handle
                                aria-label="<?= e(__('app.edit')) ?>">
                            <?= icon('drag', 18) ?>
                        </button>

                        <span class="mtl-sortable__move">
                            <button type="button" class="p-button--base" data-sortable-up aria-label="↑">
                                <?= icon('chevron-up', 14) ?>
                            </button>
                            <button type="button" class="p-button--base" data-sortable-down aria-label="↓">
                                <?= icon('chevron-down', 14) ?>
                            </button>
                        </span>

                        <span style="flex: 1; min-inline-size: 0;">
                            <a href="<?= e($step->editUrl()) ?>"><?= e($step->string('title')) ?></a>
                            <span class="mtl-muted mtl-truncate">
                                <?= e($step->string('location_name')) ?>
                                <?php if ($step->occurredLabel() !== ''): ?>
                                    · <?= e($step->occurredLabel()) ?>
                                <?php endif; ?>
                            </span>
                            <?php if ($step->latitude() === null || $step->longitude() === null): ?>
                                <span class="mtl-status mtl-status--draft" title="<?= e(__('trip.globe_none_short')) ?>">
                                    <?= e(__('step.no_coordinates')) ?>
                                </span>
                            <?php endif; ?>
                        </span>

                        <span class="mtl-status mtl-status--<?= e($step->string('status')) ?>">
                            <?= e($step->string('status')) ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php $this->end() ?>
