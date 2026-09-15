# Reference Tracker — Developer Runbook

Untuk programmer yang memelihara aplikasi ini. Untuk panduan pemakaian, lihat
[End-User Guide](../user-guide/README.md).

---

## 1. Apa ini

Aplikasi pelacak referensi ilmiah. Satu akun = satu koleksi referensi pribadi.

| Lapisan | Teknologi |
|---|---|
| Backend | Laravel 13.31, PHP 8.5 |
| Frontend | React 19 + Inertia 2.3 (bukan SPA terpisah, bukan API) |
| Build | Vite 8, Tailwind 4 (CSS-first) |
| Database | MariaDB 12.3 |
| Test | PHPUnit 12 (backend), Playwright 1.63 (E2E) |

**Tier: T1 (client-grade).** Artinya: docs + E2E wajib, sesuai `web-app-sop`.

---

## 2. Setup dari nol

### Prasyarat

- PHP 8.3+ dengan ekstensi **pdo_mysql**
- Composer
- Node 20+ dan npm
- MariaDB 10.6+

> ⚠️ **`pdo_sqlite` TIDAK terpasang di mesin pengembangan asli.** Karena itu
> aplikasi ini memakai MariaDB, dan test pun memakai MariaDB — bukan SQLite
> in-memory seperti default Laravel. Jangan ubah `phpunit.xml` kembali ke
> SQLite; test akan gagal dengan *"could not find driver"*.

### Langkah

```bash
git clone <repo> reference-tracker
cd reference-tracker

composer install
npm install

cp .env.example .env
php artisan key:generate
```

Buat dua database — satu untuk pengembangan, satu khusus test:

```bash
mariadb -u root -e "CREATE DATABASE reference_tracker      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mariadb -u root -e "CREATE DATABASE reference_tracker_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mariadb -u root -e "CREATE DATABASE reference_tracker_e2e  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

Isi `.env` (bagian yang penting):

```dotenv
APP_NAME="Reference Tracker"
APP_ENV=local
APP_DEBUG=true

DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=reference_tracker
DB_USERNAME=root
DB_PASSWORD=
```

Migrasi dan jalankan:

```bash
php artisan migrate
npm run build          # atau: npm run dev (lihat catatan di bawah)
php artisan serve
```

Buka <http://127.0.0.1:8000>.

### ⚠️ Jebakan: `public/hot`

`npm run dev` membuat file `public/hot`. Selama file itu ada, Laravel
mengarahkan URL asset ke Vite dev server. **Kalau dev server mati sementara
`public/hot` masih ada, aplikasi menyajikan halaman kosong tanpa pesan error
apa pun** — React tidak pernah dimuat.

Solusinya: hapus `public/hot`, atau jalankan `npm run build`. File itu sudah
di-gitignore, dan `tests/e2e/global-setup.js` menghapusnya otomatis sebelum
setiap run E2E.

---

## 3. Menjalankan test

```bash
# Backend — 84 test, 245 assertion
./vendor/bin/phpunit

# Satu file saja
./vendor/bin/phpunit tests/Unit/BibtexExporterTest.php

# E2E — 11 test (Playwright mengurus server + DB-nya sendiri)
npm run test:e2e
```

**E2E memakai database sendiri** (`reference_tracker_e2e`) dan menjalankan
`migrate:fresh` setiap kali lewat `tests/e2e/global-setup.js`. Jadi E2E tidak
akan pernah menyentuh data pengembanganmu.

Kalau E2E gagal, buka snapshot halaman yang tersimpan:

```
test-results/<nama-test>/error-context.md
```

File itu berisi *accessibility tree* halaman saat gagal — cara tercepat tahu
apakah elemennya benar-benar tidak ada atau selektornya yang salah.

---

## 4. Peta arsitektur

```
app/
  Http/
    Controllers/ReferenceController.php   <- semua endpoint referensi
    Controllers/ProfileController.php     <- profil (dari Breeze)
    Requests/StoreReferenceRequest.php    <- validasi + aturan unik DOI
    Requests/ImportBibtexRequest.php      <- validasi upload .bib
    Middleware/SecurityHeaders.php        <- header keamanan + CSP
    Middleware/HandleInertiaRequests.php  <- share props (auth, flash)
  Models/Reference.php                    <- model + scopeSearch
  Models/Tag.php
  Policies/ReferencePolicy.php            <- SATU-SATUNYA tempat aturan kepemilikan
  Support/BibtexExporter.php              <- logic murni, tanpa DB
  Support/BibtexParser.php                <- logic murni, tanpa DB
  Support/DoiResolver.php                 <- panggilan Crossref, logic murni

resources/js/
  app.jsx                                 <- entry Inertia
  Pages/References/{Index,Form,Show}.jsx  <- halaman fitur utama
  Layouts/, Components/                   <- dari Breeze

