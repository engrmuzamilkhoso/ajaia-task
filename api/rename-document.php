<?php
/** POST {id, title}: rename a document (owner only). */
require __DIR__ . '/../includes/bootstrap.php';

require_method('POST');
$user = require_api_user();
verify_csrf();

$input = read_json_body();
$id = require_id($input['id'] ?? null);

json_response(['title' => rename_document(db(), $id, $user['id'], $input['title'] ?? null)]);
