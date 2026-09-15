# SOP Test Run — Reference Tracker

**Tujuan:** uji pertama `web-app-sop` di project nyata (bukan simulasi).
**Tier:** T1 · **Stack:** Laravel 13.31 + Inertia 3.3 + React 19 + Vite 8 + Tailwind 4 + MariaDB
**Lokasi:** `/mnt/data/01_Projects/Porto/reference-tracker`

---

## Progres

| Stage | Status | Catatan |
|---|---|---|
| 0 — Prompt Roast | ✅ | Request di-roast jadi konkret |
| 1 — Brainstorm | ✅ | Design disetujui, tier naik T0→T1 |
| 1.5 — Factory Bootstrap | ✅ | 14 file. **BUG #1 ketemu** |
| 2 — Anti-Slop Contract | ✅ | `docs/design-contract.md` |
| 2.5 — UI + Feature Checklist | ✅ | Ada di design contract |
| 3 — Spec | ✅ | Design contract jadi spec (T1 kecil) |
| 4 — Plan | ✅ | Plan in-flight, dikerjakan langsung |
| 5 — Workspace | ✅ | main branch, solo |
| 6 — TDD | 🔄 **3 cycle selesai** | 22 test, 59 assertions |
| 7 — Execute | 🔄 | Models + logic selesai; UI belum |
| 8 — Review | ⬜ | |
| 9 — Debug | ⬜ | |
| 10 — Prod Hardening | ⬜ | |
| 11 — Docs + E2E | ⬜ | Playwright terpasang, belum ditulis |
| 12 — UAT | ⬜ | |
| 13 — Verify | ⬜ | |
| 14 — Ship | ⬜ | |
| 16 — Retro | ⬜ | |

---

## 🔴 BUG NYATA #1 — scanner false-positive di `.htaccess` Laravel

**Stage:** 1.5 · **Dampak:** SEMUA project Laravel gagal commit.

`.htaccess` baris 14: `RewriteRule .* - [E=HTTP_X_XSRF_TOKEN:%{HTTP:X-XSRF-Token}]`

Dua cacat `scan_secrets.py`:
1. Guard `(?<![A-Za-z])` lolos di `XSRF_TOKEN` (underscore bukan huruf)
2. `%{...}` sintaks Apache tidak dianggap placeholder

**Fix:** guard diperketat, `SKIP_FILE` untuk config non-credential, placeholder `%{}`/`{{}}`, skip `vendor/`+`node_modules/`.
**Verifikasi:** 15/15 battery test.

**Pelajaran:** scanner di-test di `/tmp` kosong → LOLOS. Baru ketahuan salah waktu kena project nyata. **Test harus di project beneran.**

---

## 🔴 BUG NYATA #2 — test infrastructure salah default

**Stage:** 6 · **Dampak:** semua Feature test error.

Laravel default `phpunit.xml` pakai SQLite `:memory:`, tapi **`pdo_sqlite` tidak terpasang** di mesin ini.

**Fix:** arahkan test ke MariaDB `reference_tracker_test` (dibuat terpisah dari dev DB).

**Pelajaran:** asumsi default framework bisa salah di environment nyata. Test infra harus diverifikasi sebelum nulis test.

---

## ⚠️ KOREKSI DIRI — assertion test salah, bukan kode

**Stage:** 6, cycle 2.

Test `test_parses_a_single_entry` assert `'article'` (tipe BibTeX mentah). Padahal spec-nya: parser menghasilkan **tipe internal** (`journal`), biar export bisa map balik `journal → article`.

**Yang benar:** perbaiki **test**, bukan kode. Kode sudah sesuai spec.
Ditambah `test_round_trips_through_export_and_import` sebagai jaring.

**Pelajaran:** test yang gagal belum tentu kode salah. Cek spec dulu.

---

## Keputusan (Ruling)

- **R1** Tier T0→T1 (user). Konsekuensi: docs + Playwright E2E + UAT wajib.
- **R2** MariaDB, bukan SQLite — `pdo_sqlite` tidak ada.
- **R3** Laravel 13.31 (bukan 11) — versi terbaru dari create-project.
- **R4** Solo + T1 → kerja di `main`, tanpa worktree.
- **R5** Scanner di-sync ke project (bukan symlink) — project self-contained.
- **R6** `BibtexExporter`/`BibtexParser` = pure logic tanpa Eloquent → bisa di-test tanpa DB.
- **R7** Parser toleran: entry rusak di-skip, bukan fatal (kehilangan 1 ref > tolak seluruh import).
- **R8** Unescape saat import → export escape tepat sekali (bukan dobel).

---

## TDD Progress

| Cycle | Deliverable | Test | Hasil |
|---|---|---|---|
| 1 | `BibtexExporter` | 6 | ✅ 19 assertions |
| 2 | `BibtexParser` | 10 | ✅ + round-trip |
| 3 | `Reference`/`Tag` models | 5 | ✅ |
| — | **TOTAL** | **22** | **✅ 59 assertions** |

**Yang di-cover test:**
- Export: type mapping, escaping `& % $ # _ { }`, author join, stable cite key, optional field omission
- Import: brace-depth splitting, braced/quoted/bare values, `@string` skip, malformed tolerance, unescape
- Model: JSON cast, search (title/author/year), pivot, cascade delete (user→refs, ref↛tags)

---

## Fitur

1. Login (satu akun) — ⬜
2. CRUD referensi — 🔄 model siap, controller+UI belum
3. Tag + filter — 🔄 model siap
4. Cari — ✅ scope siap + tested
5. Export BibTeX — ✅ logic siap + tested
6. Import BibTeX — ✅ logic siap + tested
7. DOI fetch (Crossref) — ⬜

---

## Commit Log

```
b501565 feat(data): references + tags models, migrations, factories
5bcb0a1 feat(bibtex): import/parse BibTeX files
57ac410 feat(bibtex): export references to BibTeX
0448dd2 chore: initial Laravel 13 + factory bootstrap (T1)
```