tests/
  Unit/                                   <- BibTeX + DOI (tanpa DB)
  Feature/                                <- HTTP, model, keamanan
  e2e/                                    <- Playwright, alur user nyata
```

### Aturan penting yang mudah dilanggar

1. **Kepemilikan HANYA di `ReferencePolicy`.** Jangan tambahkan cek
   `user_id === auth()->id()` di controller. Satu tempat, satu jawaban.
2. **`user_id` tidak pernah diterima dari client.** Tidak ada di `rules()`,
   jadi tidak akan pernah masuk `$validated`. Di-set server-side.
3. **`Support/*` adalah logic murni.** Tanpa Eloquent, tanpa IO (kecuali
   `DoiResolver` yang memang memanggil HTTP). Ini yang membuatnya bisa
   di-test tanpa database.
4. **Validasi tidak boleh lebih longgar dari skema.** `title` `max:500`
   dan kolomnya `VARCHAR(500)` harus selalu sama. Ketidakcocokan = error
   database yang tidak tertangani.
5. **Import harus atomik.** Dibungkus `DB::transaction`. Kalau gagal di
   tengah, seluruhnya di-rollback.

---

## 5. Alur data

### Membuat referensi

```
Form.jsx  --useForm().transform()-->  POST /references
                                        |
                              StoreReferenceRequest (validasi + DOI unik)
                                        |
                              ReferenceController@store
                                        |  user_id = Auth::id()  (server-side)
                                        v
                              Reference::create()
```

> **Catatan `transform()`:** `useForm.submit()` **mengabaikan** opsi `data`
> yang di-pass ke `post()`/`put()`. Ia selalu mengirim
> `transform.current(dataRef.current)`. Karena itu `authors` (textarea
> multi-baris) diubah jadi array lewat `transform()`, bukan lewat argumen
> `post()`. Ini pernah jadi bug: server menolak dengan *"The authors field
> must be an array."*

### Import `.bib`

```
upload .bib -> ImportBibtexRequest (validasi isi, bukan cuma ekstensi)
            -> BibtexParser::parse()   (toleran: entri rusak dilewati)
            -> DB::transaction {
                 skip DOI yang sudah ada (library + file yang sama)
                 Reference::create() per entri
               }
            -> flash: "Imported N reference(s). Skipped M duplicate(s)."
```

### DOI lookup

```
Form.jsx --fetch()--> POST /references/doi
                        -> DoiResolver::resolve()
                        -> https://api.crossref.org/works/<doi>
                        -> 200 + JSON  |  404 kalau gagal
```

`DoiResolver` **tidak pernah throw** dan **tidak pernah** mengembalikan
objek parsial. Semua kegagalan (format salah, 404, 5xx, timeout, payload
aneh) jadi `null`, dan UI menampilkan "isi manual saja".

---

## 6. Deploy

Aplikasi belum pernah di-deploy. Kalau mau:

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build

php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

**Wajib sebelum deploy:**

- [ ] `APP_ENV=production`, `APP_DEBUG=false`
- [ ] `APP_KEY` di-set (bukan default)
- [ ] Kredensial DB asli (bukan root tanpa password)
- [ ] HTTPS aktif — HSTS hanya dikirim kalau request sudah secure
- [ ] `npm run build` dijalankan (manifest Vite harus ada)
- [ ] `public/hot` **tidak ada**
- [ ] Suite E2E dijalankan terhadap hasil build

**Rollback:** deploy sebelumnya disimpan, `git revert` commit rilis,
`composer install`, `npm run build`, lalu `php artisan migrate:rollback` kalau
migrasi terakhir ikut dirilis.

---

## 7. Troubleshooting

| Gejala | Penyebab | Solusi |
|---|---|---|
| Halaman **kosong**, HTTP 200, tanpa error | `public/hot` ada tapi Vite mati | `rm public/hot` atau `npm run build` |
| Halaman kosong, error `reading 'component'` | Versi Inertia client vs server beda mayor | Pastikan `@inertiajs/react` **^2.3** cocok dengan `inertiajs/inertia-laravel` **^2.0** |
| Test: `could not find driver` | `phpunit.xml` kembali ke SQLite | Pakai MariaDB; `pdo_sqlite` tidak terpasang |
| Test: `Unknown database 'reference_tracker_test'` | DB test belum dibuat | Lihat langkah setup di atas |
| E2E timeout di `webServer` | Port 8124 masih dipakai run sebelumnya | `pkill -f 'artisan serve --port=8124'` |
| E2E: `waiting for locator` | Selektor salah, bukan app lambat | Cek `test-results/*/error-context.md` |
| Commit ditolak "possible secret" | False positive scanner | Baca `.githooks/scan_secrets.py`; jalankan battery-nya |
| Asset 404 setelah deploy | Manifest Vite tidak ada | `npm run build` di server |
| Halaman produksi **kosong** padahal lokal normal | CSP memblokir script inline (Ziggy / Vite prefetch) | Pastikan nonce ada di `script-src` dan `@routes(null, $cspNonce)` |
| Console: *"invalid source: ws://[::1]:5173"* | Literal IPv6 dalam tanda kurung tidak valid di CSP | Pakai `ws://localhost:5173` |

