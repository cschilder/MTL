<?php
/**
 * One album.
 *
 * @var MTL\Core\View $this
 * @var MTL\Models\Album $album
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

$this->layout('layouts/app');

/** @var MTL\Models\Album $album */
$album = $this->get('album');
/** @var list<MTL\Models\Media> $media */
$media = $this->get('media', []);
$parent = $this->get('parent');
?>
<?php $this->start('content') ?>

<nav class="p-breadcrumbs" aria-label="Breadcrumb">
    <ol class="p-breadcrumbs__items">
        <li class="p-breadcrumbs__item"><a href="<?= e(path('/albums')) ?>"><?= e(__('album.albums')) ?></a></li>
        <?php if ($parent !== null): ?>
            <li class="p-breadcrumbs__item">
                <a href="<?= e($parent->url()) ?>"><?= e($parent->string('title')) ?></a>
            </li>
        <?php endif; ?>
        <li class="p-breadcrumbs__item"><span aria-current="page"><?= e($album->string('title')) ?></span></li>
    </ol>
</nav>

<div class="mtl-page-head">
    <div>
        <h1><?= e($album->string('title')) ?></h1>
        <p class="mtl-muted"><?= e((string) $album->int('media_count')) ?> <?= e(mb_strtolower(__('media.media'))) ?></p>
    </div>

    <div class="mtl-page-head__actions">
        <?php if ($this->get('canEdit', false)): ?>
            <a class="p-button--base" href="<?= e($album->editUrl()) ?>">
                <?= icon('pencil', 16) ?> <?= e(__('app.edit')) ?>
            </a>
        <?php endif; ?>
    </div>
</div>

<?php $description = (string) $this->get('descriptionHtml', ''); ?>
<?php if ($description !== ''): ?>
    <div class="mtl-prose" style="max-inline-size: 46rem; margin-block-end: var(--mtl-space-5);">
        <?= $description /* rendered markdown */ ?>
    </div>
<?php endif; ?>

<?php if ($media === []): ?>
    <p class="mtl-empty"><?= e(__('album.empty')) ?></p>
<?php else: ?>
    <?= $this->include('partials/gallery', ['media' => $media, 'layout' => $album->layout()]) ?>
<?php endif; ?>

<?php $this->end() ?>
