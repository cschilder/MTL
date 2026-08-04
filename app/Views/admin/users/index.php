<?php
/**
 * The user list.
 *
 * @var MTL\Core\View $this
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

use MTL\Models\User;

$this->layout('layouts/admin');

/** @var list<User> $users */
$users = $this->get('users', []);
/** @var array{q:string,role:string,trashed:string} $filters */
$filters = $this->get('filters', []);

$roleLabels = [
    User::ROLE_ADMIN  => __('user.role_admin'),
    User::ROLE_EDITOR => __('user.role_editor'),
    User::ROLE_AUTHOR => __('user.role_author'),
    User::ROLE_VIEWER => __('user.role_viewer'),
];

$statusLabels = [
    'active'   => __('user.status_active'),
    'invited'  => __('user.status_invited'),
    'pending'  => __('user.status_pending'),
    'disabled' => __('user.status_disabled'),
];
?>
<?php $this->start('content') ?>

<div class="mtl-page-head">
    <div>
        <h1><?= e(__('user.users')) ?></h1>
        <p class="mtl-muted">
            <?= e(__('user.role_admin')) ?>: <?= e((string) $this->get('adminCount', 0)) ?>
        </p>
    </div>

    <div class="mtl-page-head__actions">
        <a class="p-button--positive" href="<?= e(path('/admin/users/new')) ?>">
            <?= icon('plus', 16) ?> <?= e(__('user.new')) ?>
        </a>
    </div>
</div>

<form method="get" class="mtl-row" style="margin-block-end: var(--mtl-space-4);">
    <label class="mtl-visually-hidden" for="filter-q"><?= e(__('app.search')) ?></label>
    <input type="search" id="filter-q" name="q" value="<?= e($filters['q'] ?? '') ?>"
           placeholder="<?= e(__('app.search')) ?>" style="margin: 0; max-inline-size: 16rem;">

    <label class="mtl-visually-hidden" for="filter-role"><?= e(__('user.role')) ?></label>
    <select id="filter-role" name="role" style="margin: 0; max-inline-size: 12rem;">
        <option value=""><?= e(__('app.all')) ?></option>
        <?php foreach ($roleLabels as $role => $label): ?>
            <option value="<?= e($role) ?>" <?= ($filters['role'] ?? '') === $role ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>

    <button type="submit" class="p-button" style="margin: 0;"><?= e(__('app.search')) ?></button>
</form>

<div class="mtl-table-wrap">
    <table class="mtl-table mtl-table--stack">
        <thead>
            <tr>
                <th><?= e(__('user.name')) ?></th>
                <th><?= e(__('user.email')) ?></th>
                <th><?= e(__('user.role')) ?></th>
                <th><?= e(__('user.status')) ?></th>
                <th><?= e(__('user.last_login')) ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $user): ?>
                <tr>
                    <td data-label="<?= e(__('user.name')) ?>">
                        <a href="<?= e(path('/admin/users/' . $user->id())) ?>"><?= e($user->displayName()) ?></a>
                        <?php if ($user->hasTwoFactor()): ?>
                            <span title="<?= e(__('auth.two_factor')) ?>"><?= icon('shield', 14) ?></span>
                        <?php endif; ?>
                    </td>
                    <td data-label="<?= e(__('user.email')) ?>"><?= e($user->string('email')) ?></td>
                    <td data-label="<?= e(__('user.role')) ?>"><?= e($roleLabels[$user->role()] ?? $user->role()) ?></td>
                    <td data-label="<?= e(__('user.status')) ?>">
                        <span class="mtl-status mtl-status--<?= e($user->string('status')) ?>">
                            <?= e($statusLabels[$user->string('status')] ?? $user->string('status')) ?>
                        </span>
                    </td>
                    <td data-label="<?= e(__('user.last_login')) ?>">
                        <?= e($user->date('last_login_at')?->format('j M Y, H:i') ?? __('user.never')) ?>
                    </td>
                    <td class="mtl-table__actions">
                        <?php if ($user->string('status') === 'pending'): ?>
                            <form method="post" action="<?= e(path('/admin/users/' . $user->id() . '/approve')) ?>"
                                  style="display: inline;">
                                <?= csrf_field() ?>
                                <button type="submit" class="p-button--positive is-small" style="margin: 0;">
                                    <?= e(__('user.approve')) ?>
                                </button>
                            </form>
                        <?php endif; ?>
                        <a class="p-button--base" href="<?= e(path('/admin/users/' . $user->id())) ?>">
                            <?= icon('pencil', 16) ?>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?= $this->include('partials/pagination') ?>

<?php $this->end() ?>
