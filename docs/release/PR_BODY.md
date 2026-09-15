# Reference Tracker v1.0.0

Personal literature/reference tracker. Tier **T1** (client-grade) — full
pipeline: TDD, docs, E2E, hardening.

**Stack:** Laravel 13.31 · Inertia 2.3 · React 19 · Vite 8 · Tailwind 4 · MariaDB
**Verification:** 163 PHP tests / 524 assertions · 25 Playwright E2E · scanner battery 39/39

---

## Features

- **Auth** — register, login, logout, password reset (Breeze)
- **References** — full CRUD, five types (journal/book/conference/thesis/web)
- **Tags** — create, attach, filter
- **Search** — title, author, year, DOI; case-insensitive; paginated
- **BibTeX import** — tolerant parser; skips duplicates and reports the count; atomic
- **BibTeX export** — preserves imported cite keys, so `\cite{lecun2015}` never breaks
- **DOI lookup** — auto-fills metadata from Crossref, degrades gracefully to manual entry

---

## Security

| Area | Implementation |
|---|---|
| Per-user isolation | `ReferencePolicy` is the single choke point; cross-account access returns 403/404 and is covered by tests |
| Mass assignment | `user_id` is never accepted from the client; set server-side |
| Cross-account tags | `syncOwnedTags()` filters to tags the user owns |
| File upload | content-checked, not extension-checked (a `.bib` full of PHP is rejected) |
| SQL injection | query builder with bound parameters; `LOWER()` applied in SQL, never string-interpolated |
| XSS | React escapes by default; no `dangerouslySetInnerHTML` on user content |
| CSP | per-request nonce; `script-src 'self' 'nonce-…' 'strict-dynamic'` |
| Existence oracle | owner-scoped route binding: a foreign row is 404, indistinguishable from a missing one |
| LIKE injection | `%` and `_` escaped, with an explicit `ESCAPE` clause |
| Rate limits | import 10/min, DOI lookup 30/min; import capped at 2000 entries |
| Proxy awareness | trusted proxies configured so HSTS and the Secure cookie flag actually fire behind a TLS terminator |
| Headers | `nosniff`, `DENY` framing, referrer policy, permissions policy, HSTS over TLS |
| Secrets | fail-closed pre-commit scan; history swept clean |

---

## What testing actually caught

24 real defects, 6 of them in the SOP's own tooling, plus 30 findings from two
independent adversarial reviews (backend + frontend). The three that mattered
most were invisible to a fully green backend suite:

1. **Inertia client 3.7 vs server 2.0** — every page rendered as an empty shell.
2. **`useForm.submit()` ignores a `data` option** — the UI could not save a single
   record, while 72 backend tests passed (they posted the right shape by hand).
3. **Production CSP had no nonce** — the page emits two inline scripts (Ziggy routes,
   Vite prefetch). A browser blocked both; the app would have been dead on arrival
   in production while working perfectly in dev.

Also found and fixed: case-sensitive search, duplicate-DOI 500, non-atomic import,
validation looser than the schema, an unvalidated import path (100 kB authors stored
verbatim), a partial update that wiped tags, and a malformed query string that
returned a 500 with 932 kB of stack trace.

**The independent reviews found 30 more**, including a tag filter that made
saved references appear to vanish, LIKE wildcard injection, an existence oracle,
HSTS never firing behind a proxy, and an unbounded import. All Important *and*
Minor findings are now closed. See `docs/release/REVIEW_NOTES.md`.

---

## Breaking / operational notes

- **`APP_KEY` was rotated.** Any session issued before this release is invalid.
  This is intentional: an `.env.bak` carrying the old key was committed in the
  first commit, and that file has been purged from history. Rotating was the only
  fix that works regardless of whether the old history was ever pushed.
- **Tests require MariaDB.** `pdo_sqlite` is not installed on the development
  machine, so `phpunit.xml` points at `reference_tracker_test`, not SQLite
  `:memory:`. Do not switch it back.
- **E2E uses its own database** (`reference_tracker_e2e`) and runs
  `migrate:fresh` via `globalSetup`. It never touches dev data.

---

## Reviewer checklist

- [ ] `composer install && npm install`
- [ ] create `reference_tracker`, `reference_tracker_test`, `reference_tracker_e2e`
- [ ] `cp .env.example .env && php artisan key:generate`
- [ ] `php artisan migrate`
- [ ] `npm run build`
- [ ] `./vendor/bin/phpunit` → 163 passing
- [ ] `npm run test:e2e` → 25 passing
- [ ] `python3 .githooks/../scan_secrets.py --help` → scanner present
- [ ] `git ls-files | grep -E '^\.env'` → only `.env.example`
- [ ] `APP_ENV=production APP_DEBUG=false php artisan serve` → open in a browser,
      console clean, login form usable

---

## Docs

- `docs/user-guide/README.md` — non-technical, for whoever uses the app
- `docs/runbook/README.md` — setup, architecture, tests, deploy, troubleshooting
- `docs/design-contract.md` — UI intent, required states, banned patterns
- `docs/release/REVIEW_NOTES.md` — what the independent review found
- `SOP_PROGRESS.md` — the full build log, including every defect and ruling
