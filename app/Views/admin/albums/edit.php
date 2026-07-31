<?php
/**
 * Creating or editing an album.
 *
 * @var MTL\Core\View $this
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

$this->layout('layouts/admin');

/** @var MTL\Models\Album|null $album */
$album = $this->get('album');
/** @var list<MTL\Models\Media> $media */
$media = $this->get('media', []);
/** @var list<MTL\Models\Trip> $trips */
$trips = $this->get('trips', []);
/** @var MTL\Models\Media|null $cover */
$cover = $this->get('cover');

$isNew = $album === null;

$value = static fn (string $field, mixed $default = '') => old($field, $album?->string($field) ?? $default);
?>
<?php $this->start('content') ?>

<div class="mtl-page-head">
    <h1><?= e($isNew ? __('album.new') : $album->string('title')) ?></h1>

    <div class="mtl-page-head__actions">
        <a class="p-button" href="<?= e(path('/admin/albums')) ?>"><?= e(__('app.back')) ?></a>

        <?php if (!$isNew && can('album.delete', $album)): ?>
            <form method="post" action="<?= e(path('/admin/albums/' . $album->id())) ?>"
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
                <label for="title"><?= e(__('album.title')) ?></label>
                <input type="text" id="title" name="title" required maxlength="180"
                       value="<?= e((string) $value('title')) ?>" autofocus>
                <?php foreach (errors('title') as $message): ?>
                    <p class="p-form-validation__message"><?= e($message) ?></p>
                <?php endforeach; ?>
            </div>

            <div>
                <label for="description_md"><?= e(__('album.description')) ?></label>
                <textarea id="description_md" name="description_md" rows="4"><?= e((string) $value('description_md')) ?></textarea>
            </div>

            <?php if (!$isNew): ?>
                <section>
                    <h2><?= e(__('media.media')) ?></h2>

                    <div class="mtl-dropzone"
                         data-uploader
                         data-uploader-target="album"
                         data-uploader-target-id="<?= e((string) $album->id()) ?>"
                         data-uploader-list="[data-upload-list]">
                        <?= icon('upload', 28) ?>
                        <span><?= e(__('media.drop_here')) ?></span>
                        <input type="file" multiple accept="image/*,video/*" aria-label="<?= e(__('media.upload')) ?>">
                    </div>

                    <div class="mtl-uploads" data-upload-list></div>

                    <?php if ($media !== []): ?>
                        <ol class="mtl-sortable" style="margin-block-start: var(--mtl-space-4);"
                            data-sortable
                            data-sortable-endpoint="<?= e(path('/admin/albums/' . $album->id() . '/media/order')) ?>"
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
                                        <a href="<?= e(path('/admin/media/' . $item->id())) ?>"><?= e($item->displayTitle()) ?></a>
                                    </span>

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
                    <?php foreach (['inherit', 'public', 'unlisted', 'private'] as $visibility): ?>
                        <option value="<?= e($visibility) ?>" <?= $value('visibility', 'inherit') === $visibility ? 'selected' : '' ?>>
                            <?= e($visibility) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="layout"><?= e(__('album.layout')) ?></label>
                <select id="layout" name="layout">
                    <?php
                    $layouts = [
                        'grid'    => __('album.layout_grid'),
                        'masonry' => __('album.layout_masonry'),
                        'story'   => __('album.layout_story'),
                    ];
                    ?>
                    <?php foreach ($layouts as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $value('layout', 'grid') === $key ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </fieldset>

            <fieldset class="mtl-form__section">
                <legend><?= e(__('album.belongs_to')) ?></legend>

                <label for="trip_id"><?= e(__('trip.trip')) ?></label>
                <select id="trip_id" name="trip_id">
                    <option value=""><?= e(__('app.none')) ?></option>
                    <?php foreach ($trips as $tripOption): ?>
                        <option value="<?= e((string) $tripOption->id()) ?>"
                                <?= (string) $value('trip_id') === (string) $tripOption->id() ? 'selected' : '' ?>>
                            <?= e($tripOption->string('title')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </fieldset>

            <fieldset class="mtl-form__section">
                <legend><?= e(__('trip.cover')) ?></legend>

                <img data-cover-preview src="<?= e($cover?->url('small') ?? '') ?>" alt=""
                     <?= $cover === null ? 'hidden' : '' ?>
                     style="inline-size: 100%; border-radius: var(--mtl-radius);">

                <input type="hidden" id="cover_media_id" name="cover_media_id"
                       value="<?= e((string) ($album?->int('cover_media_id') ?: '')) ?>">

                <button type="button" class="p-button"
                        data-pick-media="single"
                        data-pick-target="#cover_media_id"
                        data-pick-preview="[data-cover-preview]">
                    <?= icon('image', 16) ?> <?= e(__('media.select')) ?>
                </button>
            </fieldset>
        </aside>
    </div>

    <div class="mtl-form-bar">
        <button type="submit" class="p-button--positive" style="margin: 0;"><?= e(__('app.save')) ?></button>
    </div>
</form>

<?php if (!$isNew): ?>
    <?php foreach ($media as $item): ?>
        <form id="detach-<?= e((string) $item->id()) ?>" method="post" hidden
              action="<?= e(path('/admin/albums/' . $album->id() . '/media/' . $item->id())) ?>">
            <?= csrf_field() ?>
            <?= method_field('DELETE') ?>
        </form>
    <?php endforeach; ?>
<?php endif; ?>

<?php $this->end() ?>
