<?php
/** GET: documents owned by and shared with the signed-in user. */
require __DIR__ . '/../includes/bootstrap.php';

require_method('GET');
$user = require_api_user();

json_response(list_documents_for_user(db(), $user['id']));
