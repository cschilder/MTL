<?php
/**
 * Editing one media record.
 *
 * @var MTL\Core\View $this
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

$this->layout('layouts/admin');

/** @var MTL\Models\Media $media */
$media = $this->get('media');
/** @var array{steps:list<array<string,mixed>>,albums:list<array<string,mixed>>} $usedIn */
$usedIn = $this->get('usedIn', ['steps' => [], 'albums' => []]);

$value = static fn (string $field, mixed $default = '') => old($field, $media->string($field) ?: $default);
?>
<?php $this->start('content') ?>

<div class="mtl-page-head">
    <div>
        <h1><?= e($media->displayTitle()) ?></h1>
        <p class="mtl-muted"><?= e($media->string('original_name')) ?></p>
    </div>

    <div class="mtl-page-head__actions">
        <a class="p-button" href="<?= e(path('/admin/media')) ?>"><?= e(__('app.back')) ?></a>

        <?php if (can('media.delete', $media)): ?>
            <form method="post" action="<?= e(path('/admin/media/' . $media->id())) ?>"
                  data-confirm="<?= e(__('js.common.confirm_delete')) ?>">
                <?= csrf_field() ?>
                <?= method_field('DELETE') ?>
                <button type="submit" class="p-button--negative"><?= e(__('app.delete')) ?></button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($media->string('status') === 'failed'): ?>
    <div class="p-notification--caution">
        <div class="p-notification__content">
            <p class="p-notification__message"><?= e($media->string('processing_error')) ?></p>
        </div>
    </div>
<?php endif; ?>

<div class="mtl-editor-layout">
    <div>
        <?php if ($media->isImage()): ?>
            <img src="<?= e($media->url('large')) ?>" alt="<?= e($media->alt()) ?>"
                 style="inline-size: 100%; border-radius: var(--mtl-radius); <?= e($media->placeholderStyle()) ?>">
        <?php elseif ($media->isVideo()): ?>
            <video controls preload="metadata" playsinline style="inline-size: 100%; border-radius: var(--mtl-radius);">
                <source src="<?= e($media->url('original')) ?>" type="<?= e($media->string('mime_type')) ?>">
            </video>
        <?php endif; ?>

        <form method="post" action="<?= e((string) $this->get('action')) ?>" class="mtl-form"
              style="margin-block-start: var(--mtl-space-5);">
            <?= csrf_field() ?>
            <?= method_field('PUT') ?>

            <div>
                <label for="title"><?= e(__('media.title')) ?></label>
                <input type="text" id="title" name="title" maxlength="180" value="<?= e((string) $value('title')) ?>">
            </div>

            <div>
                <label for="alt_text"><?= e(__('media.alt_text')) ?></label>
                <input type="text" id="alt_text" name="alt_text" maxlength="500" value="<?= e((string) $value('alt_text')) ?>">
                <p class="p-form-help-text"><?= e(__('media.alt_hint')) ?></p>
            </div>

            <div>
                <label for="caption_md"><?= e(__('media.caption')) ?></label>
                <textarea id="caption_md" name="caption_md" rows="3"><?= e((string) $value('caption_md')) ?></textarea>
            </div>

            <div class="mtl-form__row">
                <div>
                    <label for="credit"><?= e(__('media.credit')) ?></label>
                    <input type="text" id="credit" name="credit" maxlength="180" value="<?= e((string) $value('credit')) ?>">
                </div>
                <div>
                    <label for="visibility"><?= e(__('trip.visibility')) ?></label>
                    <select id="visibility" name="visibility">
                        <?php foreach (['inherit', 'public', 'private'] as $visibility): ?>
                            <option value="<?= e($visibility) ?>" <?= $value('visibility', 'inherit') === $visibility ? 'selected' : '' ?>>
                                <?= e($visibility) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

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
                <div>
                    <label for="captured_at"><?= e(__('media.taken_at')) ?></label>
                    <input type="datetime-local" id="captured_at" name="captured_at"
                           value="<?= e(str_replace(' ', 'T', substr((string) $value('captured_at'), 0, 16))) ?>">
                </div>
            </div>

            <button type="submit" class="p-button--positive"><?= e(__('app.save')) ?></button>
        </form>
    </div>

    <aside class="mtl-editor-layout__side mtl-stack">
        <div class="mtl-form__section">
            <h2><?= e(__('media.media')) ?></h2>

            <dl style="display: grid; grid-template-columns: auto 1fr; gap: var(--mtl-space-1) var(--mtl-space-3); font-size: 0.875rem;">
                <dt class="mtl-muted"><?= e(__('media.dimensions')) ?></dt>
                <dd style="margin: 0;"><?= e((string) $media->int('width')) ?> × <?= e((string) $media->int('height')) ?></dd>

                <dt class="mtl-muted"><?= e(__('media.file_size')) ?></dt>
                <dd style="margin: 0;"><?= e($media->sizeLabel()) ?></dd>

                <dt class="mtl-muted">MIME</dt>
                <dd style="margin: 0;"><?= e($media->string('mime_type')) ?></dd>

                <?php if ($media->cameraLabel() !== ''): ?>
                    <dt class="mtl-muted"><?= e(__('media.camera')) ?></dt>
                    <dd style="margin: 0;"><?= e($media->cameraLabel()) ?></dd>
                <?php endif; ?>

                <?php if ($media->string('exposure') !== ''): ?>
                    <dt class="mtl-muted">Belichting</dt>
                    <dd style="margin: 0;"><?= e($media->string('exposure')) ?><?= $media->int('iso') > 0 ? ' · ISO ' . e((string) $media->int('iso')) : '' ?></dd>
                <?php endif; ?>
            </dl>

            <p>
                <a class="p-button" href="<?= e(path('/download/' . $media->string('uuid'))) ?>">
                    <?= icon('download', 16) ?> <?= e(__('media.download')) ?>
                </a>
            </p>
        </div>

        <?php if ($usedIn['steps'] !== [] || $usedIn['albums'] !== []): ?>
            <div class="mtl-form__section">
                <h2><?= e(__('album.belongs_to')) ?></h2>
                <ul class="p-list">
                    <?php foreach ($usedIn['steps'] as $row): ?>
                        <li class="p-list__item">
                            <a href="<?= e(path('/admin/steps/' . $row['id'])) ?>"><?= e((string) $row['title']) ?></a>
                        </li>
                    <?php endforeach; ?>
                    <?php foreach ($usedIn['albums'] as $row): ?>
                        <li class="p-list__item">
                            <a href="<?= e(path('/admin/albums/' . $row['id'])) ?>"><?= e((string) $row['title']) ?></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </aside>
</div>

<?php $this->end() ?>
