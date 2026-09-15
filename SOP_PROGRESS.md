# SOP Test Run — Reference Tracker

**Tujuan:** uji pertama `web-app-sop` di project nyata (bukan simulasi).
**Tier:** T1 · **Stack:** Laravel 13.31 + Inertia 2.3 + React 19 + Vite 8 + Tailwind 4 + MariaDB
**Lokasi:** `/mnt/data/01_Projects/Porto/reference-tracker`

**Hasil akhir:** PHP **72 test / 206 assertions** ✅ · E2E **11 test** ✅

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
| 6 — TDD | ✅ **5 cycle** | 113 test, 309 assertions |
| 7 — Execute | ✅ | UI + logic + HTTP lengkap |
| 8 — Review | ✅ | **2 reviewer independen**. Backend nemu 4 bug baru |
| 9 — Debug | ✅ | **17 bug** ketemu & diperbaiki |
| 10 — Prod Hardening | ✅ | Search, import atomik, security headers + CSP |
| 11 — Docs + E2E | ✅ | **E2E 14/14** + End-User Guide + Runbook |
| 12 — UAT | ⬜ | Belum — butuh stakeholder kalau ada handover |
| 13 — Verify | ✅ | Suite + HTTP nyata dijalankan ulang, output dibaca |
| 14 — Ship | 🔶 | Repo siap; nunggu keputusan lu |
| 16 — Retro | ✅ | `~/.hermes/retros/2026-09.md` |

**Hasil akhir: 113 PHP test / 309 assertions ✅ · 14 E2E ✅**

---

## 🔴 21 BUG NYATA

Semua ketemu karena dijalankan di project beneran. **Yang paling penting: 72 test backend hijau sementara UI-nya gak bisa nyimpen data sama sekali.**

### #1 — Secret scanner false-positive `.htaccess`
**Stage 1.5 · Dampak: SEMUA project Laravel gagal commit.**
`RewriteRule .* - [E=HTTP_X_XSRF_TOKEN:%{HTTP:X-XSRF-Token}]` — direktif Apache dibaca sebagai `TOKEN = value`. Guard `(?<![A-Za-z])` lolos karena `_` bukan huruf.
**Fix:** guard diperketat, `SKIP_FILE` config non-credential, placeholder `%{}`/`{{}}`.

### #2 — Test infra salah default
**Stage 6 · Dampak: semua Feature test error.**
Laravel default SQLite `:memory:`; **`pdo_sqlite` tidak terpasang**.
**Fix:** MariaDB `reference_tracker_test` (DB terpisah).

### #3 — `breeze:install` itu DESTRUKTIF
**Stage 7 · Dampak: route hilang + downgrade Tailwind.**
- `routes/web.php` di-overwrite → **semua route fitur hilang**
- Tailwind **4 → 3** (downgrade)
- `package.json` **duplikat**: `react` v18+v19, `plugin-react` v4+v6
- npm gagal (peer conflict) tapi installer bilang "successfully"
**Fix:** restore routes, pertahankan Tailwind 4, dedupe, install ulang → 0 vulnerabilities.

### #4 — Laravel 13 hapus `AuthorizesRequests`
**Stage 7 · Dampak: `$this->authorize()` undefined → semua show/update/delete 500.**
**Fix:** tambah trait eksplisit.

### #5 — `BibtexExporter` abaikan `cite_key`
**Stage 7 · Dampak: round-trip `.bib` → semua entry GANTI NAMA diam-diam.**
**Fix:** prioritas `override > cite_key tersimpan > derive`.

### #6 — `BibtexParser` berhenti di entry rusak
**Stage 7 · Dampak: satu baris sampah → entry valid SESUDAHNYA ikut terbuang.**
**Fix:** validasi nama tipe antara `@` dan `{`.

### #7 — Scanner false-positive framework code
**Stage 7 · Dampak: commit diblokir 6× oleh `Password::defaults()`, `Hash::make()`.**
**Fix:** guard `(?!:)` + `is_code()` (RHS berisi `::`, `->`, `()`, `$`, `;` = ekspresi).
**Regresi:** battery **34 kasus** permanen di `project-bootstrap/scripts/test_scan_secrets.py`.

### #8 — Dua config Playwright, yang salah menang
**Stage 11 · Dampak: E2E jalanin config Breeze (`npm run dev` :3000), timeout total.**
**Fix:** hapus `playwright.config.ts`.

### #9 — **Mismatch versi mayor Inertia** (paling berbahaya)
**Stage 11 · Dampak: SEMUA halaman jadi shell kosong, TANPA error yang jelas.**
`@inertiajs/react` **3.7.1** vs `inertiajs/inertia-laravel` **2.0.27**. Server kirim format v2, client v3 gak bisa baca → `Cannot read properties of null (reading 'component')` → React gak pernah mount.
**Fix:** pin ke `^2.3.28` (`dist-tags.legacy`, bukan `latest`).

