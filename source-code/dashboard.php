<?php
require __DIR__ . '/includes/bootstrap.php';

$user = require_login();
$flash = take_flash();
$pageTitle = 'Documents';
$bodyClass = 'page-dashboard';
require __DIR__ . '/includes/header.php';
?>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <a class="brand" href="<?= e(url('dashboard.php')) ?>">
            <span class="brand-mark" aria-hidden="true"><?= icon('doc') ?></span>
            <span class="brand-name">Ajaia Docs</span>
        </a>

        <form method="post" action="<?= e(url('new-document.php')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary btn-block btn-new"><?= icon('plus') ?> New document</button>
        </form>
        <button type="button" class="btn btn-secondary btn-block" data-open-dialog="import-dialog"><?= icon('upload') ?> Upload &amp; import</button>

        <nav class="side-nav" aria-label="Document lists">
            <a href="#owned" class="side-link" data-filter="owned"><?= icon('doc') ?> Owned by me <span class="count" data-count="owned"></span></a>
            <a href="#shared" class="side-link" data-filter="shared"><?= icon('users') ?> Shared with me <span class="count" data-count="shared"></span></a>
        </nav>

        <div class="sidebar-note">
            <?= icon('cloud', 'icon icon-sm') ?>
            <span>Documents save automatically to the server as you type.</span>
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <label class="search">
                <?= icon('search') ?>
                <span class="visually-hidden">Search documents</span>
                <input type="search" id="doc-search" placeholder="Search by title, owner or text" autocomplete="off">
            </label>
            <?php require __DIR__ . '/includes/account-menu.php'; ?>
        </header>

        <main class="content" id="doc-content">
            <?php if ($flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?>" role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>">
                    <?= icon($flash['type'] === 'error' ? 'alert' : 'check') ?> <?= e($flash['message']) ?>
                </div>
            <?php endif; ?>

            <div class="list-error alert alert-error" id="list-error" hidden>
                <?= icon('alert') ?> <span id="list-error-text">Could not load your documents.</span>
                <button type="button" class="btn btn-link" id="list-retry">Try again</button>
            </div>

            <section class="doc-section" id="owned" aria-labelledby="owned-heading">
                <div class="section-head">
                    <h2 id="owned-heading">Owned by me</h2>
                </div>
                <div class="doc-table" data-list="owned" aria-busy="true">
                    <div class="doc-row doc-row-head" aria-hidden="true">
                        <span>Title</span><span>Owner</span><span>Last modified</span><span>Sharing</span><span></span>
                    </div>
                    <div class="doc-rows"><?php for ($i = 0; $i < 3; $i++): ?><div class="doc-row skeleton"><span></span><span></span><span></span><span></span><span></span></div><?php endfor; ?></div>
                </div>
            </section>

            <section class="doc-section" id="shared" aria-labelledby="shared-heading">
                <div class="section-head">
                    <h2 id="shared-heading">Shared with me</h2>
                </div>
                <div class="doc-table" data-list="shared" aria-busy="true">
                    <div class="doc-row doc-row-head" aria-hidden="true">
                        <span>Title</span><span>Owner</span><span>Last modified</span><span>Sharing</span><span></span>
                    </div>
                    <div class="doc-rows"><?php for ($i = 0; $i < 2; $i++): ?><div class="doc-row skeleton"><span></span><span></span><span></span><span></span><span></span></div><?php endfor; ?></div>
                </div>
            </section>
        </main>
    </div>
</div>

<dialog class="dialog" id="import-dialog" aria-labelledby="import-title">
    <form method="post" action="<?= e(url('upload.php')) ?>" enctype="multipart/form-data" id="import-form">
        <?= csrf_field() ?>
        <header class="dialog-head">
            <h2 id="import-title">Upload &amp; import</h2>
            <button type="button" class="icon-btn" data-close-dialog aria-label="Close"><?= icon('x') ?></button>
        </header>
        <div class="dialog-body">
            <label class="dropzone" id="dropzone">
                <?= icon('upload', 'icon icon-lg') ?>
                <strong id="dropzone-label">Choose a file or drop it here</strong>
                <span class="muted">.txt, .md or .docx · up to 2 MB</span>
                <input type="file" name="file" id="import-file" accept=".txt,.md,.markdown,.docx,text/plain,text/markdown,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required>
            </label>
            <ul class="format-notes">
                <li><strong>.txt</strong> — paragraphs and line breaks</li>
                <li><strong>.md</strong> — headings, lists, quotes, bold and italic</li>
                <li><strong>.docx</strong> — text, headings, lists, bold, italic and underline. Images, tables, fonts and colours are not imported.</li>
            </ul>
            <p class="field-error" id="import-error" role="alert" hidden></p>
        </div>
        <footer class="dialog-foot">
            <button type="button" class="btn btn-secondary" data-close-dialog>Cancel</button>
            <button type="submit" class="btn btn-primary" id="import-submit">Import</button>
        </footer>
    </form>
</dialog>

<dialog class="dialog dialog-sm" id="delete-dialog" aria-labelledby="delete-title">
    <header class="dialog-head">
        <h2 id="delete-title">Delete document?</h2>
        <button type="button" class="icon-btn" data-close-dialog aria-label="Close"><?= icon('x') ?></button>
    </header>
    <div class="dialog-body">
        <p>“<span id="delete-doc-title"></span>” will be permanently deleted for you and everyone it is shared with.</p>
    </div>
    <footer class="dialog-foot">
        <button type="button" class="btn btn-secondary" data-close-dialog>Cancel</button>
        <button type="button" class="btn btn-danger" id="delete-confirm">Delete</button>
    </footer>
</dialog>

<script>window.AJAIA_USER = <?= json_encode(['id' => $user['id'], 'name' => $user['name']], JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<?php
$scripts = ['dashboard.js'];
require __DIR__ . '/includes/footer.php';
