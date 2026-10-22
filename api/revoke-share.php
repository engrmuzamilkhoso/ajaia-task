<?php
/** POST {id, user_id}: remove a user's access (owner only). */
require __DIR__ . '/../includes/bootstrap.php';

require_method('POST');
$user = require_api_user();
verify_csrf();

$input = read_json_body();
$id = require_id($input['id'] ?? null);
$targetId = require_id($input['user_id'] ?? null, 'user');

revoke_share(db(), $id, $user['id'], $targetId);
json_response(document_sharing_info(db(), $id, $user['id']));
