# Ajaia Docs: Submission

A lightweight collaborative document editor inspired by Google Docs, built with
**plain PHP 8, MySQL, HTML, CSS and vanilla JavaScript**. There are no frameworks,
no Composer, no npm and no build step.

| | |
|---|---|
| **Live product** | **https://ajaia-task.toollzen.com/** |
| **Source code** | https://github.com/engrmuzamilkhoso/ajaia-task |
| **Walkthrough video** | https://www.loom.com/share/71077841f58249d4a89141557b41d103 |
| **Demo accounts** | `alice@ajaia.demo`, `bob@ajaia.demo`, `carol@ajaia.demo`; password **`demo1234`** for all |

---

## 1. Try it in 3 minutes (reviewer path)

1. Open https://ajaia-task.toollzen.com/ and click **Alice Morgan → Sign in** under *Demo accounts*.
2. Click **New document**. Rename it in the title field, type some text, and use the toolbar:
   **B / I / U**, *Heading 1–3*, bulleted and numbered lists.
   The status changes from *Saving…* to **All changes saved**.
3. **Refresh the page.** The title and formatting are restored from MySQL.
4. Click **Share**, choose **Bob Chen** and click **Share**. Bob now appears under *People with access*.
5. On the dashboard, click **Upload & import** and choose a `.md`, `.txt` or `.docx` file (max 2 MB).
   It opens as a new editable document. Try a `.pdf` or `.html` file to see it rejected.
6. Open the account menu (top right), choose **Switch demo account**, and sign in as **Bob**.
   - The shared document is listed under **Shared with me**, labelled *Shared by Alice Morgan*.
   - Bob can edit it, but the title is read-only (only the owner can rename).
7. Still as Bob, open **https://ajaia-task.toollzen.com/editor.php?id=2**, which is Alice's private
   document. The server answers **"We couldn't find that"** (HTTP 404).
8. Switch back to Alice, open Share, click **Remove** next to Bob, and Bob loses access immediately.

Seeded data: Alice owns *Welcome to Ajaia Docs* (shared with Bob) and *Q3 planning notes (private)*.
Carol starts with nothing and is useful as a third person.

---

## 2. What is included

| Requested item | Where | Status |
|---|---|---|
| Source code | `source-code/` (GitHub repo above) | ✅ |
| README with local setup and run instructions | `README.md` | ✅ |
| Short architecture note | `ARCHITECTURE.md` | ✅ |
| AI workflow note | `AI_WORKFLOW.md` | ✅ (a few lines marked **[DEVELOPER TO CONFIRM]**) |
| SUBMISSION.md listing what is included | `SUBMISSION.md` (this file) | ✅ |
| Live product URL | `LIVE_URL.txt` → https://ajaia-task.toollzen.com/ | ✅ Deployed and verified |
| Text file with the walkthrough video URL | `VIDEO_URL.txt` → https://www.loom.com/share/71077841f58249d4a89141557b41d103 | ✅ |
| Screenshots | `screenshots/` (8 PNGs from the running app) | ✅ |

Inside `source-code/`:

| Part | Location |
|---|---|
| Application (pages, JSON API, shared logic, assets) | `*.php`, `api/`, `includes/`, `assets/` |
| Database schema and seed data | `database/schema.sql`, `database/seed.sql` |
| Production config template | `config/config.local.example.php` |
| Automated tests | `tests/run-tests.php` |
| Local environment | `docker-compose.yml`, `docker/` |

---

## 3. Features

| Area | Implemented |
|---|---|
| Documents | Create, rename (owner), edit, reopen, delete (owner, with confirmation) |
| Rich text | Bold, italic, underline, Heading 1–3, quote, bulleted and numbered lists, clear formatting, undo/redo, Ctrl+B/I/U/Z/Y, Ctrl+S |
| Autosave | 1 s debounce for both the body and the title; status shows *Unsaved changes*, *Saving…*, *All changes saved*, *Save failed* (Retry + automatic backoff) or *Conflict* |
| Stale-save protection | Each save sends the version it was based on. The server rejects older ones (409) instead of overwriting newer content |
| Import | `.txt`, `.md`, `.docx` up to 2 MB, each becoming a new editable document. Empty, oversized, binary or mislabelled files are rejected with a clear message |
| Sharing | The owner grants or revokes edit access. Separate *Owned by me* and *Shared with me* lists, with owner name, last-modified time and a sharing badge |
| Access control | Enforced on the server for every page and endpoint |
| Dashboard | Search by title, owner or text; loading skeletons; empty, error and retry states; toasts |
| Auth | PHP sessions, bcrypt password hashes, CSRF tokens, demo account switcher |
| Responsive | Desktop and phone layouts (checked at 390 px wide) |

