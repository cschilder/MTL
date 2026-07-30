<?php
/**
 * The album overview.
 *
 * @var MTL\Core\View $this
 * @var list<MTL\Models\Album> $albums
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

$this->layout('layouts/app');

/** @var list<MTL\Models\Album> $albums */
$albums = $this->get('albums', []);
?>
<?php $this->start('content') ?>

<div class="mtl-page-head">
    <h1><?= e(__('album.albums')) ?></h1>

    <div class="mtl-page-head__actions">
        <?php if (can('album.create')): ?>
            <a class="p-button--positive" href="<?= e(path('/admin/albums/new')) ?>">
                <?= icon('plus', 16) ?> <?= e(__('album.new')) ?>
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($albums === []): ?>
    <p class="mtl-empty"><?= e(__('album.none')) ?></p>
<?php else: ?>
    <div class="mtl-card-grid">
        <?php foreach ($albums as $album): ?>
            <?php $cover = $album->coverMedia(); ?>
            <article class="mtl-card">
                <?php if ($cover !== null): ?>
                    <img class="mtl-card__media"
                         src="<?= e($cover->url('small')) ?>"
                         srcset="<?= e($cover->srcset()) ?>"
                         sizes="(min-width: 60rem) 20rem, 100vw"
                         alt="" loading="lazy" decoding="async"
                         style="<?= e($cover->placeholderStyle()) ?>">
                <?php endif; ?>

                <div class="mtl-card__body">
                    <h2><a href="<?= e($album->url()) ?>"><?= e($album->string('title')) ?></a></h2>
                    <p class="mtl-card__meta">
                        <?= icon('image', 14) ?> <?= e((string) $album->int('media_count')) ?>
                        <?php if (!$album->isPublished()): ?>
                            <span class="mtl-status mtl-status--draft"><?= e($album->string('status')) ?></span>
                        <?php endif; ?>
                    </p>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <?= $this->include('partials/pagination') ?>
<?php endif; ?>

<?php $this->end() ?>
