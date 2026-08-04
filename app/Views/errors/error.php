<?php
/**
 * Error page.
 *
 * In debug mode it also shows the exception, its location and the stack trace.
 * In production it shows only the status and a message that has been vetted as
 * safe to display.
 *
 * @var MTL\Core\View $this
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

$this->layout('layouts/app', ['noindex' => true]);

/** @var int $status */
$status = $this->get('status', 500);
/** @var string $message */
$message = $this->get('message', '');
/** @var Throwable|null $exception */
$exception = $this->get('exception');

$illustration = match (true) {
    $status === 404 => 'pin',
    $status === 403, $status === 401 => 'shield',
    $status === 419 => 'clock',
    $status === 429 => 'clock',
    default => 'warning',
};
?>
<?php $this->start('content') ?>

<div class="u-align--center" style="padding-block: var(--mtl-space-7);">
    <p style="color: var(--mtl-accent);"><?= icon($illustration, 56) ?></p>

    <h1><?= e((string) $status) ?></h1>
    <p class="p-heading--4"><?= e($message) ?></p>

    <p style="margin-block-start: var(--mtl-space-5);">
        <a class="p-button--positive" href="<?= e(path('/')) ?>"><?= e(__('error.go_home')) ?></a>
    </p>

    <?php
    // On a 403 the likeliest story is "signed in as the wrong account" — a
    // viewer tapping the management gear, a shared device. Without this
    // block there was no way out: signing out lived behind the very page
    // that refused them.
    $viewer = null;
    if ($status === 403) {
        try {
            $viewer = MTL\Auth\AuthManager::instance()->user();
        } catch (\Throwable) {
            // Errors this early have no session; the page stays generic.
        }
    }
    ?>
    <?php if ($viewer !== null): ?>
        <p class="mtl-muted"><?= e(__('auth.signed_in_as', ['name' => $viewer->displayName()])) ?></p>
        <form method="post" action="<?= e(path('/logout')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="p-button" style="margin: 0;"><?= e(__('nav.sign_out')) ?></button>
        </form>
    <?php endif; ?>
</div>

<?php if ($exception !== null): ?>
    <?php // Debug mode only — Application never passes an exception in production. ?>
    <div class="p-notification--caution">
        <div class="p-notification__content">
            <h2 class="p-notification__title"><?= e($exception::class) ?></h2>
            <p class="p-notification__message"><?= e($exception->getMessage()) ?></p>
        </div>
    </div>

    <p class="mtl-muted">
        <?= e($exception->getFile()) ?>:<?= e((string) $exception->getLine()) ?>
    </p>

    <details>
        <summary>Stack trace</summary>
        <pre><code><?= e($exception->getTraceAsString()) ?></code></pre>
    </details>
<?php endif; ?>

<?php $this->end() ?>