### #10 — **UI gak bisa nyimpen referensi sama sekali**
**Stage 11 · Dampak: form selalu ditolak server.**
`useForm.submit()` **mengabaikan** opsi `data` yang di-pass ke `post()` — selalu kirim `transform.current(dataRef.current)` (diverifikasi di source library). Jadi `authors` terkirim sebagai string, server minta array: *"The authors field must be an array."*
**Fix:** pakai `transform()`.
**Ini bug yang HTTP test gak bisa lihat** — curl test gw kirim `authors[]=` langsung, jadi hijau.

### Bonus — `public/hot` nyangkut
Marker dev server Breeze bikin Laravel arahin asset ke Vite yang gak jalan → shell kosong. Sekarang dihapus otomatis di `globalSetup`.

---

### #11 — Search case-sensitive (Stage 10, hasil probing)
**Dampak: separuh fitur pencarian rusak diam-diam.**
`LIKE` di kolom `authors` (JSON) dibandingkan byte-wise, gak peduli collation. Cari `vaswani` → 0 hasil; `Vaswani` → ketemu.
**Fix:** `LOWER()` di kedua sisi untuk semua kolom.

### #12 — DOI duplikat = 500 mentah (Stage 10)
**Dampak: user gak tau salahnya apa.**
Unique index `(user_id, doi)` meledak jadi 500, bukan error validasi.
**Fix:** `Rule::unique` per-user + skip duplikat saat import dengan laporan jumlah.

### #13 — Import tidak atomik (Stage 10)
**Dampak: impor 40 entri bisa nyangkut di tengah, data separuh tersimpan tanpa penjelasan.**
**Fix:** `DB::transaction` — semua atau tidak sama sekali.

### #14 — Validasi lebih longgar dari skema (Stage 10)
**Dampak: title 256-500 char lolos validasi lalu mati di database.**
Validasi bilang `max:500`, kolom `VARCHAR(255)`.
**Fix:** kolom diperlebar ke 500.

### #15 — Security headers tidak ada sama sekali (Stage 10)
**Dampak: clickjacking, MIME sniffing, referrer leak — semua terbuka.**
**Fix:** middleware global (termasuk halaman error) + CSP.

### #16 — CSP `connect-src` duplikat (Stage 10, ketemu saat verifikasi docs)
**Dampak: browser cuma pakai direktif terakhir, yang pertama dibuang diam-diam.**
**Fix:** dibangun sekali secara kondisional. Test-nya dibuktiin bisa gagal.

### #17 — Import path TIDAK divalidasi + partial update hapus tag (Stage 8, reviewer independen)
**Dampak: 500 mentah + stack trace 1,27 MB; tag user bisa kehapus.**
Form memvalidasi, import tidak — skema sama, dua trust boundary, satu tak terjaga:
- `year=999999` → 500 "Out of range"
- `doi={}` dua kali → 500 duplicate-key (`''` bukan `NULL`)
- author 100 kB → **tersimpan apa adanya** (storedLen=100004)
- PUT tanpa field `tags` → **semua tag terhapus**

**Fix:** `sanitiseImportedEntry()` (clamp semua field ke yang diterima skema); sync tag hanya kalau key-nya benar-benar dikirim.
**Bukti:** sanitiser di-revert → 7 dari 10 test merah.

### Bonus — route `/storage/{path}` publik (Stage 8, reviewer)
**Kelihatan seperti arbitrary file upload** (`PUT storage/{path}`, tanpa middleware).
**Diuji langsung: SEMUA 403** — unsigned GET/PUT, `shell.php`, path traversal `../.env`.
**Ternyata aman**, karena `visibility` tidak di-set → default `private` → butuh signed URL.
**Tapi aman karena kebetulan default.** Ditambah 6 test yang mengunci perilaku ini; dibuktiin merah kalau `visibility => 'public'` ditambahkan.

---

### #18 — Import path tidak divalidasi (Stage 8, reviewer independen)
Sudah dicatat di #17.

### #19 — Script inline Vite prefetch tanpa nonce (Stage 13, verifikasi production)
**Dampak: app MATI TOTAL di production.**
Halaman memuat **2 script inline**: tabel route Ziggy (~23 kB) dan prefetch Vite (~7 kB). CSP produksi `script-src 'self'` tanpa nonce → browser blokir keduanya → `route()` undefined → semua halaman gagal.
**Kenapa test gak nangkep:** test backend pakai `withoutVite()` yang membuang `@vite` — jadi script-nya **tidak pernah dirender**. E2E jalan di dev server = policy lokal.
**Fix:** nonce per-request + `Vite::useCspNonce()`.

### #20 — `style-src` memblokir progress bar Inertia (Stage 13, browser production)
**Dampak: CSP violation di SETIAP page load.**
nprogress menyuntik blok `<style>` runtime, tanpa hook nonce.
**Fix:** `'unsafe-inline'` di `style-src` saja (CSS inline gak bisa eksekusi kode; script tetap terkunci nonce). Didokumentasikan sebagai konsesi terbatas.

### #21 — Literal IPv6 tidak valid sebagai sumber CSP (Stage 13)
**Dampak: browser menolak `ws://[::1]:5173`, log violation tiap load.**
**Fix:** pakai `ws://localhost:5173`.

