# Architecture

## Overview

Ajaia Docs is a traditional multi-page PHP application. Each `.php` file in the
project root is a page, each file in `api/` is a JSON endpoint, and all shared
logic lives in `includes/`. There is no router, framework or build step, so a
request maps directly to a file.

```text
Browser ──HTML pages──▶ login.php / dashboard.php / editor.php        (server-rendered)
   │   ──form POST────▶ new-document.php / upload.php / logout.php    (redirect after POST)
   │   ──fetch (JSON)─▶ api/*.php                                     (JSON in / JSON out)
   ▼
includes/bootstrap.php → config, error handling, session, security headers
includes/auth.php      → current_user(), require_login(), CSRF
includes/documents.php → all document queries + authorization
includes/sanitizer.php → allowlist HTML sanitizer
includes/importer.php  → .txt / .md / .docx → HTML
includes/db.php        → PDO + automatic install
   ▼
MySQL (users, documents, document_shares)
```

### Request flow (example: autosave)

1. `editor.js` POSTs `{id, content, base_version}` to `api/save-document.php` with
   the `X-CSRF-Token` header.
2. The endpoint calls `require_method('POST')`, then `require_api_user()` (which
   returns 401 if there is no session), then `verify_csrf()` (403), then
   `read_json_body()` (400 or 413).
3. `save_document_content()` loads the document **with the session user's id**.
   It returns 404 if the user is neither the owner nor a shared editor. It then
   validates the payload (422 or 413), sanitizes the HTML, and runs a conditional
   `UPDATE … WHERE version = ?` (409 if the version is stale).
4. The endpoint returns `{ok: true, data: {version, updated_at}}`. Any
   `HttpError` thrown on the way becomes `{ok: false, error: {message}}` with
   its status code, and any other exception is logged and returned as a generic 500.

Endpoints are 5–10 lines each. They only parse input and call one function in
`includes/documents.php`, which is why authorization can't be skipped by accident.

## Database

```text
users(id PK, name, email UNIQUE, password_hash, created_at)

documents(id PK, title VARCHAR(200), content MEDIUMTEXT, version INT,
          owner_id FK→users ON DELETE CASCADE, created_at, updated_at ON UPDATE)
          INDEX(owner_id, updated_at)

document_shares(id PK, document_id FK→documents ON DELETE CASCADE,
                user_id FK→users ON DELETE CASCADE,
                permission ENUM('editor'), created_at)
                UNIQUE(document_id, user_id), INDEX(user_id)
```

- The unique key makes duplicate shares impossible. The app uses `INSERT IGNORE`,
  so granting access twice is an idempotent no-op.
- Cascades mean that deleting a document removes its shares.
- Everything is `utf8mb4` and InnoDB. Timestamps are UTC (`SET time_zone='+00:00'`
  per connection), sent to the browser as ISO 8601, and formatted in the viewer's locale.
- **Install:** `db()` checks for the `document_shares` table. If it's missing, it runs
  `schema.sql` (`CREATE TABLE IF NOT EXISTS`) and `seed.sql` (`INSERT IGNORE`).
  Both are idempotent, so concurrent first requests are safe. This is what
  makes a copy-only deployment work.

## Editor and content representation

- The editor is a `contenteditable` `<article>` with a custom toolbar that calls
  `document.execCommand` (bold, italic, underline, formatBlock h1/h2/h3/blockquote/p,
  lists, removeFormat, undo/redo). `defaultParagraphSeparator` is set to `p`.
- Content is stored as **HTML restricted to an allowlist**: `p br strong em u
  h1 h2 h3 ul ol li blockquote`, **with no attributes at all**. Equivalent tags are
  normalised (`b→strong`, `i→em`, `div→p`, `h4–h6→h3`). Dangerous elements
  (`script`, `style`, `iframe`, `svg`, `img`, `form` and others) are removed
  together with their content. Any other tag is unwrapped, keeping its text.
- The sanitizer (`includes/sanitizer.php`) uses PHP's built-in `DOMDocument` and
  runs **on every save, on every import, and again on render** in `editor.php`.
  The render pass protects against content that was inserted directly into the
  database. Pasting inserts plain text only.
- HTML was chosen over a JSON document model because it maps directly onto
  `contenteditable`, and a strict server-side allowlist keeps it safe. A
  structured model (ProseMirror JSON) would be the next step if real-time
  collaboration is added.

## Autosave

The logic is in `assets/js/editor.js`.

