# Ajaia Docs

A lightweight collaborative document editor inspired by Google Docs, built with
plain **PHP 8, MySQL, HTML, CSS and vanilla JavaScript**. There are no frameworks,
no Composer, no npm and no build step.

- Local development runs in **Docker** (`docker compose up`).
- Production runs on **any ordinary PHP + MySQL hosting**. Upload the files and
  enter your database credentials in one file. Nothing needs to be run from a command line.

**Live URL:** https://ajaia-task.toollzen.com/ · **Walkthrough video:** see [VIDEO_URL.txt](VIDEO_URL.txt) ·
**Screenshots:** [screenshots/](screenshots/)

## What's in this folder

```text
README.md          ← this file: setup and run instructions
ARCHITECTURE.md    ← architecture note
AI_WORKFLOW.md     ← AI workflow note
SUBMISSION.md      ← exactly what is included
LIVE_URL.txt       ← live product URL
VIDEO_URL.txt      ← walkthrough video URL
screenshots/       ← screenshots of the running app
source-code/       ← the complete application (this folder is what gets deployed)
```

All commands below are run from inside `source-code/`.

---

## Features

| Area | What works |
|---|---|
| Documents | Create, rename (owner), edit, delete (owner, with confirmation), reopen |
| Rich text | Bold, italic, underline, Heading 1–3, quote, bulleted and numbered lists, clear formatting, undo/redo. Keyboard shortcuts Ctrl+B/I/U/Z/Y and Ctrl+S |
| Autosave | Saves 1 second after you stop typing. Status shows *Unsaved changes*, *Saving…*, *All changes saved*, *Save failed* (with Retry and automatic backoff) or *Conflict*. A save based on an older version is rejected, so it can't overwrite newer content |
| Import | `.txt`, `.md`, `.docx` up to **2 MB**. Each import becomes a new editable document |
| Sharing | The owner shares with other users and can revoke access. Shared users can read and edit. Separate *Owned by me* and *Shared with me* lists with owner and sharing badges |
| Access control | Enforced on the server for every page and endpoint. Unauthorized users get *404 Not found*, and shared users get *403* for owner-only actions |
| Dashboard | Search by title, owner or text; loading skeletons, empty states, error and retry states, toasts |
| Auth | Session login with bcrypt password hashes, CSRF tokens on every state-changing request, a demo account switcher |
| Responsive | Works on desktop and mobile widths |

## Technology stack

- **Backend:** core PHP 8.1+ (tested on 8.3), PDO with prepared statements, PHP sessions
- **Database:** MySQL 8 (MariaDB 10.4+ also works), `utf8mb4`
- **Frontend:** HTML5, one CSS file, vanilla JS, a `contenteditable` editor, inline SVG icons
- **PHP extensions:** `pdo_mysql`, `dom`, `fileinfo`, `json`, and `zip` (only for `.docx` import). All are standard on shared hosting
- **Tests:** a plain PHP CLI test runner (`tests/run-tests.php`); PHPUnit is not needed
- **Local environment:** Docker Compose (`php:8.3-apache` + `mysql:8.0`)

---

## Run locally with Docker

Requires Docker Desktop (or Docker Engine with the Compose plugin).

```bash
cd source-code
docker compose up -d --build
```

Open **http://localhost:8080**.

On the first request the app creates its tables and the demo accounts, so you
don't need to import anything manually. Code changes show up immediately because
the project folder is mounted into the container.

Useful commands:

```bash
docker compose logs -f app                 # PHP / Apache logs
docker compose exec db mysql -uajaia -pajaia_dev_password ajaia   # MySQL shell
docker compose down                        # stop (data is kept in the db_data volume)
docker compose down -v                     # stop and wipe the database
```

MySQL is also exposed on `localhost:3307` (user `ajaia`, password `ajaia_dev_password`)
for GUI clients.

### Run locally without Docker (optional)

With PHP 8.1+ and MySQL installed (for example XAMPP or Laragon):

1. Create an empty database, for example `ajaia`.
2. Copy `source-code/config/config.local.example.php` to `source-code/config/config.local.php` and fill in the credentials.
3. Run `php -S localhost:8000` from inside `source-code/` and open http://localhost:8000.

## Demo accounts

All three use the password **`demo1234`**. The login page has one-click buttons for each.

| Name | Email | Seeded data |
|---|---|---|
| Alice Morgan | `alice@ajaia.demo` | Owns *Welcome to Ajaia Docs* (shared with Bob) and *Q3 planning notes (private)* |
| Bob Chen | `bob@ajaia.demo` | Sees *Welcome to Ajaia Docs* under *Shared with me* |
| Carol Diaz | `carol@ajaia.demo` | No documents, useful as a third person for sharing |

To switch users, open the account menu (top right) and choose **Switch demo account**.

## Run the tests

```bash
docker compose exec app php tests/run-tests.php
```

The runner uses a separate `ajaia_test` database, which Docker creates on the first start
and the runner wipes on every run. It never touches dev or production data. It
covers the permission and document layer directly, then starts PHP's built-in
server against the test database to check the real HTTP endpoints (login,
401/403/404/405/409/422 responses, CSRF, sharing and revoking).

If your database volume was created before `docker/mysql-init/` existed, create
the test database once:

