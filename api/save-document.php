<?php
/** POST {id, content, base_version}: save document HTML (owner or editor). */
require __DIR__ . '/../includes/bootstrap.php';

require_method('POST');
$user = require_api_user();
verify_csrf();

$input = read_json_body();
$id = require_id($input['id'] ?? null);

json_response(save_document_content(db(), $id, $user['id'], $input['content'] ?? null, $input['base_version'] ?? null));
