<?php
/**
 * The management environment shell: header, side rail, content.
 *
 * @var MTL\Core\View $this
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

$this->layout('layouts/base');

/** @var MTL\Core\Request|null $request */
$request = $this->get('request');
$path = $request?->path ?? '';

$current = static fn (string $prefix): string => str_starts_with($path, $prefix) ? ' aria-current="page"' : '';

$groups = [
    __('nav.dashboard') => [
        ['/admin', __('admin.dashboard'), 'globe', null],
    ],
    __('trip.trips') => [
        ['/admin/trips', __('trip.trips'), 'route', null],
        ['/admin/albums', __('album.albums'), 'photo-stack', 'album.create'],
        ['/admin/media', __('media.library'), 'image', 'media.upload'],
        ['/admin/tags', __('nav.tags'), 'tag', 'tag.manage'],
    ],
    __('admin.system') => [
        ['/admin/users', __('user.users'), 'users', 'user.manage'],
        ['/admin/settings', __('settings.settings'), 'settings', 'settings.manage'],
        ['/admin/audit', __('audit.audit'), 'clock', 'audit.view'],
        ['/admin/maintenance', __('maintenance.maintenance'), 'shield', 'maintenance.run'],
    ],
];
?>
<?php $this->start('body') ?>

<?= $this->include('partials/header') ?>

<div class="mtl-admin">
    <nav class="mtl-admin__rail" aria-label="<?= e(__('nav.admin')) ?>">
        <p class="u-hide--large" style="margin-block-end: var(--mtl-space-3);">
            <button type="button" class="p-button--base" data-admin-menu-toggle aria-expanded="true">
                <?= icon('menu', 18) ?> <?= e(__('app.menu')) ?>
            </button>
        </p>

        <?php foreach ($groups as $heading => $links): ?>
            <?php
            // Hide a whole group when the visitor may reach none of its items.
            $visible = array_values(array_filter(
                $links,
                static fn (array $link): bool => $link[3] === null || can($link[3])
            ));
            ?>

            <?php if ($visible === []): ?>
                <?php continue; ?>
            <?php endif; ?>

            <div class="mtl-admin__nav-group">
                <h3><?= e($heading) ?></h3>

                <?php foreach ($visible as [$href, $label, $iconName]): ?>
                    <a class="mtl-admin__nav-link" href="<?= e(path($href)) ?>"<?= $href === '/admin' ? ($path === '/admin' ? ' aria-current="page"' : '') : $current($href) ?>>
                        <?= icon($iconName, 18) ?>
                        <span><?= e($label) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>

        <div class="mtl-admin__nav-group">
            <h3><?= e(__('nav.profile')) ?></h3>

            <a class="mtl-admin__nav-link" href="<?= e(path('/admin/profile')) ?>"<?= $current('/admin/profile') ?>>
                <?= icon('user', 18) ?>
                <span><?= e($this->get('currentUser')?->displayName() ?? '') ?></span>
            </a>

            <form method="post" action="<?= e(path('/logout')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="mtl-admin__nav-link" style="background: none; border: 0; inline-size: 100%; text-align: start; cursor: pointer;">
                    <?= icon('sign-out', 18) ?>
                    <span><?= e(__('nav.sign_out')) ?></span>
                </button>
            </form>
        </div>
    </nav>

    <main id="mtl-main" class="mtl-admin__main">
        <?= $this->section('content') ?>
    </main>
</div>

<?= $this->include('partials/notifications') ?>
<?= $this->include('partials/media-picker') ?>

<?php $this->end() ?>
