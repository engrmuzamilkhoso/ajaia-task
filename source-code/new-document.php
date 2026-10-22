<?php
require __DIR__ . '/includes/bootstrap.php';

$user = require_login();
require_method('POST');
verify_csrf();

$id = create_document(db(), $user['id']);
redirect('editor.php?id=' . $id);
