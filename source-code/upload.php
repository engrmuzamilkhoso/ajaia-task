<?php
/**
 * File import: validates the upload, converts it to a new document owned by
 * the signed-in user, and opens it in the editor.
 */
require __DIR__ . '/includes/bootstrap.php';

$user = require_login();
require_method('POST');

// A request larger than post_max_size arrives with empty $_POST and $_FILES.
if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    flash('error', 'That file is larger than the 2 MB limit.');
    redirect('dashboard.php');
}

verify_csrf();

try {
    $imported = import_uploaded_file($_FILES['file'] ?? []);
    $id = create_document(db(), $user['id'], $imported['title'], $imported['html']);
} catch (HttpError $e) {
    flash('error', 'Import failed: ' . $e->getMessage());
    redirect('dashboard.php');
}

flash('success', 'Imported “' . $imported['title'] . '”.');
redirect('editor.php?id=' . $id);
