<?php
/**
 * The signed-in visitor's own account.
 *
 * @var MTL\Core\View $this
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

$this->layout('layouts/admin');

/** @var MTL\Models\User $user */
$user = $this->get('user');
/** @var MTL\Models\Media|null $avatar */
$avatar = $this->get('avatar');
/** @var list<string> $locales */
$locales = $this->get('locales', []);
/** @var list<string> $timezones */
$timezones = $this->get('timezones', []);

$theme = $user->preference('theme', 'auto');
?>
<?php $this->start('content') ?>

<div class="mtl-page-head">
    <h1><?= e(__('nav.profile')) ?></h1>
</div>

<div class="mtl-editor-layout">
    <div class="mtl-stack">
        <form method="post" action="<?= e(path('/admin/profile')) ?>" class="mtl-form">
            <?= csrf_field() ?>

            <fieldset class="mtl-form__section">
                <legend><?= e(__('user.name')) ?></legend>

                <div>
                    <label for="name"><?= e(__('user.name')) ?></label>
                    <input type="text" id="name" name="name" required maxlength="120"
                           value="<?= e((string) old('name', $user->string('name'))) ?>">
                </div>

                <div>
                    <label for="email"><?= e(__('user.email')) ?></label>
                    <input type="email" id="email" name="email" required
                           autocapitalize="none" spellcheck="false"
                           value="<?= e((string) old('email', $user->string('email'))) ?>">
                    <?php foreach (errors('email') as $message): ?>
                        <p class="p-form-validation__message"><?= e($message) ?></p>
                    <?php endforeach; ?>
                </div>

                <div>
                    <label for="bio"><?= e(__('trip.summary')) ?></label>
                    <textarea id="bio" name="bio" rows="3" maxlength="500"><?= e((string) old('bio', $user->string('bio'))) ?></textarea>
                </div>

                <div class="mtl-form__row">
                    <div>
                        <label for="locale"><?= e(__('app.language')) ?></label>
                        <select id="locale" name="locale">
                            <?php foreach ($locales as $code): ?>
                                <option value="<?= e($code) ?>" <?= $user->locale() === $code ? 'selected' : '' ?>>
                                    <?= e(strtoupper($code)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="timezone"><?= e(__('step.occurred_at')) ?></label>
                        <select id="timezone" name="timezone">
                            <?php foreach ($timezones as $zone): ?>
                                <option value="<?= e($zone) ?>" <?= $user->string('timezone') === $zone ? 'selected' : '' ?>>
                                    <?= e($zone) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div>
                    <p class="p-form__label"><?= e(__('user.name')) ?></p>

                    <img data-avatar-preview src="<?= e($avatar?->url('thumb') ?? '') ?>" alt=""
                         <?= $avatar === null ? 'hidden' : '' ?>
                         style="inline-size: 5rem; aspect-ratio: 1; object-fit: cover; border-radius: 50%;">

                    <input type="hidden" id="avatar_media_id" name="avatar_media_id"
                           value="<?= e((string) ($user->int('avatar_media_id') ?: '')) ?>">

                    <button type="button" class="p-button"
                            data-pick-media="single"
                            data-pick-target="#avatar_media_id"
                            data-pick-preview="[data-avatar-preview]">
                        <?= icon('image', 16) ?> <?= e(__('media.select')) ?>
                    </button>
                </div>

                <button type="submit" class="p-button--positive"><?= e(__('app.save')) ?></button>
            </fieldset>
        </form>

        <form method="post" action="<?= e(path('/admin/profile/password')) ?>" class="mtl-form">
            <?= csrf_field() ?>

            <fieldset class="mtl-form__section">
                <legend><?= e(__('auth.password')) ?></legend>

                <div>
                    <label for="current_password"><?= e(__('auth.current_password')) ?></label>
                    <input type="password" id="current_password" name="current_password" required
                           autocomplete="current-password">
                </div>

                <div>
                    <label for="password"><?= e(__('auth.new_password')) ?></label>
                    <input type="password" id="password" name="password" required
                           autocomplete="new-password" minlength="10">
                    <?php foreach (errors('password') as $message): ?>
                        <p class="p-form-validation__message"><?= e($message) ?></p>
                    <?php endforeach; ?>
                </div>

                <div>
                    <label for="password_confirmation"><?= e(__('auth.confirm_password')) ?></label>
                    <input type="password" id="password_confirmation" name="password_confirmation" required
                           autocomplete="new-password">
                </div>

                <p class="p-form-help-text">
                    Alle andere apparaten waarop je ingelogd bent, worden hierna uitgelogd.
                </p>

                <button type="submit" class="p-button"><?= e(__('app.save')) ?></button>
            </fieldset>
        </form>
    </div>

    <aside class="mtl-editor-layout__side mtl-stack">
        <div class="mtl-form__section">
            <h2><?= e(__('app.theme')) ?></h2>

            <div class="p-segmented-control">
                <div class="p-segmented-control__list" role="group">
                    <?php foreach (['auto' => __('app.theme_auto'), 'light' => __('app.theme_light'), 'dark' => __('app.theme_dark')] as $key => $label): ?>
                        <button type="button" class="p-segmented-control__button"
                                data-theme-set="<?= e($key) ?>"
                                aria-pressed="<?= $theme === $key ? 'true' : 'false' ?>">
                            <?= e($label) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="mtl-form__section">
            <h2><?= e(__('auth.two_factor')) ?></h2>

            <p>
                <?= e($user->hasTwoFactor() ? __('auth.two_factor_enabled') : __('auth.two_factor_disabled')) ?>
            </p>

            <a class="p-button<?= $user->hasTwoFactor() ? '' : '--positive' ?>" href="<?= e(path('/admin/profile/2fa')) ?>">
                <?= icon('shield', 16) ?> <?= e($user->hasTwoFactor() ? __('app.edit') : __('app.create')) ?>
            </a>
        </div>

        <div class="mtl-form__section">
            <h2><?= e(__('user.role')) ?></h2>
            <p><?= e(__('user.role_' . $user->role())) ?></p>
            <p class="mtl-muted"><?= e(__('user.role_' . $user->role() . '_hint')) ?></p>
        </div>
    </aside>
</div>

<?php $this->end() ?>
