<?php
/**
 * Document data access and authorization.
 *
 * Every function takes the acting user's id (always from the session) and
 * enforces permissions itself, so no endpoint can forget a check:
 *   owner  -> read, edit, rename, delete, manage sharing
 *   editor -> read, edit (via a document_shares row)
 *   anyone else -> 404, so private document ids are not even confirmed.
 */
declare(strict_types=1);

const DOCUMENT_TITLE_MAX = 200;
const DOCUMENT_CONTENT_MAX_BYTES = 1_000_000;
const DEFAULT_DOCUMENT_TITLE = 'Untitled document';

function normalize_title($title): string
{
    if (!is_string($title)) {
        throw new HttpError(422, 'Title must be text.');
    }
    if (!preg_match('//u', $title)) {
        throw new HttpError(422, 'Title contains invalid characters.');
    }
    $title = preg_replace('/[\x00-\x1F\x7F]/u', '', $title);
    $title = trim((string) preg_replace('/\s+/u', ' ', (string) $title));
    if ($title === '') {
        throw new HttpError(422, 'Title cannot be empty.');
    }
    if (utf8_length($title) > DOCUMENT_TITLE_MAX) {
        throw new HttpError(422, 'Title must be ' . DOCUMENT_TITLE_MAX . ' characters or fewer.');
    }
    return $title;
}

function normalize_content($content): string
{
    if (!is_string($content)) {
        throw new HttpError(422, 'Content must be an HTML string.');
    }
    if (strlen($content) > DOCUMENT_CONTENT_MAX_BYTES) {
        throw new HttpError(413, 'This document is too large to save (1 MB limit).');
    }
    if (!preg_match('//u', $content)) {
        throw new HttpError(422, 'Content contains invalid characters.');
    }
    return sanitize_html($content);
}

/** The document plus the user's role, or null when the user has no access. */
function find_document_for_user(PDO $pdo, int $documentId, int $userId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT d.id, d.title, d.content, d.version, d.owner_id, d.created_at, d.updated_at,
                u.name AS owner_name, u.email AS owner_email,
                IF(d.owner_id = ?, \'owner\', \'editor\') AS role
           FROM documents d
           JOIN users u ON u.id = d.owner_id
      LEFT JOIN document_shares s ON s.document_id = d.id AND s.user_id = ?
          WHERE d.id = ? AND (d.owner_id = ? OR s.id IS NOT NULL)'
    );
    $stmt->execute([$userId, $userId, $documentId, $userId]);
    $doc = $stmt->fetch();
    if (!$doc) {
        return null;
    }
    $doc['id'] = (int) $doc['id'];
    $doc['owner_id'] = (int) $doc['owner_id'];
    $doc['version'] = (int) $doc['version'];
    return $doc;
}

/** Load a document the user may access, optionally requiring ownership. */
function require_document(PDO $pdo, int $documentId, int $userId, bool $ownerOnly = false): array
{
    $doc = find_document_for_user($pdo, $documentId, $userId);
    if ($doc === null) {
        throw new HttpError(404, 'Document not found, or you do not have access to it.');
    }
    if ($ownerOnly && $doc['role'] !== 'owner') {
        throw new HttpError(403, 'Only the document owner can do that.');
    }
    return $doc;
}

function create_document(PDO $pdo, int $ownerId, string $title = DEFAULT_DOCUMENT_TITLE, string $html = ''): int
{
    $stmt = $pdo->prepare('INSERT INTO documents (title, content, owner_id) VALUES (?, ?, ?)');
    $stmt->execute([normalize_title($title), normalize_content($html), $ownerId]);
    return (int) $pdo->lastInsertId();
}

/**
 * Save content using optimistic concurrency: the client sends the version it
 * started from, and the update only applies if nobody saved in between.
 */
function save_document_content(PDO $pdo, int $documentId, int $userId, $content, $baseVersion): array
{
    require_document($pdo, $documentId, $userId);
    if (!is_int($baseVersion) || $baseVersion < 1) {
        throw new HttpError(422, 'A valid base_version is required.');
    }
    $clean = normalize_content($content);

    $stmt = $pdo->prepare('UPDATE documents SET content = ?, version = version + 1 WHERE id = ? AND version = ?');
    $stmt->execute([$clean, $documentId, $baseVersion]);
    if ($stmt->rowCount() === 0) {
        throw new HttpError(409, 'This document was changed somewhere else (another tab or another person). Reload to get the latest version.');
    }

    $stmt = $pdo->prepare('SELECT version, updated_at FROM documents WHERE id = ?');
    $stmt->execute([$documentId]);
    $row = $stmt->fetch();
    return ['version' => (int) $row['version'], 'updated_at' => iso_time($row['updated_at'])];
}

function rename_document(PDO $pdo, int $documentId, int $userId, $title): string
{
    require_document($pdo, $documentId, $userId, true);
    $title = normalize_title($title);
    $pdo->prepare('UPDATE documents SET title = ? WHERE id = ?')->execute([$title, $documentId]);
    return $title;
}

