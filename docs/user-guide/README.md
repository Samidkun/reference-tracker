# Reference Tracker — Panduan Pengguna

Panduan ini untuk **pengguna aplikasi**, bukan programmer. Tidak perlu paham
istilah teknis untuk mengikutinya.

---

## Apa ini?

Reference Tracker membantu kamu menyimpan dan mengelola daftar referensi
(buku, jurnal, prosiding, skripsi, website) untuk skripsi, tesis, atau
penelitian.

**Yang bisa kamu lakukan:**

- Menyimpan referensi satu per satu
- Mengimpor banyak referensi sekaligus dari file `.bib` (hasil Mendeley, Zotero, Google Scholar)
- Mengambil data referensi otomatis dari **DOI** — kamu cukup tempel DOI-nya
- Mencari referensi berdasarkan judul, penulis, tahun, atau DOI
- Menandai referensi dengan **tag** dan memfilternya
- Mengekspor semua referensi kembali ke file `.bib` untuk dipakai di LaTeX atau Word

---

## Memulai

### 1. Membuat akun

1. Buka aplikasi di browser.
2. Klik **Register**.
3. Isi nama, email, dan kata sandi (minimal 8 karakter).
4. Klik **Register**.

Kamu akan langsung masuk ke halaman **References** (daftar referensi), yang
masih kosong.

> **Catatan:** satu akun hanya bisa melihat referensinya sendiri. Referensi
> orang lain tidak akan pernah muncul di akunmu.

### 2. Masuk kembali

1. Buka aplikasi.
2. Isi **email** dan **kata sandi**.
3. Klik **Log in**.

---

## Menambah referensi

### Cara A — isi manual

1. Klik **Add reference** di kanan atas.
2. Isi kolomnya:

   | Kolom | Wajib? | Keterangan |
   |---|---|---|
   | **Title** | ✅ | Judul lengkap |
   | **Authors** | ✅ | Satu penulis per baris, format `NamaBelakang, NamaDepan` |
   | **Year** | — | Tahun terbit, 4 angka |
   | **Type** | ✅ | Journal article / Book / Conference paper / Thesis / Website |
   | **DOI** | — | Kalau ada, isi — ini mengaktifkan tombol *Fetch metadata* |
   | **URL** | — | Alamat web sumber |
   | **Notes** | — | Catatan bebas untuk dirimu sendiri |
   | **Tags** | — | Muncul kalau kamu sudah pernah membuat tag |

3. Klik **Add reference**.

**Format penulis yang benar:**

```
LeCun, Yann
Bengio, Yoshua
Hinton, Geoffrey
```

Satu nama per baris. Kalau salah format, daftar referensimu tetap bisa
menampilkannya, tapi hasil ekspor BibTeX-nya bisa berantakan.

### Cara B — ambil otomatis dari DOI ⭐

Cara tercepat kalau kamu punya DOI-nya:

1. Klik **Add reference**.
2. Tempel DOI di kolom **DOI**, contoh: `10.1038/nature14539`
3. Klik **Fetch metadata**.
4. Judul, penulis, tahun, tipe, dan URL akan **terisi otomatis**.
5. Periksa sebentar, lalu klik **Add reference**.

Kalau DOI tidak ditemukan, akan muncul pesan kuning dan kamu bisa mengisi
manual. Aplikasi tidak akan error — kamu tinggal lanjut.

### Cara C — impor file `.bib`

Kalau kamu sudah punya koleksi di Mendeley/Zotero/Google Scholar:

1. Ekspor koleksimu jadi file `.bib` dari aplikasi tersebut.
2. Di Reference Tracker, klik **Add reference**.
3. Gulir ke bawah ke bagian **Import from BibTeX**.
4. Pilih file `.bib`-nya, klik **Import**.
5. Semua referensi di file itu langsung masuk.

**Kalau ada referensi duplikat** (DOI yang sama sudah ada), aplikasi akan
melewatinya dan memberi tahu:
*"Imported 12 reference(s). Skipped 3 duplicate(s)."*
Jadi kamu tidak perlu khawatir mengimpor file yang sama dua kali.

**Kalau file-nya rusak sebagian**, entri yang bisa dibaca tetap masuk; yang
rusak dilewati. Kamu tidak akan kehilangan seluruh impor karena satu baris
bermasalah.

---

## Mencari referensi

Ketik di kotak pencarian di atas daftar, lalu klik **Search**.

Pencarian mencari di **judul, penulis, tahun, dan DOI** sekaligus, dan
**tidak membedakan huruf besar/kecil** — `lecun`, `LeCun`, dan `LECUN` sama
saja hasilnya.

Klik **Search** dengan kotak kosong untuk menampilkan semua lagi.

---

## Memakai tag

1. Buat referensi dan isi kolom **Tags** (tag dibuat otomatis dari sini).
2. Di halaman daftar, tag muncul sebagai tombol bulat di bawah kotak
   pencarian.
3. Klik satu tag untuk memfilter — hanya referensi bertag itu yang tampil.
4. Klik lagi untuk mematikan filter.

---

## Melihat, mengubah, menghapus

- **Lihat detail:** klik judul referensi. Halaman detail menampilkan semua
  kolom, dan DOI/URL bisa diklik untuk membuka sumbernya.
- **Ubah:** klik **Edit** (di halaman detail atau di baris daftar).
- **Hapus:** klik **Delete**. Akan muncul konfirmasi dulu — penghapusan
  tidak bisa dibatalkan.

---

## Mengekspor ke BibTeX

1. Di halaman daftar, klik **Export .bib**.
2. File `.bib` langsung terunduh (namanya `references-TANGGAL.bib`).
3. Pakai file itu di LaTeX, atau impor balik ke Mendeley/Zotero.

**Kunci sitasi (citation key) dipertahankan.** Kalau kamu mengimpor
`@article{lecun2015, ...}` lalu mengekspornya lagi, kuncinya tetap
`lecun2015` — tidak berubah jadi nama lain. Jadi `\cite{lecun2015}` di
dokumen LaTeX-mu tidak akan rusak.

---

## Pertanyaan yang sering muncul

**Saya lupa kata sandi.**
Klik **Forgot your password?** di halaman login, masukkan email, lalu ikuti
tautan yang dikirim ke emailmu.

**Apakah referensi saya bisa dilihat orang lain?**
Tidak. Setiap akun hanya bisa mengakses datanya sendiri.

**Kenapa tombol "Fetch metadata" tidak menemukan DOI saya?**
Kemungkinan: DOI-nya salah ketik, atau datanya belum terdaftar di Crossref.
Isi manual saja — semua kolom bisa diisi sendiri.

**Saya mengimpor file yang sama dua kali, apakah jadi dobel?**
Tidak. Referensi dengan DOI yang sama otomatis dilewati.

**Berapa banyak referensi yang bisa saya simpan?**
Tidak ada batasan dari aplikasi.

**Apakah data saya aman?**
Data disimpan di database aplikasi. Gunakan **Export .bib** secara berkala
sebagai cadangan pribadi.

**Bisa diakses dari HP?**
Ya, tampilannya menyesuaikan ukuran layar.

---

## Butuh bantuan lebih teknis?

Lihat [Developer Runbook](../runbook/README.md) — dokumen itu untuk
programmer yang memelihara aplikasi ini.
