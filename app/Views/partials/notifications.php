<?php
/**
 * Flash notifications queued by the previous request.
 *
 * Rendered server-side so a message survives a redirect without JavaScript;
 * app.js only adds the dismiss behaviour and the auto-hide timer.
 *
 * @var MTL\Core\View $this
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

/** @var list<array{type:string,message:string}> $notifications */
$notifications = $this->get('notifications', []);

if ($notifications === []) {
    return;
}
?>
<div class="mtl-toasts" role="status" aria-live="polite">
    <?php foreach ($notifications as $notification): ?>
        <?php
        // Vanilla's notification modifiers: positive, negative, caution,
        // information. Anything else falls back to information.
        $type = in_array($notification['type'], ['positive', 'negative', 'caution', 'information'], true)
            ? $notification['type']
            : 'information';
        ?>
        <div class="p-notification--<?= e($type) ?>" data-mtl-toast>
            <div class="p-notification__content">
                <p class="p-notification__message"><?= e($notification['message']) ?></p>
            </div>
            <button class="p-notification__close" aria-label="<?= e(__('app.close')) ?>" data-mtl-dismiss>
                <?= e(__('app.close')) ?>
            </button>
        </div>
    <?php endforeach; ?>
</div>
