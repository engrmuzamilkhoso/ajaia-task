<?php
/** GET ?id=: owner, people with access, and (for the owner) who else it can be shared with. */
require __DIR__ . '/../includes/bootstrap.php';

require_method('GET');
$user = require_api_user();
$id = require_id($_GET['id'] ?? null);

json_response(document_sharing_info(db(), $id, $user['id']));
