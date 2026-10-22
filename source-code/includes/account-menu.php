<?php
/** Signed-in user chip with sign-out / switch-account actions. @var array $user */
?>
<details class="account-menu">
    <summary class="account-chip" aria-label="Account menu for <?= e($user['name']) ?>">
        <span class="avatar" aria-hidden="true"><?= e(user_initials($user['name'])) ?></span>
        <span class="account-name"><?= e($user['name']) ?></span>
        <?= icon('chevron', 'icon icon-sm') ?>
    </summary>
    <div class="menu-panel">
        <div class="menu-header">
            <strong><?= e($user['name']) ?></strong>
            <span><?= e($user['email']) ?></span>
        </div>
        <form method="post" action="<?= e(url('logout.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="switch" value="1">
            <button type="submit" class="menu-item"><?= icon('swap') ?> Switch demo account</button>
        </form>
        <form method="post" action="<?= e(url('logout.php')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="menu-item"><?= icon('logout') ?> Sign out</button>
        </form>
    </div>
</details>
