<?php
/** POST {id, user_id}: grant a user edit access (owner only). */
require __DIR__ . '/../includes/bootstrap.php';

require_method('POST');
$user = require_api_user();
verify_csrf();

$input = read_json_body();
$id = require_id($input['id'] ?? null);
$targetId = require_id($input['user_id'] ?? null, 'user');

$created = share_document(db(), $id, $user['id'], $targetId);
json_response(document_sharing_info(db(), $id, $user['id']), $created ? 201 : 200);
