# SOP Test Run — Reference Tracker

**Tujuan:** uji pertama `web-app-sop` di project nyata (bukan simulasi).
**Tier:** T1 · **Stack:** Laravel 13.31 + Inertia 3.3 + React 19 + Vite 8 + Tailwind 4 + MariaDB
**Lokasi:** `/mnt/data/01_Projects/Porto/reference-tracker`

---

## Progres

| Stage | Status | Catatan |
|---|---|---|
| 0 — Prompt Roast | ✅ | Request di-roast jadi konkret |
| 1 — Brainstorm | ✅ | Tier naik T0→T1 |
| 1.5 — Factory Bootstrap | ✅ | 14 file. **BUG #1** |
| 2 — Anti-Slop Contract | ✅ | `docs/design-contract.md` |
| 2.5 — UI + Feature Checklist | ✅ | Ada di design contract |
| 3 — Spec | ✅ | Design contract = spec |
| 4 — Plan | ✅ | Dikerjakan langsung, solo |
| 5 — Workspace | ✅ | `main`, solo, tanpa worktree |
| 6 — TDD | ✅ **5 cycle** | 72 test, 206 assertions |
| 7 — Execute | ✅ | UI + logic + HTTP lengkap |
| 8 — Review | 🔄 | |
| 9 — Debug | ✅ | 5 bug ketemu & diperbaiki saat integrasi |
| 10 — Prod Hardening | ⬜ | |
| 11 — Docs + E2E | 🔄 | Playwright terpasang |
| 12 — UAT | ⬜ | |
| 13 — Verify | ⬜ | |
| 14 — Ship | ⬜ | |
| 16 — Retro | ⬜ | |

---

## 🔴 BUG NYATA (ketemu karena test di project beneran)

### #1 — Secret scanner false-positive `.htaccess`
**Stage 1.5 · Dampak: SEMUA project Laravel gagal commit.**
`RewriteRule .* - [E=HTTP_X_XSRF_TOKEN:%{HTTP:X-XSRF-Token}]`
Guard `(?<![A-Za-z])` lolos karena `_` bukan huruf; `%{}` tak dianggap placeholder.
**Fix:** guard diperketat + `SKIP_FILE` config non-credential + placeholder `%{}`/`{{}}` + skip `vendor/`, `node_modules/`.

### #2 — Test infra salah default
**Stage 6 · Dampak: semua Feature test error.**
Laravel default pakai SQLite `:memory:`; **`pdo_sqlite` tidak terpasang**.
**Fix:** arahkan ke MariaDB `reference_tracker_test` (DB terpisah dari dev).

### #3 — `breeze:install` itu DESTRUKTIF
**Stage 7 · Dampak: route hilang + downgrade Tailwind.**
- `routes/web.php` di-overwrite → **semua route ReferenceController hilang**
- Tailwind **4 → 3** (downgrade!)
- `package.json` jadi **duplikat**: `react` v18+v19, `plugin-react` v4+v6
- npm gagal (peer conflict) tapi installer bilang "successfully"
**Fix:** restore `web.php`, pertahankan Tailwind 4 (CSS-first `@theme`), dedupe deps, `npm install` ulang → 0 vulnerabilities.

### #4 — Laravel 13 hapus `AuthorizesRequests` dari base Controller
**Stage 7 · Dampak: `$this->authorize()` → "undefined method", semua show/update/delete 500.**
**Fix:** tambahkan trait eksplisit.

### #5 — `BibtexExporter` abaikan `cite_key` tersimpan
**Stage 7 · Dampak: round-trip `.bib` → semua entry GANTI NAMA diam-diam.**
**Fix:** prioritas `override > cite_key tersimpan > derive`.

### #6 — `BibtexParser` berhenti di entry rusak
**Stage 7 · Dampak: satu baris sampah (`@@@garbage@@@`) → entry valid SESUDAHNYA ikut terbuang.**
Scanner menelan `{` milik entry berikutnya.
**Fix:** validasi nama tipe antara `@` dan `{`; lewati hanya token buruk.

### #7 — Scanner false-positive framework code
**Stage 7 · Dampak: commit diblokir 6× oleh `Password::defaults()`, `Hash::make()`, `$this->password = Password::MIN_LENGTH`.**
**Fix:** guard `(?!:)` (jangan baca `::` sebagai assignment) + `is_code()` (RHS berisi `::`, `->`, `()`, `$`, `;` = ekspresi, bukan credential).
**Regresi:** battery 34 kasus disimpan permanen di `project-bootstrap/scripts/test_scan_secrets.py`.

---

## ⚠️ KOREKSI DIRI