function delete_document(PDO $pdo, int $documentId, int $userId): void
{
    require_document($pdo, $documentId, $userId, true);
    $pdo->prepare('DELETE FROM documents WHERE id = ? AND owner_id = ?')->execute([$documentId, $userId]);
}

/** Grant editor access. Returns false if the user already had access. */
function share_document(PDO $pdo, int $documentId, int $ownerId, int $targetUserId): bool
{
    $doc = require_document($pdo, $documentId, $ownerId, true);
    if ($targetUserId === $doc['owner_id']) {
        throw new HttpError(422, 'You already own this document.');
    }
    $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ?');
    $stmt->execute([$targetUserId]);
    if (!$stmt->fetch()) {
        throw new HttpError(404, 'That user does not exist.');
    }
    $stmt = $pdo->prepare("INSERT IGNORE INTO document_shares (document_id, user_id, permission) VALUES (?, ?, 'editor')");
    $stmt->execute([$documentId, $targetUserId]);
    return $stmt->rowCount() === 1;
}

function revoke_share(PDO $pdo, int $documentId, int $ownerId, int $targetUserId): void
{
    require_document($pdo, $documentId, $ownerId, true);
    $stmt = $pdo->prepare('DELETE FROM document_shares WHERE document_id = ? AND user_id = ?');
    $stmt->execute([$documentId, $targetUserId]);
    if ($stmt->rowCount() === 0) {
        throw new HttpError(404, 'That person does not have access to this document.');
    }
}

/** Who can access a document, plus (for the owner) who it can be shared with. */
function document_sharing_info(PDO $pdo, int $documentId, int $userId): array
{
    $doc = require_document($pdo, $documentId, $userId);

    $stmt = $pdo->prepare(
        'SELECT u.id, u.name, u.email, s.permission, s.created_at
           FROM document_shares s JOIN users u ON u.id = s.user_id
          WHERE s.document_id = ? ORDER BY u.name'
    );
    $stmt->execute([$documentId]);
    $shares = array_map(fn ($row) => [
        'user_id'    => (int) $row['id'],
        'name'       => $row['name'],
        'email'      => $row['email'],
        'permission' => $row['permission'],
        'shared_at'  => iso_time($row['created_at']),
    ], $stmt->fetchAll());

    $candidates = [];
    if ($doc['role'] === 'owner') {
        $stmt = $pdo->prepare(
            'SELECT u.id, u.name, u.email FROM users u
              WHERE u.id <> ?
                AND NOT EXISTS (SELECT 1 FROM document_shares s WHERE s.document_id = ? AND s.user_id = u.id)
              ORDER BY u.name'
        );
        $stmt->execute([$doc['owner_id'], $documentId]);
        $candidates = array_map(fn ($row) => [
            'user_id' => (int) $row['id'],
            'name'    => $row['name'],
            'email'   => $row['email'],
        ], $stmt->fetchAll());
    }

    return [
        'document_id' => $documentId,
        'can_manage'  => $doc['role'] === 'owner',
        'owner'       => ['user_id' => $doc['owner_id'], 'name' => $doc['owner_name'], 'email' => $doc['owner_email']],
        'shares'      => $shares,
        'candidates'  => $candidates,
    ];
}

/** Documents the user owns and documents shared with them. */
function list_documents_for_user(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT d.id, d.title, d.content, d.updated_at,
                (SELECT COUNT(*) FROM document_shares s WHERE s.document_id = d.id) AS share_count
           FROM documents d
          WHERE d.owner_id = ?
       ORDER BY d.updated_at DESC, d.id DESC'
    );
    $stmt->execute([$userId]);
    $owned = array_map(fn ($row) => [
        'id'          => (int) $row['id'],
        'title'       => $row['title'],
        'snippet'     => mb_substr_safe(html_to_text($row['content']), 140),
        'updated_at'  => iso_time($row['updated_at']),
        'share_count' => (int) $row['share_count'],
        'role'        => 'owner',
    ], $stmt->fetchAll());

    $stmt = $pdo->prepare(
        'SELECT d.id, d.title, d.content, d.updated_at, u.name AS owner_name, u.email AS owner_email
           FROM document_shares s
           JOIN documents d ON d.id = s.document_id
           JOIN users u ON u.id = d.owner_id
          WHERE s.user_id = ?
       ORDER BY d.updated_at DESC, d.id DESC'
    );
    $stmt->execute([$userId]);
    $shared = array_map(fn ($row) => [
        'id'          => (int) $row['id'],
        'title'       => $row['title'],
        'snippet'     => mb_substr_safe(html_to_text($row['content']), 140),
        'updated_at'  => iso_time($row['updated_at']),
        'owner_name'  => $row['owner_name'],
        'owner_email' => $row['owner_email'],
        'role'        => 'editor',
    ], $stmt->fetchAll());

    return ['owned' => $owned, 'shared' => $shared];
}

/** All users, for the account switcher on the login screen. */
function list_demo_users(PDO $pdo): array
{
    return $pdo->query('SELECT id, name, email FROM users ORDER BY id LIMIT 10')->fetchAll();
}
