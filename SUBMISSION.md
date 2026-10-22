# Submission: Ajaia Docs

## Included

| Requested item | Where it is | Status |
|---|---|---|
| The source code | `source-code/`: PHP pages, `api/`, `includes/`, `assets/`, `database/`, `tests/`, `docker/` | ✅ Complete |
| README.md with local setup and run instructions | `README.md` | ✅ |
| Short architecture note | `ARCHITECTURE.md` | ✅ |
| AI workflow note | `AI_WORKFLOW.md` | ⚠️ Has **[DEVELOPER TO CONFIRM]** items to fill in |
| SUBMISSION.md listing what is included | `SUBMISSION.md` (this file) | ✅ |
| Live product URL | `LIVE_URL.txt` | ⏳ **Pending: not deployed yet** |
| Text file with the walkthrough video URL | `VIDEO_URL.txt` | ⏳ **Pending: not recorded yet** |
| Screenshots | `screenshots/` (8 PNGs captured from the running app) | ✅ |

Inside `source-code/`:

| Part | Location |
|---|---|
| Database schema and seed data (also applied automatically on first request) | `source-code/database/schema.sql`, `source-code/database/seed.sql` |
| Local environment (Docker) | `source-code/docker-compose.yml`, `source-code/docker/` |
| Production config template | `source-code/config/config.local.example.php` |
| Automated tests (33/33 passing) | `source-code/tests/run-tests.php` |

## Demo login

Open the app and click one of the demo accounts on the sign-in page, or sign in manually:

- `alice@ajaia.demo` / `demo1234`: owns a shared document and a private document
- `bob@ajaia.demo` / `demo1234`: has Alice's welcome document under *Shared with me*
- `carol@ajaia.demo` / `demo1234`: starts with nothing

Switch users from the account menu (top right) → **Switch demo account**.

## Verification performed

**Automated:** run `docker compose exec app php tests/run-tests.php` from `source-code/`. The last run passed **33/33**:

- Documents (6): create and save formatted content, the default title, stale save → 409 with no overwrite, sanitize on save, rename with normalization, delete with share cascade
- Sharing and authorization (8): unauthorized users blocked from read, save, rename, delete, share and info (404); a shared user can read and edit; a shared user can't rename, delete, share or revoke (403); revoke removes access; duplicate shares prevented; can't share with yourself or a user who doesn't exist; owned and shared lists kept separate; sharing info
- Validation (3): invalid titles; malformed save payloads; strict id parsing
- Sanitizer (3): formatting kept and normalised; script, style, iframe, svg, comments and forms removed; entities stay inert
- Import (5): `.txt`, `.md`, `.docx` conversion; unsupported types (`.html`, `.exe`, `.php`, no extension) → 415; empty, blank, oversized, binary-as-`.txt` and text-as-`.docx` files rejected
- HTTP (8): 401 for anonymous users with a redirect to login; password check; URL tampering → 404; a shared user can save but gets 403 on rename and share; missing or forged CSRF → 403; 405, 400 and bad ids; share and revoke over HTTP; `config/` and `includes/` contents not exposed

**Manual / browser (headless Chrome against the Docker stack):**
- Login, then create a document, rename it, type, apply bold and Heading 2 → *All changes saved* → reload: the title and formatting are restored
- Share dialog lists the owner and Bob, with Carol offered as a candidate
- Bob sees the shared document and gets a "not found" page for Alice's private document
- Phone width (390 px): no horizontal overflow
- No JavaScript console errors
- Apache returns 403 for `config/`, `includes/`, `database/*.sql`, `tests/`, `*.md`, `docker-compose.yml` and `.gitignore`
- Multipart upload: `.md` → new document opened in the editor; `.html` → "Unsupported file type" message
- Automatic install from an empty database verified (tables dropped, first request recreated them and seeded the data)

## Known incomplete / out of scope

- No live deployment URL yet (manual upload step; see README)
- Walkthrough video not recorded yet
- No real-time co-editing (concurrent edits produce a conflict prompt instead of a merge), single *editor* share role, no version history, comments or export
- Demo-grade authentication (seeded accounts with a published password, no registration or reset)
- No browser end-to-end test committed to the repo (the browser check above was a one-off script that isn't included)

## Walkthrough video outline (3–5 min)

1. **(0:00) Overview.** What Ajaia Docs is and the stack: plain PHP, MySQL and vanilla JS, Docker locally, copy-to-host in production.
2. **(0:30) Create and edit.** Sign in as Alice, create a new document, rename it, then type and use bold, italic, underline, H1/H2 and both list types. Point out *Saving…* → *All changes saved*.
3. **(1:15) Persistence.** Refresh the page: the content and formatting come back. Go back to the dashboard: last-modified time and Owned by me.
4. **(1:40) Import.** Upload a `.md` file: headings and lists are preserved. Try an unsupported file and show the error.
5. **(2:10) Sharing.** Open Share, add Bob, then switch to Bob: the document is under *Shared with me* with *Shared by Alice*. Bob edits the document, and the title field is read-only for him.
6. **(2:50) Access control.** As Bob, paste the URL of Alice's private document: *not found*. Revoke Bob as Alice and show that his access is gone.
7. **(3:20) Architecture.** The `includes/documents.php` authorization layer, 404 vs 403, the HTML allowlist sanitizer, versioned autosave (409 on stale saves), and the automatic install.
8. **(4:00) Scope and AI.** What was left out and why. How Claude Code was used and how the output was verified (the test suite, the browser checks, code review).