### Verifikasi cepat bahwa app sehat

```bash
curl -sI http://127.0.0.1:8000/login | grep -iE "x-frame|content-security|nosniff"
# harus muncul X-Frame-Options, Content-Security-Policy, X-Content-Type-Options

curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8000/login   # 200
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8000/references  # 302 -> /login
```

---

## 8. Keamanan

| Lapisan | Implementasi |
|---|---|
| Kepemilikan data | `ReferencePolicy` (view/update/delete) — diuji, termasuk upaya akses lintas akun |
| Mass assignment | `user_id` tidak ada di `rules()`, di-set server-side |
| Tag lintas akun | `syncOwnedTags()` memfilter hanya tag milik user |
| Upload file | `ImportBibtexRequest` memeriksa **isi** file, bukan cuma ekstensi |
| SQL injection | Query builder + binding; `LOWER()` di sisi SQL, nilai selalu di-bind |
| Secret | Pre-commit hook + scanner bawaan, **fail-closed** |
| Header | `SecurityHeaders` (global, termasuk halaman error) + CSP nonce |
| CSRF | Middleware Laravel standar; `fetch()` membaca `<meta name="csrf-token">` |
| DOI | Timeout 8 detik, tidak pernah throw, semua kegagalan → `null` |

### CSP — kenapa ada `'unsafe-inline'` di style tapi tidak di script

Halaman ini memuat **dua script inline**: tabel route Ziggy (~23 kB, dari
`@routes`) dan helper prefetch Vite (~7 kB, dari `@vite`). Kalau CSP memblokir
keduanya, `route()` jadi undefined dan **seluruh aplikasi mati** — tapi cuma di
production, karena policy lokal mengizinkan `'unsafe-inline'` untuk Vite.

Solusinya **nonce per-request**, bukan `'unsafe-inline'`:
- `SecurityHeaders` membuat nonce acak per request.
- Nonce diteruskan ke `@routes(null, $cspNonce)` dan ke Vite lewat
  `Vite::useCspNonce($nonce)` — API resmi Laravel.
- `script-src` jadi `'self' 'nonce-...' 'strict-dynamic'`.

`style-src` **tetap** butuh `'unsafe-inline'`, karena progress bar Inertia
(nprogress) menyuntik blok `<style>` saat runtime dan tidak menyediakan hook
nonce. Ini konsesi yang disengaja dan terbatas: **CSS inline tidak bisa
menjalankan kode**, sementara script tetap terkunci ke nonce. Perbedaan ini
diuji eksplisit di `CspInlineScriptTest`.

**Bukti verifikasi:** dijalankan di browser nyata dengan `APP_ENV=production` —
0 CSP violation, 0 console error, Ziggy ter-load. Saat fix dilepas, 2 violation
muncul kembali.

**Bukan cakupan saat ini:** 2FA, rate limiting login, verifikasi email wajib,
audit log, enkripsi at-rest. Tambahkan kalau tier naik ke T2.

---

## 9. Alur kerja kontribusi

1. Buat branch.
2. **TDD: tulis test yang gagal dulu.** Ini aturan tetap, bukan saran.
3. Implementasi minimal sampai test hijau.
4. `./vendor/bin/phpunit` dan `npm run test:e2e` harus hijau.
5. Commit gaya conventional (`feat:`, `fix:`, `test:`, `docs:`, `chore:`).
6. PR.

**Definition of done (T1):** test hijau, E2E hijau, docs diperbarui kalau
perilaku berubah, tidak ada TODO yang menggantung.

---

## 10. Keputusan arsitektur (ADR ringkas)

| Keputusan | Alasan |
|---|---|
| MariaDB, bukan SQLite | `pdo_sqlite` tidak terpasang; dev dan test memakai driver yang sama |
| Inertia, bukan API + SPA | Satu aplikasi, tidak ada lapisan API yang harus dijaga sinkron |
| `Support/*` logic murni | Bisa di-test tanpa database, cepat, dan tidak bisa kena N+1 |
| Kepemilikan di Policy | Satu tempat; controller ditulis ulang tanpa kehilangan aturan |
| Import atomik | Impor setengah jalan lebih buruk daripada gagal total |
| DOI di-skip kalau duplikat | Mengimpor ulang file yang sama tidak boleh menggandakan koleksi |
| CSP ketat di produksi | `script-src 'self'`; longgar hanya di lokal untuk Vite HMR |
