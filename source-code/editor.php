<?php
require __DIR__ . '/includes/bootstrap.php';

$user = require_login();
$id = parse_id($_GET['id'] ?? null);
if ($id === null) {
    throw new HttpError(404, 'That document link is not valid.');
}
$doc = require_document(db(), $id, $user['id']);
$isOwner = $doc['role'] === 'owner';
$flash = take_flash();

$pageTitle = $doc['title'];
$bodyClass = 'page-editor';
require __DIR__ . '/includes/header.php';
?>
<div class="editor-shell"
     id="editor-app"
     data-doc-id="<?= $doc['id'] ?>"
     data-version="<?= $doc['version'] ?>"
     data-user-id="<?= $user['id'] ?>"
     data-role="<?= e($doc['role']) ?>">

    <header class="editor-topbar">
        <a class="icon-btn" href="<?= e(url('dashboard.php')) ?>" aria-label="Back to documents" title="Back to documents"><?= icon('back') ?></a>
        <span class="brand-mark brand-mark-sm" aria-hidden="true"><?= icon('doc') ?></span>

        <div class="title-block">
            <input type="text"
                   id="doc-title"
                   class="title-input"
                   value="<?= e($doc['title']) ?>"
                   maxlength="<?= DOCUMENT_TITLE_MAX ?>"
                   aria-label="Document title"
                   <?= $isOwner ? '' : 'readonly title="Only the owner can rename this document"' ?>>
            <div class="title-meta">
                <?php if ($isOwner): ?>
                    <span class="badge badge-owner">Owner</span>
                <?php else: ?>
                    <span class="badge badge-shared">Shared by <?= e($doc['owner_name']) ?></span>
                <?php endif; ?>
                <span class="save-status" id="save-status" role="status" aria-live="polite" data-state="saved">
                    <span class="save-dot" aria-hidden="true"></span>
                    <span class="save-text">All changes saved</span>
                </span>
                <button type="button" class="btn btn-link btn-xs" id="save-retry" hidden>Retry</button>
            </div>
        </div>

        <div class="topbar-actions">
            <button type="button" class="btn btn-primary" id="share-button"><?= icon($isOwner ? 'share' : 'users') ?> <span><?= $isOwner ? 'Share' : 'People' ?></span></button>
            <?php require __DIR__ . '/includes/account-menu.php'; ?>
        </div>
    </header>

    <div class="toolbar" role="toolbar" aria-label="Formatting" aria-controls="doc-editor">
        <button type="button" class="tool" data-command="undo" title="Undo (Ctrl+Z)" aria-label="Undo"><?= icon('undo') ?></button>
        <button type="button" class="tool" data-command="redo" title="Redo (Ctrl+Y)" aria-label="Redo"><?= icon('redo') ?></button>
        <span class="tool-sep" aria-hidden="true"></span>
        <label class="visually-hidden" for="block-style">Text style</label>
        <select id="block-style" class="tool-select" title="Text style">
            <option value="p">Normal text</option>
            <option value="h1">Heading 1</option>
            <option value="h2">Heading 2</option>
            <option value="h3">Heading 3</option>
            <option value="blockquote">Quote</option>
        </select>
        <span class="tool-sep" aria-hidden="true"></span>
        <button type="button" class="tool" data-command="bold" title="Bold (Ctrl+B)" aria-label="Bold" aria-pressed="false"><?= icon('bold') ?></button>
        <button type="button" class="tool" data-command="italic" title="Italic (Ctrl+I)" aria-label="Italic" aria-pressed="false"><?= icon('italic') ?></button>
        <button type="button" class="tool" data-command="underline" title="Underline (Ctrl+U)" aria-label="Underline" aria-pressed="false"><?= icon('underline') ?></button>
        <span class="tool-sep" aria-hidden="true"></span>
        <button type="button" class="tool" data-command="insertUnorderedList" title="Bulleted list" aria-label="Bulleted list" aria-pressed="false"><?= icon('ul') ?></button>
        <button type="button" class="tool" data-command="insertOrderedList" title="Numbered list" aria-label="Numbered list" aria-pressed="false"><?= icon('ol') ?></button>
        <span class="tool-sep" aria-hidden="true"></span>
        <button type="button" class="tool" data-command="removeFormat" title="Clear formatting" aria-label="Clear formatting"><?= icon('clear') ?></button>
    </div>

    <?php if ($flash): ?>
        <div class="editor-flash alert alert-<?= e($flash['type']) ?>" role="status"><?= icon('check') ?> <?= e($flash['message']) ?></div>
    <?php endif; ?>
    <div class="alert alert-error editor-banner" id="conflict-banner" role="alert" hidden>
        <?= icon('alert') ?>
        <span id="conflict-text">This document was changed somewhere else. Reload to get the latest version — your unsaved edits here will be discarded.</span>
        <button type="button" class="btn btn-secondary btn-xs" id="conflict-reload">Reload document</button>
    </div>

    <main class="canvas">
        <article class="page"
                 id="doc-editor"
                 contenteditable="true"
                 spellcheck="true"
                 role="textbox"
                 aria-multiline="true"
                 aria-label="Document body"
                 data-placeholder="Start writing…"><?= sanitize_html($doc['content']) ?></article>
    </main>
</div>

<dialog class="dialog" id="share-dialog" aria-labelledby="share-title">
    <header class="dialog-head">
        <h2 id="share-title">Share “<span id="share-doc-title"><?= e($doc['title']) ?></span>”</h2>
        <button type="button" class="icon-btn" data-close-dialog aria-label="Close"><?= icon('x') ?></button>
    </header>
    <div class="dialog-body">
        <form class="share-form" id="share-form" <?= $isOwner ? '' : 'hidden' ?>>
            <label class="visually-hidden" for="share-user">Person to share with</label>
            <select id="share-user" required></select>
            <span class="permission-pill">Can edit</span>
            <button type="submit" class="btn btn-primary" id="share-submit">Share</button>
        </form>
        <p class="muted share-note" id="share-readonly-note" <?= $isOwner ? 'hidden' : '' ?>>
            Only <?= e($doc['owner_name']) ?> (the owner) can change who has access.
        </p>
        <p class="field-error" id="share-error" role="alert" hidden></p>

        <h3 class="people-heading">People with access</h3>
        <ul class="people-list" id="people-list" aria-busy="true">
            <li class="people-loading muted">Loading…</li>
        </ul>
    </div>
    <footer class="dialog-foot">
        <button type="button" class="btn btn-secondary" data-close-dialog>Done</button>
    </footer>
</dialog>
<?php
$scripts = ['editor.js', 'sharing.js'];
require __DIR__ . '/includes/footer.php';
