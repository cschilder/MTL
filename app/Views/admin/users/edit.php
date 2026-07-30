<?php
/**
 * Creating or editing a user.
 *
 * @var MTL\Core\View $this
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

use MTL\Models\User;

$this->layout('layouts/admin');

/** @var User|null $user */
$user = $this->get('user');
/** @var list<string> $roles */
$roles = $this->get('roles', []);
/** @var list<string> $permissions */
$permissions = $this->get('permissions', []);
/** @var list<array<string,mixed>> $sessions */
$sessions = $this->get('sessions', []);
/** @var list<array<string,mixed>> $recent */
$recent = $this->get('recent', []);

$isNew = $user === null;

$value = static fn (string $field, mixed $default = '') => old($field, $user?->string($field) ?? $default);

$roleLabels = [
    User::ROLE_ADMIN  => [__('user.role_admin'), __('user.role_admin_hint')],
    User::ROLE_EDITOR => [__('user.role_editor'), __('user.role_editor_hint')],
    User::ROLE_AUTHOR => [__('user.role_author'), __('user.role_author_hint')],
    User::ROLE_VIEWER => [__('user.role_viewer'), __('user.role_viewer_hint')],
];
?>
<?php $this->start('content') ?>

<div class="mtl-page-head">
    <h1><?= e($isNew ? __('user.new') : $user->displayName()) ?></h1>

    <div class="mtl-page-head__actions">
        <a class="p-button" href="<?= e(path('/admin/users')) ?>"><?= e(__('app.back')) ?></a>

        <?php if (!$isNew && $user->id() !== ($this->get('currentUser')?->id() ?? 0)): ?>
            <form method="post" action="<?= e(path('/admin/users/' . $user->id())) ?>"
                  data-confirm="<?= e(__('js.common.confirm_delete')) ?>">
                <?= csrf_field() ?>
                <?= method_field('DELETE') ?>
                <button type="submit" class="p-button--negative"><?= e(__('app.delete')) ?></button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="mtl-editor-layout">
    <form method="post" action="<?= e((string) $this->get('action')) ?>" class="mtl-form">
        <?= csrf_field() ?>
        <?php if (!$isNew): ?>
            <?= method_field('PUT') ?>
        <?php endif; ?>

        <div>
            <label for="name"><?= e(__('user.name')) ?></label>
            <input type="text" id="name" name="name" required maxlength="120"
                   value="<?= e((string) $value('name')) ?>" autofocus>
            <?php foreach (errors('name') as $message): ?>
                <p class="p-form-validation__message"><?= e($message) ?></p>
            <?php endforeach; ?>
        </div>

        <div>
            <label for="email"><?= e(__('user.email')) ?></label>
            <input type="email" id="email" name="email" required
                   autocapitalize="none" spellcheck="false"
                   value="<?= e((string) $value('email')) ?>">
            <?php foreach (errors('email') as $message): ?>
                <p class="p-form-validation__message"><?= e($message) ?></p>
            <?php endforeach; ?>
        </div>

        <fieldset class="mtl-form__section">
            <legend><?= e(__('user.role')) ?></legend>

            <?php foreach ($roles as $role): ?>
                <?php [$label, $hint] = $roleLabels[$role] ?? [$role, '']; ?>
                <label class="p-radio">
                    <input type="radio" class="p-radio__input" name="role" value="<?= e($role) ?>"
                           <?= $value('role', 'author') === $role ? 'checked' : '' ?>>
                    <span class="p-radio__label">
                        <?= e($label) ?>
                        <br><small class="mtl-muted"><?= e($hint) ?></small>
                    </span>
                </label>
            <?php endforeach; ?>
        </fieldset>

        <div>
            <label for="status"><?= e(__('user.status')) ?></label>
            <select id="status" name="status">
                <?php foreach (['invited', 'active', 'disabled'] as $status): ?>
                    <option value="<?= e($status) ?>" <?= $value('status', 'invited') === $status ? 'selected' : '' ?>>
                        <?= e(__('user.status_' . $status)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if (!$isNew): ?>
            <div>
                <label for="bio"><?= e(__('user.name')) ?></label>
                <textarea id="bio" name="bio" rows="3" maxlength="500"><?= e((string) $value('bio')) ?></textarea>
            </div>
        <?php endif; ?>

        <button type="submit" class="p-button--positive"><?= e(__('app.save')) ?></button>
    </form>

    <aside class="mtl-editor-layout__side mtl-stack">
        <?php if (!$isNew): ?>
            <div class="mtl-form__section">
                <h2><?= e(__('user.status')) ?></h2>

                <dl style="display: grid; grid-template-columns: auto 1fr; gap: var(--mtl-space-1) var(--mtl-space-3); font-size: 0.875rem;">
                    <dt class="mtl-muted"><?= e(__('user.last_login')) ?></dt>
                    <dd style="margin: 0;"><?= e($user->date('last_login_at')?->format('j M Y, H:i') ?? __('user.never')) ?></dd>

                    <dt class="mtl-muted"><?= e(__('auth.two_factor')) ?></dt>
                    <dd style="margin: 0;"><?= e($user->hasTwoFactor() ? __('app.yes') : __('app.no')) ?></dd>

                    <dt class="mtl-muted">Sessies</dt>
                    <dd style="margin: 0;"><?= e((string) count($sessions)) ?></dd>
                </dl>

                <div class="mtl-row" style="margin-block-start: var(--mtl-space-3);">
                    <form method="post" action="<?= e(path('/admin/users/' . $user->id() . '/invite')) ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="p-button"><?= e(__('user.invited')) ?></button>
                    </form>

                    <?php if ($sessions !== []): ?>
                        <form method="post" action="<?= e(path('/admin/users/' . $user->id() . '/sessions')) ?>">
                            <?= csrf_field() ?>
                            <?= method_field('DELETE') ?>
                            <button type="submit" class="p-button"><?= e(__('user.sessions_revoked')) ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($permissions !== []): ?>
                <div class="mtl-form__section">
                    <h2><?= e(__('user.role')) ?></h2>
                    <ul class="p-list" style="font-size: 0.8125rem; columns: 2;">
                        <?php foreach ($permissions as $permission): ?>
                            <li class="p-list__item"><?= e($permission) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($recent !== []): ?>
                <div class="mtl-form__section">
                    <h2><?= e(__('admin.recent_activity')) ?></h2>
                    <ul class="p-list" style="font-size: 0.8125rem;">
                        <?php foreach ($recent as $entry): ?>
                            <li class="p-list__item">
                                <?= e((string) $entry['action']) ?>
                                <span class="mtl-muted">— <?= e(date('j M, H:i', (int) strtotime((string) $entry['created_at'] . ' UTC'))) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="mtl-form__section">
                <h2><?= e(__('user.invited')) ?></h2>
                <p class="mtl-muted">
                    Er wordt geen wachtwoord ingesteld. De nieuwe gebruiker krijgt een
                    uitnodigingslink en kiest er zelf een.
                </p>
            </div>
        <?php endif; ?>
    </aside>
</div>

<?php $this->end() ?>
