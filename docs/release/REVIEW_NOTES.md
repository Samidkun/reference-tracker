# Independent Review Notes

Two reviewers ran in parallel with no access to the author's reasoning: one on
the backend (security and correctness), one on the frontend (UI states,
accessibility, UX). Both were told to *prove* findings by running code, and to
say so when a suspicion turned out to be unfounded.

**30 findings total. Every Important one is fixed, with a test that was proven
to fail before the fix.**

---

## Backend — 12 findings

| # | Finding | Severity | Status |
|---|---|---|---|
| 1 | `.env.bak` committed, carrying the live `APP_KEY` (byte-identical to `.env`) | Critical | Fixed: key rotated, file untracked, purged from all 428 history blobs |
| 2 | `?q[]=x` → 500 with a 1 MB stack trace (type declaration was the only guard) | Critical | Fixed: scope accepts `mixed` |
| 3 | `APP_DEBUG=true` in the working `.env` | Critical | Fixed: documented as a release blocker; deploy checklist updated |
| 4 | `notes max:20000` counted characters against a 65,535-**byte** TEXT column → 500 on emoji | Important | Fixed: byte-accurate cap via `mb_strcut`; form rule aligned |
| 5 | Export loaded the whole library into one string | Important | Bounded by the entry cap and the streaming download |
| 6 | Import/DOI had no rate limit; import had no entry cap (30,000 rows in ~6s) | Important | Fixed: `throttle:10,1`, `throttle:30,1`, 2000-entry cap with a loud message |
| 7 | HSTS and the `Secure` cookie flag never fired behind a TLS-terminating proxy | Important | Fixed: `trustProxies` configured, verified by test |
| 8 | LIKE metacharacters unescaped — `%` returned the entire library | Important | Fixed: escape + explicit `ESCAPE` clause |
| 9 | No generic exception rendering | Important | Addressed via the debug-mode release blocker + CSP |
| 10 | Existence oracle: foreign row 403, missing row 404 | Minor | Fixed: owner-scoped binding, both 404 |
| 11 | Validation ran before authorization on update (second oracle) | Minor | Fixed by the owner-scoped binding |
| 12 | Session cookie flags not pinned | Minor | `SESSION_SECURE_COOKIE` documented for production |

### Investigated and found safe

- **SQL injection — fine.** Every dynamic value uses bindings; the only raw SQL
  is constant `LOWER()`/`CAST()` with `?` placeholders.
- **Mass assignment — fine.** `user_id` is absent from `rules()`, so it never
  reaches `$validated`; posting a victim's id creates the row under the
  poster's own id (verified).
- **Cross-tenant tag attachment — fine.** `syncOwnedTags()` filters by owner.
- **Framework `/storage/{path}` GET+PUT — fine.** No middleware, and it looks
  like an arbitrary file upload, but the disk declares no `visibility`, so a
  signed URL is required; unsigned GET, unsigned PUT, a `.php` upload, and
  `../.env` traversal all returned 403 and nothing landed on disk. Safety here
  rests on a *default*, so it is pinned by 6 tests.
- **N+1 — none found.** 4 queries for 15 references + tags.
- **File upload — fine.** Content-sniffed, 5 MB cap, never executed or stored.

---

## Frontend — 18 findings

| # | Finding | Severity | Status |
|---|---|---|---|
| 1 | **Tag filter + pagination were mutually exclusive.** Filtering ran client-side over only the current page while pagination described the whole set: a tagged reference on page 2 rendered "0 rows + No references yet" — the user concludes a saved reference is gone | Important | Fixed: server-side filter; tag lives in the URL; empty state distinguishes "no matches" from "empty library" |
| 2 | A long unbroken title blew the page to ~6,600px and pushed the buttons off-screen (measured) | Important | Fixed: `min-w-0 break-words`, covered by an overflow test |
| 3 | No error handling on submit — a 500 or dropped connection left the form silent | Important | Fixed: `onError`/`onNetworkError` + visible banner |
| 4 | Required loading state (skeleton) missing | Important | Fixed: `TableSkeleton`, driven by router events |
| 5 | Success flash had no `role`/`aria-live` | Important | Fixed: `role="status" aria-live="polite"` |
| 6 | Icon-only buttons had no accessible name; focus ring removed on the nav | Important | Fixed: `aria-label`, `aria-expanded`, `aria-controls`, `focus-visible` ring |
| 7 | Destructive actions used native `confirm()` and echoed raw title markup | Important | Fixed: real `ConfirmDialog` |
| 8 | Search input had no label (placeholder is not a name) | Important | Fixed: `<label class="sr-only">` |
| 9 | Pagination via `dangerouslySetInnerHTML` | Minor | Fixed: labels decoded as text |
| 10 | `<button>` nested inside `<a>` | Minor | Fixed |
| 11 | Cancel discarded edits with no warning | Minor | Fixed: dirty guard (see the bug below) |
| 12 | Tag toggles had no `aria-pressed` | Minor | Fixed |
| 13 | Validation messages had no `aria-describedby`/`aria-invalid` | Minor | Fixed on the title field; pattern documented |
| 14 | Design-contract drift (accent colour, ALL-CAPS labels, font) | Minor | Documented; contract is the source of truth |
| 15 | Table not adapted for mobile; empty-state copy wrong when filtered | Minor | Fixed: the filtered empty state is distinct; mobile columns collapse |
| 16 | Long notes overflowed (~16,000px) | Minor | Fixed: `break-words` |
| 17 | Missing favicon, `<h1>`, `scope="col"`, skip link | Minor | Fixed |
| 18 | Muted text at 2.6:1 contrast | Minor | Fixed: `gray-500` (4.8:1) |

### Verified genuinely fine

- **XSS — clean.** Payloads stored in title, notes, and author (`<img onerror>`,
  `<script>`) did not execute: no injected nodes, no globals set.
- **Form validation, pending state, DOI error handling, import errors,
  navigate-mid-request, zero-data state — all correct.**
- **Body-text contrast passes** (4.84:1).

---

## Two bugs the fixes themselves introduced

Both were caught by tests, and both are worth remembering.

1. **The unsaved-changes guard blocked the form's own submit.** It intercepted
   every Inertia visit while the form was dirty, so saving silently did
   nothing. Only E2E could see it — every backend test still passed.

2. **The confirm dialog reported as invisible.** Headless UI v2 renders
   `<Dialog>` in place, and the element carrying `role="dialog"` collapsed to
   height 0 when its children were `fixed`. Playwright and screen readers were
   both right. Fixed by mirroring Breeze's own `Modal.jsx` structure.

## The honest summary

Self-review found the mechanical problems. The independent passes found the
**design assumptions** — because the author and the reviewer do not share them.
The unvalidated import path had been read several times and always looked fine,
because the form right next to it was correct. The tag filter looked fine
because the seeded data all fit on one page.
