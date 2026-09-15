# SOP Test Run — Reference Tracker

**Tujuan:** uji pertama `web-app-sop` di project nyata (bukan simulasi).
**Tier:** T1 (client-grade) · **Stack:** Laravel 13 + Inertia + React + Vite + MariaDB
**Lokasi:** `/mnt/data/01_Projects/Porto/reference-tracker`

---

## Progres per Stage

| Stage | Status | Catatan |
|---|---|---|
| 0 — Prompt Roast | ✅ | Request "test sop buat project" di-roast: vague, tujuan meta. Dipaksa jadi konkret (project + tier + stack + fitur). |
| 1 — Brainstorm | ✅ | Design disetujui. Scope naik dari usulan awal (login + import + DOI). |
| 1.5 — Factory Bootstrap | ✅ | 14 file dibuat. **1 bug scanner ketemu & dibenerin.** |
| 2 — Anti-Slop | ⬜ | |
| 2.5 — UI + Feature Checklist | ⬜ | |
| 3 — Spec | ⬜ | |
| 4 — Plan | ⬜ | |
| 5 — Workspace | ✅ | Sudah di main branch (T1, solo, tidak pakai worktree terpisah) |
| 6 — TDD | ⬜ | |
| 7 — Execute | ⬜ | |
| 8 — Review | ⬜ | |
| 9 — Debug | ⬜ | |
| 10 — Prod Hardening | ⬜ | |
| 11 — Docs + E2E | ⬜ | |
| 12 — UAT | ⬜ | |
| 13 — Verify | ⬜ | |
| 14 — Ship | ⬜ | |
| 16 — Retro | ⬜ | |

---

## 🔴 BUG NYATA #1 — scanner false-positive di `.htaccess`

**Ditemukan:** Stage 1.5, waktu commit pertama.
**Gejala:** commit normal **DIBLOKIR**. Semua project Laravel gak bisa commit.
**Penyebab:** `public/.htaccess` baris 14:
```apache
RewriteRule .* - [E=HTTP_X_XSRF_TOKEN:%{HTTP:X-XSRF-Token}]
```
Dua cacat di `scan_secrets.py`:
1. Guard `(?<![A-Za-z])` lolos di `XSRF_TOKEN` — underscore bukan huruf
2. `%{...}` (sintaks Apache) tidak masuk daftar placeholder

**Perbaikan:**
- Guard diperketat: `(?<![A-Za-z0-9])` ... `(?![A-Za-z0-9_])`
- Tambah `SKIP_FILE` untuk config non-credential (`.htaccess`, `web.config`, `nginx.conf`, dll)
- Placeholder tambah `%{...}` dan `{{...}}`
- Skip `vendor/`, `node_modules/`, `.githooks/`

**Verifikasi:** battery test 15/15 pass (8 harus blokir, 6 harus lolos, 1 `.htaccess` nyata).

**Pelajaran:** gate yang di-test di `/tmp` kosong **lolos**. Baru ketahuan salah waktu kena project nyata. Ini bukti kenapa test harus di project beneran.

---

## Keputusan (Ruling)

- **R1:** Tier dinaikkan T0 → T1 oleh user. Konsekuensi: docs + Playwright E2E + UAT jadi wajib.
- **R2:** Database MariaDB (bukan SQLite) — `pdo_sqlite` tidak terpasang di mesin ini.
- **R3:** Laravel 13.31 (bukan 11) — `composer create-project` mengambil versi terbaru.
- **R4:** Solo + T1, kerja langsung di `main`. Tidak buat worktree terpisah (tidak ada kolaborator yang perlu dilindungi).
- **R5:** Scanner di project di-sync dari skill (bukan symlink) supaya project self-contained.

---

## Fitur yang Disetujui

1. Login (satu akun)
2. CRUD referensi (judul, penulis, tahun, tipe, DOI, URL, catatan)
3. Tag + filter by tag
4. Cari by judul/penulis/tahun
5. Export BibTeX (semua / pilihan)
6. Import BibTeX (upload, parse, preview, konfirmasi)
7. DOI auto-fetch via Crossref

## TDD Fokus (logic yang bisa salah)

- BibTeX **export**: mapping tipe, escaping (`& % $ # _ { }`), format penulis
- BibTeX **import**: parser, entry rusak, dedupe
- DOI fetch: parsing Crossref, handle not-found / API down
- Search: kombinasi tag + teks + tahun
