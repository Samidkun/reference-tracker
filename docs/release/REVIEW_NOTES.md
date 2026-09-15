# Independent Review Notes

Two reviewers ran in parallel with no access to the author's reasoning:
one on the backend (security and correctness), one on the frontend
(UI states, accessibility, UX). Both were instructed to *prove* findings by
running code, and to say so when a suspicion turned out to be unfounded.

## Backend — 4 real defects found

The author had already reviewed this code and considered it clean. A fresh
adversarial reader found:

| # | Defect | Severity | Status |
|---|---|---|---|
| 1 | Import path had **no validation at all** — same schema as the form, two entry points, one guarded. A `.bib` could store a 100 kB author list verbatim, or 500 on an out-of-range year / empty DOI. | Critical | Fixed, 10 tests |
| 2 | `PUT` without a `tags` key **wiped every tag**. The form always sends the field, so the UI hid it; any other client would destroy data by omitting one key. | Critical | Fixed, 3 tests |
| 3 | DOI uniqueness was **case-sensitive** in the form but lowercased in the import, so the same paper could be saved twice. | Important | Fixed, 1 test |
| 4 | `?q[]=x` → **HTTP 500 with 932 kB of stack trace** (SQLSTATE, vendor paths). A malformed URL broke the page and leaked internals. | Important | Fixed, 20 tests |

### Investigated and found safe

A framework-registered route pair (`GET`/`PUT storage/{path}`) had **empty
middleware** and looked like an arbitrary file upload. Probed over real HTTP:
unsigned GET, unsigned PUT, uploading a `.php` file, and `../.env` traversal
all returned 403, and nothing landed on disk. It is safe because the disk does
not declare `visibility => public`, so a signed URL is required.

That safety rests on a default, so it is now pinned by 6 tests: adding
`'visibility' => 'public'` — the plausible "make uploads easier" change —
turns 3 of them red immediately.

## Frontend

Reviewed against the project's own design contract (`docs/design-contract.md`),
which lists required states, banned patterns, and the accessibility bar.
Findings folded into the UI work; no outstanding blockers.

## The honest summary

Self-review found the mechanical problems. The independent pass found the
**design assumptions** — because the author and the reviewer do not share them.
The unvalidated import path is the clearest example: it had been read several
times and always looked fine, because the form right next to it was correct.
