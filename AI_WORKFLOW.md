# AI Workflow

> Items marked **[DEVELOPER TO CONFIRM]** describe things only the developer
> can verify, such as their own review time or any other tools they used. Please
> edit them before submitting.

## AI tools used

- **Claude Code** (Anthropic CLI agent, model Claude Opus, in the VS Code
  extension). It inspected the environment, wrote the code and documentation,
  ran the Docker stack and the test suite, and fixed the failures it found.
- **[DEVELOPER TO CONFIRM]** Any other assistants used (ChatGPT, Copilot and so on), or "none".

## How the work was driven

The developer gave a detailed brief (stack, features, security rules, deliverables)
and one important constraint mid-session: **"use Docker for local dev, but in
production I just want to copy the files and have it run, with no commands."**
That constraint shaped the architecture:

- no Composer packages, so the HTML sanitizer, Markdown converter and DOCX reader
  are written against built-in PHP extensions (`dom`, `zip`, `fileinfo`)
- **automatic install** of the schema and seed data on the first request, instead of migration commands
- configuration through one PHP file (`config/config.local.php`) instead of environment variables
  only

The brief was first written for a Next.js/Prisma stack. The developer switched to
plain PHP/MySQL before any code was written, so no work was thrown away.

## Tasks AI accelerated

- Laying out the project structure, the schema with constraints, and the seed data
- Writing the authorization layer (`includes/documents.php`) and the thin JSON endpoints
- The allowlist sanitizer, the Markdown converter and the DOCX-to-HTML reader
- The UI: login, dashboard, editor, toolbar, share and import dialogs, and responsive CSS
- The autosave state machine (debounce, single in-flight request, version check, retry with backoff)
- A dependency-free PHP test runner with domain-level and HTTP-level tests
- The documentation

## Suggestions changed or rejected

- **Rejected: a Composer sanitizer (HTML Purifier) and a Markdown library (Parsedown).**
  They're good libraries, but they would require Composer or committing `vendor/`,
  which conflicts with copy-only deployment. They were replaced with a small
  `DOMDocument` allowlist that removes *all* attributes, which makes it easier to
  check than a general-purpose purifier, because the editor needs no attributes at all.
- **Rejected: storing rich text as JSON.** HTML plus a strict allowlist suits
  `contenteditable` and needs no client library. The trade-off is documented in ARCHITECTURE.md.
- **Rejected: returning 403 for documents the user can't access.** Changed to 404 so
  ids can't be probed. 403 is kept for shared users trying owner-only actions.
- **Changed: "last write wins" autosave** was replaced with optimistic versioning
  (`UPDATE … WHERE version = ?` → 409), so a delayed request can't overwrite
  newer content.
- **Changed: pasting rich HTML.** Paste inserts plain text, which keeps foreign
  markup out of documents (the server sanitizes regardless).
- **[DEVELOPER TO CONFIRM]** Anything you changed after reviewing the output.

## How correctness and security were verified

- `php -l` on every PHP file, and the full suite (`tests/run-tests.php`) run
  inside the Docker container against an isolated `ajaia_test` database. The latest
  run's actual results are recorded in SUBMISSION.md.
- The tests exercise the security-relevant paths directly. Unauthorized reads and
  writes get 404, shared users trying owner-only actions get 403, missing or forged
  CSRF tokens get 403, wrong methods get 405, malformed JSON gets 400, stale versions
  get 409, and invalid titles and payloads get 422 or 413. The sanitizer is tested against
  XSS payloads (`<script>`, event handlers, `javascript:` links, `<svg onload>`, nested
  `<scr<script>ipt>`), the importer against spoofed or binary uploads, and the `config/`
  and `includes/` files are checked to confirm their contents aren't served.
- Manual checks in the browser through Docker: the login, dashboard, editor, sharing
  and import flows. **[DEVELOPER TO CONFIRM]** your own manual pass before recording.
- Code review against the brief's security list: prepared statements everywhere
  (no SQL concatenation of input), `htmlspecialchars` for all text output, DOM `textContent`
  in JS for user data, session hardening, and `.htaccess` deny rules.

## How UX and reliability were assessed

- Every async action has a loading, success and failure state (skeleton rows,
  *Saving…*, toasts, inline dialog errors, a retry button).
- Autosave failure modes were designed explicitly (offline, 5xx, 401, 404, 409)
  rather than just the happy path.
- Layout checked at desktop and phone widths (sidebar collapses, toolbar scrolls,
  page padding shrinks).

## Where independent engineering judgment was required

- Interpreting "copy and it runs" as automatic install plus no third-party packages, and
  accepting the cost of maintaining a small hand-written sanitizer and parsers.
- Choosing what *not* to build within the timebox (real-time co-editing, roles
  beyond editor, version history) and documenting those limits honestly.
- Deciding that a 409 conflict should stop autosave and ask the user to reload
  instead of silently merging or overwriting.
- **[DEVELOPER TO CONFIRM]** Add your own decisions and review notes here.
