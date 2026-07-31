<?php
/**
 * Shown when config/config.php does not exist yet.
 *
 * This is the first page anyone sees after uploading MTL to a fresh Strato
 * account, so it says exactly what to do next rather than reporting a failure.
 * It deliberately depends on nothing: no database, no settings, no session.
 *
 * @var MTL\Core\View $this
 */

declare(strict_types=1);

defined('MTL_APP') || exit;

$writableStorage = is_writable(MTL_ROOT . '/storage');
$hasPdo = extension_loaded('pdo_mysql');
$hasGd = extension_loaded('gd');
$hasMb = extension_loaded('mbstring');

$checks = [
    ['PHP 8.1 or newer', PHP_VERSION_ID >= 80100, PHP_VERSION],
    ['pdo_mysql extension', $hasPdo, $hasPdo ? 'present' : 'missing'],
    ['gd extension (image resizing)', $hasGd, $hasGd ? 'present' : 'missing'],
    ['mbstring extension', $hasMb, $hasMb ? 'present' : 'missing'],
    ['storage/ is writable', $writableStorage, $writableStorage ? 'yes' : 'no — set it to 0775'],
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MTL — setup required</title>
<link rel="stylesheet" href="<?= e(path('/assets/css/mtl.css')) ?>">
</head>
<body>
<main class="mtl-shell mtl-shell--reading">
    <h1>MTL is not configured yet</h1>

    <p>
        The application files are in place, but there is no
        <code>config/config.php</code> yet, so MTL does not know how to reach
        the database.
    </p>

    <h2>What to do</h2>

    <ol class="p-list--divided">
        <li class="p-list__item">
            Copy <code>config/config.example.php</code> to
            <code>config/config.php</code>.
        </li>
        <li class="p-list__item">
            Fill in the database host, name, user and password from the Strato
            control panel, under <em>Databases and web space → Databases</em>.
            The host is usually <code>rdbms.strato.de</code>, not
            <code>localhost</code>.
        </li>
        <li class="p-list__item">
            Set <code>app.key</code> to 32 random bytes, base64 encoded. On a
            shell that is <code>php bin/console.php key:generate</code>; without
            one, any base64 string of at least 32 bytes will do, as long as it
            is kept secret and never changed afterwards.
        </li>
        <li class="p-list__item">
            Reload this page. The web installer takes over from here: it checks
            the server, repairs what it can, creates the tables and asks for
            your first administrator account.
        </li>
    </ol>

    <h2>Server checks</h2>

    <table class="mtl-table">
        <tbody>
        <?php foreach ($checks as [$label, $passed, $detail]): ?>
            <tr>
                <td><?= e($label) ?></td>
                <td>
                    <span class="mtl-status mtl-status--<?= $passed ? 'published' : 'private' ?>">
                        <?= $passed ? 'OK' : 'Attention' ?>
                    </span>
                </td>
                <td class="mtl-muted"><?= e((string) $detail) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <p class="mtl-muted">
        This page disappears as soon as <code>config/config.php</code> exists.
    </p>
</main>
</body>
</html>