**Assertion test salah, bukan kode** (cycle 2 & 4):
- Cycle 2: test assert `'article'` (tipe BibTeX), padahal spec = tipe internal (`journal`). **Test diperbaiki.**
- Cycle 4: fixture pakai DOI `10.1/x` yang ditolak pre-filter format (registrant harus 4-9 digit). **Test diperbaiki**, kode sudah benar.
- Cycle 4: `Http::fake()` di dalam loop **tidak** re-register — stub pertama menang selamanya. **Diverifikasi terhadap framework**, fix pakai satu callback fake.

---

## Keputusan (Ruling)

- **R1** Tier T0→T1 (user). Konsekuensi: docs + Playwright E2E + UAT wajib.
- **R2** MariaDB, bukan SQLite — `pdo_sqlite` tidak ada.
- **R3** Laravel 13.31 (bukan 11).
- **R4** Solo + T1 → kerja di `main`, tanpa worktree.
- **R5** Scanner di-copy ke project, bukan symlink — project self-contained.
- **R6** `BibtexExporter`/`BibtexParser`/`DoiResolver` = logic murni, bisa di-test tanpa DB.
- **R7** Parser toleran: entry rusak dilewati, bukan fatal.
- **R8** Unescape saat import → export escape tepat sekali.
- **R9** Ownership **hanya** di `ReferencePolicy`, bukan di controller.
- **R10** `user_id` **tidak pernah** diterima dari client.
- **R11** Test pakai `withoutVite()` — test tidak boleh bergantung pada `npm run build`.
- **R12** `resources/js/bootstrap.js` sengaja kosong — app pakai `fetch`, bukan axios (hemat ~50 kB).

---

## TDD Progress

| Cycle | Deliverable | Test | Hasil |
|---|---|---|---|
| 1 | `BibtexExporter` | 6 | ✅ |
| 2 | `BibtexParser` | 10 | ✅ round-trip |
| 3 | `Reference`/`Tag` model | 5 | ✅ |
| 4 | `DoiResolver` | 8 | ✅ |
| 5 | HTTP layer + isolasi user | 17 | ✅ |
| + | Breeze auth/profile (bawaan) | 24 | ✅ |
| + | Import security (evil .bib) | 2 | ✅ |
| — | **TOTAL** | **72** | **✅ 206 assertions** |

**Coverage inti:**
- Export: type mapping, escaping `& % $ # _ { }`, author join, cite key stabil, field opsional
- Import: brace-depth splitting, braced/quoted/bare value, `@string` skip, toleransi entry rusak, unescape
- Model: JSON cast, search (title/author/year/doi), pivot, cascade (user→refs hapus, ref↛tags tetap)
- DOI: resolve, 404, 5xx, timeout, payload rusak, format invalid (tanpa network), 7→5 type map, fallback tahun
- HTTP: auth gate, index+search+tag, CRUD, **isolasi antar-user (view/update/delete = 403)**, `user_id` client diabaikan, tag asing difilter, upload `.bib` divalidasi isi, DOI 404

---

## Fitur

| # | Fitur | Status |
|---|---|---|
| 1 | Login / register (Breeze) | ✅ |
| 2 | CRUD referensi | ✅ |
| 3 | Tag + filter | ✅ |
| 4 | Cari (title/author/year/doi) | ✅ |
| 5 | Export BibTeX | ✅ |
| 6 | Import BibTeX | ✅ |
| 7 | DOI fetch (Crossref, network nyata) | ✅ |

---

## Verifikasi HTTP Nyata (bukan cuma unit test)

```
GET  /                    -> 302 -> /references
GET  /references (guest)  -> 302 -> /login
GET  /login               -> 200, Inertia component Auth/Login
POST /register            -> 302 -> /dashboard
GET  /references (auth)   -> 200, component References/Index
POST /references          -> 302, tersimpan
GET  /references?q=...    -> filter benar (1 match / 0 match)
GET  /references/export   -> 200, application/x-bibtex, isi valid
POST /references/import   -> 302, 2 entry dari .bib kotor
POST evil.bib (PHP)       -> DITOLAK, 0 row, error "does not look like BibTeX"
POST /references/doi      -> 200, metadata asli dari Crossref
npm run build             -> sukses, Tailwind 44K CSS, 0 vulnerabilities
```

---

## Commit Log

```
6f6702a feat(ui): references CRUD, BibTeX import/export, DOI lookup
ce63a51 feat(doi): resolve DOIs via Crossref with graceful degradation
b501565 feat(data): references + tags models, migrations, factories
5bcb0a1 feat(bibtex): import/parse BibTeX files
57ac410 feat(bibtex): export references to BibTeX
0448dd2 chore: initial Laravel 13 + factory bootstrap (T1)
```
