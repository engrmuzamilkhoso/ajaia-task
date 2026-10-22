<?php
/** POST {id}: permanently delete a document (owner only). */
require __DIR__ . '/../includes/bootstrap.php';

require_method('POST');
$user = require_api_user();
verify_csrf();

$input = read_json_body();
$id = require_id($input['id'] ?? null);

delete_document(db(), $id, $user['id']);
json_response(['deleted' => $id]);