- Each `input` event increments `changeSeq` and (re)starts a **1000 ms debounce**.
- **Only one save request is in flight at a time.** If edits happen while a
  save is in flight, a follow-up save starts as soon as it finishes, so the
  newest content is always sent last.
- **Optimistic concurrency:** each save carries `base_version`. The server
  increments `version` only when it matches (`UPDATE … WHERE id=? AND version=?`).
  A stale or duplicate request, a second tab, or another user's newer save
  gets a **409**. The UI then stops autosaving and shows a *Reload document*
  banner, so newer content is never silently overwritten.
- Failures (network or 5xx errors) keep the edits, show *Save failed* with a **Retry** button,
  and retry automatically with exponential backoff (4 s up to 30 s). A 401 or 404 tells the
  user that their session expired or that access was revoked.
- Ctrl+S saves immediately. *Back to documents* finishes a pending save before
  navigating, and `beforeunload` warns if unsaved changes remain.
- Title rename is a separate owner-only endpoint, triggered on blur or Enter.
  Escape reverts the title.

## Authentication

- PHP sessions use the cookie `ajaia_session` with `HttpOnly`, `SameSite=Lax`, `Secure`
  when the site is served over HTTPS, strict mode, and an 8-hour idle timeout. The
  session ID is regenerated on login and logout.
- Passwords are bcrypt (`password_hash` / `password_verify`). The demo buttons
  on the login page only fill in the real form, and the password is still verified.
- **The user id only ever comes from `$_SESSION`.** No endpoint accepts a
  user id as proof of identity. The `user_id` in share and revoke requests is the
  *target* user, and it's only accepted from the document's owner.
- CSRF: a per-session random token, sent as the `X-CSRF-Token` header by `fetch()`
  and as a hidden `_csrf` field in forms, and compared with `hash_equals`. Every
  POST requires it, including login and logout.

## Authorization and sharing

All rules live in `includes/documents.php`:

| Action | Owner | Shared editor | Anyone else |
|---|---|---|---|
| Open / read / list | ✔ | ✔ | 404 |
| Save content | ✔ | ✔ | 404 |
| Rename, delete | ✔ | 403 | 404 |
| View who has access | ✔ | ✔ (read-only) | 404 |
| Share / revoke | ✔ | 403 | 404 |

- `find_document_for_user()` performs a single join query that returns the
  document only when `owner_id = me` or a share row exists for me. Every other
  operation goes through `require_document($pdo, $id, $userId, $ownerOnly)`.
- A user with no access gets **404, not 403**, so document IDs can't be probed to
  find out whether a document exists.
- Ownership is never taken from client input. `owner_id` is set from the session
  on create, and no endpoint can change it.
- Sharing with yourself (422) and with a user who doesn't exist (404) is rejected.
  Revoking a share that doesn't exist returns 404.

## Error handling

`HttpError(status, message)` carries a safe user-facing message. A global
exception handler turns it into JSON (for `/api/`) or an HTML error page. Any
other exception is written to the server log with file and line, and the user
sees only a generic message (unless `debug` is on). Database connection failures
return 503 without showing the DSN or credentials.

## Main trade-offs

| Decision | Why | Cost |
|---|---|---|
| Plain PHP, no Composer | Deployment is copy-only, it's easy to read, and there's no dependency drift | Hand-written sanitizer, Markdown converter and DOCX parser, each deliberately small |
| HTML content + strict allowlist | Native to `contenteditable`, needs no client library, safe on the server | Less structure than a JSON document model |
| `execCommand` | Zero dependencies, consistent across browsers for this feature set | Deprecated API, with limited control over its output (the sanitizer normalises it) |
| Optimistic locking instead of merging | Simple, and guarantees there are no silent overwrites | Concurrent editors see a conflict instead of merged changes |
| 404 for unauthorized access | Doesn't reveal whether a document exists | Slightly less specific error message |
| Auto-install on first request | No commands needed on hosting | One cheap `SHOW TABLES` query per request |

## Next steps

1. Real-time collaboration: switch to a structured document model (ProseMirror or Yjs) and a WebSocket service, which shared hosting can't provide.
2. Viewer and commenter roles, ownership transfer, and share-by-email invitations.
3. Version history: a `document_revisions` table with snapshots on save, plus a restore option.
4. Proper account management: registration, password reset, and per-IP login throttling.
5. A content security policy with nonces, and moving the remaining inline bootstrap script into a file.
6. Browser end-to-end tests (Playwright) for the editor toolbar and autosave UX.