### Import formats

| Format | What is preserved |
|---|---|
| `.txt` | Paragraphs and line breaks |
| `.md` | Headings, paragraphs, bulleted and numbered lists, blockquotes, bold, italic. Links become plain text; nested lists are flattened |
| `.docx` | Paragraphs, Heading/Title styles, bulleted and numbered lists, bold, italic, underline. **Not** images, tables (their text is kept), fonts or colours |

---

## 4. Technology

- **Backend:** core PHP 8.1+ (tested on 8.3), PDO prepared statements, PHP sessions
- **Database:** MySQL 8 / MariaDB 10.4+, `utf8mb4`, InnoDB with foreign keys
- **Frontend:** HTML5, one CSS file, vanilla JS, a `contenteditable` editor, inline SVG icons
- **PHP extensions:** `pdo_mysql`, `dom`, `fileinfo`, `json`, and `zip` (only for `.docx`); all standard on shared hosting
- **Local dev:** Docker Compose (`php:8.3-apache` + `mysql:8.0`)
- **Production:** ordinary PHP + MySQL hosting. **Copy the files and edit one config file**;
  the app creates its tables and demo accounts on the first request.

---

## 5. Run locally

```bash
git clone https://github.com/engrmuzamilkhoso/ajaia-task.git
cd ajaia-task/source-code
docker compose up -d --build          # then open http://localhost:8080
docker compose exec app php tests/run-tests.php
```

Without Docker: create an empty MySQL database, copy `config/config.local.example.php` to
`config/config.local.php` and fill in the credentials, then run `php -S localhost:8000` inside `source-code/`.

### Deploy (how the live site was set up)

1. Create a MySQL database and user in the hosting panel.
2. Upload the contents of `source-code/` to the web root.
3. Create `config/config.local.php` from the example file with the database credentials (`debug => false`).
4. Open the site. Tables and demo accounts are created automatically, and HTTPS marks the session cookie `Secure`.

---

## 6. Architecture (summary; full note in `ARCHITECTURE.md`)

```text
Browser ── pages ──────▶ login.php · dashboard.php · editor.php        (server-rendered)
        ── form POST ──▶ new-document.php · upload.php · logout.php    (redirect after POST)
        ── fetch JSON ─▶ api/*.php                                     (JSON in / JSON out)
                              │
includes/auth.php       session user, CSRF
includes/documents.php  ALL document queries + permission checks
includes/sanitizer.php  allowlist HTML sanitizer (DOMDocument)
includes/importer.php   .txt / .md / .docx → HTML
includes/db.php         PDO + automatic install on an empty database
                              │
MySQL: users · documents · document_shares
```

**Data model**

```text
users(id, name, email UNIQUE, password_hash, created_at)
documents(id, title ≤200, content MEDIUMTEXT, version, owner_id → users, created_at, updated_at)
document_shares(id, document_id → documents, user_id → users, permission 'editor', created_at,
                UNIQUE(document_id, user_id))           -- ON DELETE CASCADE on all foreign keys
```

**Key decisions**

- **Authorization in one place.** Every endpoint is 5–10 lines that call one function in
  `includes/documents.php`, which loads the document *with the session user's id* and enforces the role.
  No endpoint can skip a check.

  | Action | Owner | Shared editor | Anyone else |
  |---|---|---|---|
  | Open / read / save | ✔ | ✔ | 404 |
  | Rename / delete / share / revoke | ✔ | 403 | 404 |

  A user without access gets **404, not 403**, so document ids can't be probed. The user id only ever
  comes from `$_SESSION`; ownership is never taken from the client.
- **Rich text is stored as allowlisted HTML.** Only `p br strong em u h1 h2 h3 ul ol li blockquote`
  survive, with **no attributes at all**. The sanitizer runs on every save, on import, and again on render.
- **Autosave with optimistic concurrency.** One request is in flight at a time, with a follow-up save if typing
  continued. `UPDATE … WHERE version = ?` returns 409 when a newer save exists, and the UI then shows *Reload
  document* instead of silently overwriting.
- **Zero-command deployment.** No third-party packages. Schema and seed data are applied automatically
  (idempotent `CREATE TABLE IF NOT EXISTS` / `INSERT IGNORE`) when the tables are missing.

---

## 7. Security measures