```bash
docker compose exec db mysql -uroot -pajaia_root_password -e "CREATE DATABASE IF NOT EXISTS ajaia_test; GRANT ALL ON ajaia_test.* TO 'ajaia'@'%';"
```

---

## Deploy to PHP hosting (no commands)

Works on typical cPanel, Plesk, Hostinger or DirectAdmin hosting with PHP 8.1+ and MySQL.

1. **Create a MySQL database and user** in your hosting control panel and note
   the host (usually `localhost`), database name, username and password.
2. **Create the config file.** Copy `source-code/config/config.local.example.php` to
   `source-code/config/config.local.php` and fill in those four values. Keep `'debug' => false`.
3. **Upload the contents of `source-code/`** to your web root (for example `public_html/`) or to any
   sub-folder, using the File Manager or FTP. You don't need to upload `docker/`,
   `docker-compose.yml` or `tests/`, but they're harmless: `.htaccess` blocks access to them.
4. **Open the site.** On the first visit the app creates the tables and the
   demo accounts automatically.
5. **Enable HTTPS** (most hosts offer free Let's Encrypt). The session cookie
   is marked `Secure` automatically when the site is served over HTTPS.

If you'd rather create the tables yourself, import `source-code/database/schema.sql` and then
`source-code/database/seed.sql` in phpMyAdmin. The automatic install then has nothing to do.

**Hosting notes**
- The PHP `zip` extension is only needed for `.docx` import. Without it, `.txt` and `.md`
  still work and `.docx` uploads get a clear error message.
- The `.htaccess` files deny web access to `config/`, `includes/`, `database/`,
  `tests/` and `docker/`. On **nginx** hosts, add equivalent `deny` rules.
- Change or remove the demo accounts before using the app for anything real (see Known limitations).

### Configuration reference

`config/config.php` reads environment variables (`DB_HOST`, `DB_PORT`, `DB_NAME`,
`DB_USER`, `DB_PASS`, `APP_DEBUG`). `config/config.local.php` overrides them when present.

| Key | Meaning |
|---|---|
| `db_host`, `db_port`, `db_name`, `db_user`, `db_pass` | MySQL connection |
| `debug` | Show exception messages in responses. Use `false` in production |
| `auto_install` | Create tables and seed data when the database is empty (default `true`) |

`config/config.local.php` is git-ignored, so credentials stay out of source control.

---

## File import: formats and limits

| Format | What's imported |
|---|---|
| `.txt` | Paragraphs (separated by blank lines) and line breaks |
| `.md`, `.markdown` | Headings (`#`–`###`; deeper levels become H3), paragraphs, bulleted and numbered lists, blockquotes, `**bold**`, `*italic*`/`_italic_`. Code blocks become plain paragraphs. Links keep their text and URL as plain text. Nested lists are flattened |
| `.docx` | Paragraphs, Heading 1–6 / Title / Subtitle styles, bulleted and numbered lists, bold, italic and underline. **Not imported:** images, tables (their text comes through as paragraphs), fonts, colours, comments, headers and footers |

- Maximum size: **2 MB**. Empty, unreadable, binary or mislabelled files are rejected with a message.
- Validation checks the extension, the MIME type detected by `fileinfo` (not the browser-supplied type), UTF-8 validity, and that a `.docx` is a real Word ZIP archive.
- Uploaded files are read once and discarded. They are never stored, executed or served.
- Imported content is escaped or converted to HTML and then passed through the same allowlist sanitizer as editor content.

---

## Project structure

```text
source-code/
├── index.php            → redirects to dashboard or login
├── login.php            → sign-in form + demo account buttons
├── logout.php           → POST sign-out / switch account
├── dashboard.php        → document lists, search, import dialog
├── editor.php           → editor page (server-rendered, access-checked)
├── new-document.php     → POST: create blank document, redirect to editor
├── upload.php           → POST: import a file as a new document
├── api/                 → JSON endpoints (see ARCHITECTURE.md)
├── includes/            → bootstrap, auth, db, documents (authorization), sanitizer, importer, layout
├── config/              → config.php + config.local.example.php
├── database/            → schema.sql, seed.sql
├── assets/              → css/style.css, js/*.js, images/favicon.svg
├── tests/run-tests.php  → test suite
└── docker/ + docker-compose.yml → local dev only
```

## Known limitations

- **Demo authentication.** Accounts are seeded with a shared, published password and
  there's no registration, password reset, email verification or MFA. The account
  switcher is for evaluation only. Before any real use, change the passwords or
  replace the seeds, and add proper user management.
- **No real-time co-editing.** Two people editing the same document at once
  don't see each other's cursor. The second person's next save gets a *conflict*
  and a *Reload document* prompt, so neither person's changes are silently overwritten,
  but the edits aren't merged automatically.
- **One permission level** for shared users (*can edit*). There is no viewer or commenter role,
  and no way to transfer ownership.
- The editor uses the browser's `document.execCommand`. It's deprecated but still
  supported by every major browser. Pasting inserts plain text on purpose, so
  formatting from other apps isn't kept.
- There is no version history, comments, images, tables, export or trash/undo for deletes.
- Login rate limiting is per session, not per IP, so it's a basic speed bump and not a defence.
