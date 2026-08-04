<?php
/**
 * Site header.
 *
 * The navigation is a plain horizontal list that scrolls sideways on a phone
 * rather than collapsing into a hamburger: with five destinations, a scrolling
 * strip is one tap to anywhere instead of two.
 *
 * @var MTL\Core\View $this
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

use MTL\Services\SettingsService;

/** @var MTL\Core\Request|null $request */
$request = $this->get('request');
$currentPath = $request?->path ?? '/';
$currentUser = $this->get('currentUser');

/**
 * Marks the entry for the section the visitor is in. A prefix match, so a step
 * page still highlights "Trips".
 */
$isCurrent = static function (string $prefix) use ($currentPath): bool {
    return $prefix === '/'
        ? $currentPath === '/'
        : str_starts_with($currentPath, $prefix);
};

$links = [
    ['/', __('nav.home'), 'globe'],
    ['/trips', __('nav.trips'), 'route'],
    ['/albums', __('nav.albums'), 'photo-stack'],
];
?>
<header class="mtl-header">
    <a class="mtl-header__brand" href="<?= e(path('/')) ?>">
        <?php $logoId = SettingsService::int('site.logo_media_id'); ?>
        <?php if ($logoId > 0): ?>
            <img src="<?= e(path('/media/thumb/' . $logoId)) ?>" alt="" width="28" height="28">
        <?php else: ?>
            <?php // The house mark: the Zetstenen world, from assets/branding. ?>
            <img src="<?= e(asset('branding/mtl-zetstenen.svg')) ?>" alt="" width="28" height="28">
        <?php endif; ?>
        <span><?= e(SettingsService::string('site.title', 'MTL')) ?></span>
    </a>

    <nav class="mtl-header__nav" aria-label="<?= e(__('app.menu')) ?>">
        <?php foreach ($links as [$href, $label, $iconName]): ?>
            <a class="mtl-header__link"
               href="<?= e(path($href)) ?>"
               <?= $isCurrent($href) ? 'aria-current="page"' : '' ?>>
                <?= icon($iconName, 18) ?>
                <span><?= e($label) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="mtl-header__actions">
        <form class="p-search-box" role="search" action="<?= e(path('/search')) ?>" method="get">
            <label class="mtl-visually-hidden" for="mtl-search"><?= e(__('app.search')) ?></label>
            <input type="search"
                   id="mtl-search"
                   name="q"
                   class="p-search-box__input"
                   placeholder="<?= e(__('search.placeholder')) ?>"
                   value="<?= e($request?->string('q') ?? '') ?>">
            <button type="submit" class="p-search-box__button" aria-label="<?= e(__('app.search')) ?>">
                <?= icon('search', 18) ?>
            </button>
        </form>

        <?php if ($currentUser !== null && can('admin.access')): ?>
            <a class="mtl-header__link" href="<?= e(path('/admin')) ?>">
                <?= icon('settings', 18) ?>
                <span class="u-hide--small"><?= e(__('nav.admin')) ?></span>
            </a>
        <?php elseif ($currentUser !== null): ?>
            <?php // Signed in without a management area to go to (a viewer):
                  // the only sensible action here is signing out. Offering the
                  // gear instead used to lead straight to a 403 with no way
                  // back — a person could not even switch accounts. ?>
            <a class="mtl-header__link" href="<?= e(path('/logout')) ?>">
                <?= icon('sign-out', 18) ?>
                <span class="u-hide--small"><?= e(__('nav.sign_out')) ?></span>
            </a>
        <?php else: ?>
            <a class="mtl-header__link" href="<?= e(path('/login')) ?>">
                <?= icon('user', 18) ?>
                <span class="u-hide--small"><?= e(__('nav.sign_in')) ?></span>
            </a>
        <?php endif; ?>
    </div>
</header>