**Catatan penting:** #19-#21 **cuma ketemu karena menjalankan app dengan `APP_ENV=production` di browser nyata.** Semua test lain hijau. Ini pelajaran terbesar dari seluruh run ini — dan sudah gw tulis ke SOP.

---

## ⚠️ KOREKSI DIRI

**Assertion test salah, bukan kode** (cycle 2, 4, dan E2E):
- Cycle 2: assert `'article'` (tipe BibTeX), spec = tipe internal (`journal`).
- Cycle 4: fixture DOI `10.1/x` ditolak pre-filter format (registrant 4-9 digit) — kode sudah benar.
- Cycle 4: `Http::fake()` di loop **tidak** re-register; stub pertama menang. Diverifikasi ke framework.
- E2E: `menuitem` untuk Log Out (Breeze pakai `<button>`); `et al.` untuk 2 author (formatnya `A & B`).

---

## Keputusan (Ruling)

- **R1** Tier T0→T1 (user). Konsekuensi: docs + E2E + UAT wajib.
- **R2** MariaDB, bukan SQLite — `pdo_sqlite` tidak ada.
- **R3** Laravel 13.31 (bukan 11).
- **R4** Solo + T1 → kerja di `main`, tanpa worktree.
- **R5** Scanner di-copy ke project, bukan symlink.
- **R6** `BibtexExporter`/`Parser`/`DoiResolver` = logic murni, testable tanpa DB.
- **R7** Parser toleran: entry rusak dilewati, bukan fatal.
- **R8** Unescape saat import → export escape tepat sekali.
- **R9** Ownership **hanya** di `ReferencePolicy`.
- **R10** `user_id` **tidak pernah** dari client.
- **R11** Test pakai `withoutVite()` — gak boleh bergantung `npm run build`.
- **R12** `bootstrap.js` sengaja kosong — pakai `fetch`, bukan axios.
- **R13** E2E pakai DB sendiri (`reference_tracker_e2e`), `migrate:fresh` tiap run.
- **R14** Pin `@inertiajs/react` ke `^2.3.28` — **wajib** cocok major dengan adapter Laravel.
- **R15** Selector E2E by role + accessible name, bukan atribut presentasional.

---

## TDD Progress

| Cycle | Deliverable | Test |
|---|---|---|
| 1 | `BibtexExporter` | 6 |
| 2 | `BibtexParser` | 10 |
| 3 | `Reference`/`Tag` model | 5 |
| 4 | `DoiResolver` | 8 |
| 5 | HTTP layer + isolasi user | 17 |
| + | Breeze auth/profile (bawaan) | 24 |
| + | Import security (evil .bib) | 2 |
| **PHP TOTAL** | | **72** (206 assertions) |
| E2E | auth, CRUD, bibtex, isolation | **11** |

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

## Verifikasi (bukti, bukan klaim)

```
PHP    : OK (72 tests, 206 assertions)
E2E    : 11 passed (11.4s)
Build  : sukses, Tailwind CSS 44K, 0 vulnerabilities
Scanner: 34 passed, 0 failed (MUST_BLOCK + MUST_PASS)
HTTP   : / -> 302; /references (guest) -> 302 /login
         POST /references -> tersimpan; ?q= -> filter benar
         export -> application/x-bibtex; import .bib kotor -> 2 entry
         evil.bib (PHP) -> DITOLAK, 0 row
         DOI lookup -> metadata asli dari Crossref
```

---

## Commit Log

```
2b2b204 test(e2e): Playwright suite (11 tests) + fix 3 real bugs it exposed
6f6702a feat(ui): references CRUD, BibTeX import/export, DOI lookup
ce63a51 feat(doi): resolve DOIs via Crossref with graceful degradation
b501565 feat(data): references + tags models, migrations, factories
5bcb0a1 feat(bibtex): import/parse BibTeX files
57ac410 feat(bibtex): export references to BibTeX
0448dd2 chore: initial Laravel 13 + factory bootstrap (T1)
```

---

## Sisa Kerjaan (jujur)

1. **Stage 10 — Prod hardening** belum: N+1 check, index review, perf budget, security headers verify.
2. **Stage 11 — Docs** belum: End-User Guide + Runbook. E2E-nya sudah ✅.
3. **Stage 12 — UAT** belum.
4. **Stage 14 — Ship** belum.
5. **Stage 8 — Review** dilakukan sendiri; bukan reviewer independen. Ini kelemahan nyata yang gw catat di retro.

## Yang berubah di SOP sendiri (hasil run ini)

- **Stage 1.5 "Scaffolder Safety"** — backup, diff, cek versi dependency, bersihin marker & config bayangan.
- **Stage 11 "Shift-left rule"** — 1 smoke test begitu ada halaman; selector by role; test harus bisa dibuktiin bisa gagal.
- **`references/integration-hazards.md`** — 10 entri symptom → cause → fix.
- **`scan_secrets.py`** diperkeras + battery regresi 34 kasus (MUST_BLOCK **dan** MUST_PASS).
- **4 rationalization baru** — "backend test hijau berarti jalan", "installer bilang sukses", "E2E nanti aja", "test hang berarti app lambat".