- PDO prepared statements everywhere; no SQL built from input
- Session cookie `HttpOnly`, `SameSite=Lax`, `Secure` over HTTPS, strict mode, ID regenerated on login and logout, 8-hour idle timeout
- CSRF token on every state-changing request (`X-CSRF-Token` header or a hidden form field, compared with `hash_equals`)
- Server-side allowlist HTML sanitizer; paste inserts plain text; all other output is escaped with `htmlspecialchars` or set via DOM `textContent`
- Uploads: extension allowlist, `fileinfo` MIME check, UTF-8 and binary checks, a real ZIP/DOCX check, a 2 MB limit; files are never stored or executed
- `.htaccess` denies web access to `config/`, `includes/`, `database/`, `tests/`, `docker/`, `*.sql`, `*.md` and dotfiles (verified on the live site: 403)
- Generic error messages in production; details go to the server log only
- `X-Content-Type-Options`, `X-Frame-Options: DENY` and `Referrer-Policy` headers

---

## 8. Verification

### Automated tests: `php tests/run-tests.php` → **33/33 passed**

The tests run against an isolated `ajaia_test` database that is wiped on every run.

| Group | Tests |
|---|---|
| Documents (6) | Create and save formatted content and reopen; default title; stale save → 409 with no overwrite; sanitize on save; rename normalization; delete removes shares |
| Sharing and authorization (8) | Unauthorized read, save, rename, delete, share and info → 404; shared user can read and edit; shared user can't rename, delete, share or revoke → 403; revoke removes access; duplicate shares prevented; can't share with yourself or an unknown user; owned vs shared lists; sharing info |
| Validation (3) | Empty, whitespace, too-long, non-string and invalid-UTF-8 titles; malformed save payloads; strict id parsing |
| Sanitizer (3) | Formatting kept and normalised; script, style, iframe, svg, forms and comments removed; entities stay inert |
| Import (5) | `.txt`, `.md` and `.docx` conversion; `.html`, `.exe`, `.php` and extensionless files → 415; empty, oversized, binary-as-`.txt` and text-as-`.docx` rejected |
| HTTP endpoints (8) | Anonymous → 401 or redirect to login; password check; URL tampering → 404; shared user can save but rename and share → 403; missing or forged CSRF → 403; wrong method 405, malformed JSON 400, bad id; share and revoke over HTTP; config and includes contents not served |

### Manual and browser checks

- Headless Chrome against the Docker stack:
  - create, rename, type, apply bold and H2 → *All changes saved* → reload restores the title and formatting
  - share dialog; Bob sees the shared document and is blocked from the private one
  - no horizontal overflow at 390 px; no console errors
- **Live site (https://ajaia-task.toollzen.com/):**
  - `/` redirects to login
  - protected folders and files → 403; API without a session → 401
  - Bob signs in, sees the shared document (200), and is refused Alice's private document (404)
  - secure session cookie; HSTS and security headers present

---

## 9. AI workflow (summary; full note in `AI_WORKFLOW.md`)

- **Tool:** Claude Code (Anthropic's CLI agent, in the VS Code extension). It inspected the environment,
  wrote the code and documentation, ran Docker, the test suite and a headless-browser check, and fixed the failures it found.
- **Developer direction that shaped the design:** "use Docker for local dev, but in production just copy the files,
  no commands." This led to having no Composer packages, a built-in sanitizer, Markdown and DOCX parsers, and an
  automatic database install.
- **Suggestions rejected or changed:** HTML Purifier and Parsedown (they would need Composer); a JSON document
  model (HTML plus an allowlist fits `contenteditable`); 403 for unauthorized access (changed to 404); last-write-wins
  autosave (replaced with versioned saves and 409); rich paste (switched to plain text).
- **Bugs caught by verification and fixed:** the plain-text import added extra newlines (caught by a unit test); the
  title only saved on blur, so refreshing right after a rename lost it (caught by the browser check, and the title now
  autosaves); Chrome inserted `<span style>` while formatting (now cleaned on input); the database
  export failed on import tools that ignore `/*!…*/` comments (rewritten in dependency order).
- **Verification:** the 33 automated tests, the browser checks, checks against the live site, and code review
  against the security requirements.

---

## 10. Scope decisions and known limitations

- **Demo authentication only.** Seeded accounts share a published password; there is no registration, reset or MFA,
  and the account switcher is for evaluation only.
- **No real-time co-editing.** Concurrent editors get a conflict prompt rather than merged changes, so nothing
  is silently overwritten.
- **One share role** (*can edit*); no viewer or commenter role and no ownership transfer.
- No version history, comments, images, tables or export. Pasting inserts plain text.
- The editor uses `document.execCommand`. It's deprecated but supported by all major browsers, and the server
  normalises its output.
- Login throttling is per session, not per IP.

### Next steps with 2–4 more hours

1. A committed Playwright test for the editor (toolbar, autosave, reload)
2. Version history (a `document_revisions` table with restore)
3. A viewer role and share-by-email invitations
4. Per-IP login throttling and a Content Security Policy with nonces
