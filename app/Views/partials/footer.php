<?php
/**
 * Site footer.
 *
 * @var MTL\Core\View $this
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

use MTL\Core\Translator;
use MTL\Services\SettingsService;

$owner = SettingsService::string('site.owner_name');
$available = Translator::available();
?>
<footer class="mtl-footer">
    <ul>
        <li>© <?= e((string) date('Y')) ?><?= $owner === '' ? '' : ' ' . e($owner) ?></li>
        <li><a href="<?= e(path('/trips')) ?>"><?= e(__('nav.trips')) ?></a></li>
        <li><a href="<?= e(path('/albums')) ?>"><?= e(__('nav.albums')) ?></a></li>
        <li>
            <a href="<?= e(path('/feed.xml')) ?>">
                <?= icon('external', 14) ?> RSS
            </a>
        </li>

        <?php if (count($available) > 1): ?>
            <li>
                <?php foreach ($available as $code): ?>
                    <?php // A plain link rather than a select: it works without script and is crawlable. ?>
                    <a href="<?= e(path($this->get('request')?->path ?? '/', ['lang' => $code])) ?>"
                       <?= Translator::locale() === $code ? 'aria-current="true"' : '' ?>>
                        <?= e(strtoupper($code)) ?>
                    </a>
                <?php endforeach; ?>
            </li>
        <?php endif; ?>
    </ul>
</footer>
