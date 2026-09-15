# Reference Tracker — Design Contract

**Stage 2 output.** Mengikat semua kerjaan UI. Baca sebelum nulis komponen.

## Product

**User:** mahasiswa yang nulis skripsi (satu orang, satu akun).
**Tugas utama:** ngumpulin referensi → nulis bab 2 → export BibTeX.
**Satu aksi yang paling penting:** **tambah referensi cepat** — kalau ini lambat, app-nya gak kepake.

## Visual direction

**Tema: "meja kerja akademik"** — tenang, padat informasi, gak ramai.
Bukan dashboard startup, bukan landing page. Ini **alat kerja**, dipakai berjam-jam.

- **Base:** putih/abu terang (`slate-50` bg, `white` surface). Bukan dark mode (T1, skip dulu).
- **Aksen:** satu warna saja — `indigo-600`. Dipakai HANYA untuk aksi utama & state aktif.
- **Tipografi:** Instrument Sans (sudah dari Laravel). Body `text-sm`/`text-base`, heading `text-xl`/`text-2xl`. Line-length < 80ch untuk catatan.
- **Radius:** `rounded-lg` konsisten. Tidak campur radius.
- **Spacing:** skala 4px (`p-4`, `gap-3`, `space-y-6`). Tidak ada angka ajaib.
- **Shadow:** minimal — cuma `shadow-sm` untuk kartu, jangan bertumpuk.

**Kenapa bukan tampilan "startup":** gradien ungu-biru, hero besar, ilustrasi 3D, emoji
ikon — semua itu slop untuk alat kerja. Yang dipakai tiap hari harus **cepat dibaca, gak
melelahkan mata, dan gak ada elemen dekoratif tanpa fungsi.**

## Struktur halaman

```
Layout (sidebar kiri + konten)
├── /            Daftar referensi (default)
├── /references/create   Form tambah
├── /references/{id}/edit Form edit
├── /tags        Kelola tag
└── /export      Pilih + export BibTeX
```

## Komponen yang dipakai ulang (jangan bikin varian baru)

| Komponen | Tanggung jawab |
|---|---|
| `AppLayout` | Sidebar, header, slot konten |
| `Button` | primary / secondary / danger / ghost — 1 ukuran radius |
| `Input`, `Select`, `Textarea` | label + error inline, konsisten |
| `Card` | surface untuk daftar & form |
| `EmptyState` | ikon + pesan + 1 aksi |
| `TagPill` | tag dengan warna netral (bukan warna acak) |
| `Toast` | feedback aksi (sukses/gagal) |
| `ConfirmDialog` | aksi destruktif (hapus) |

## Required states (WAJIB ada semua)

| State | Wujud |
|---|---|
| **Empty** | "Belum ada referensi" + tombol "Tambah referensi pertama" — bukan tabel kosong |
| **Loading** | Skeleton baris (bukan spinner penuh layar) saat fetch daftar |
| **Error** | Pesan + tombol "Coba lagi". Jangan error mentah |
| **Success** | Toast setelah simpan/hapus |
| **Validation error** | Pesan di bawah field, field border merah |
| **404** | Halaman "Referensi tidak ditemukan" + kembali ke daftar |
| **Disabled** | Tombol disabled saat submit pending |
| **Offline (DOI fetch)** | "Tidak bisa menghubungi Crossref" + opsi isi manual |

## Detail yang sering kelupaan (Stage 2 checklist)

- [ ] Favicon + title per halaman
- [ ] Keyboard: Tab bisa ke semua kontrol, focus ring kelihatan
- [ ] `aria-label` di tombol ikon
- [ ] Kontras ≥ 4.5:1
- [ ] Konfirmasi sebelum hapus
- [ ] Form: `type=url` untuk URL, `type=number` untuk tahun, `autocomplete` di login
- [ ] Long text: judul panjang → truncate dengan tooltip, bukan meluber
- [ ] Daftar besar: pagination (jangan load semua)
- [ ] Mobile: sidebar jadi drawer, tabel jadi kartu bertumpuk
- [ ] Print stylesheet untuk halaman daftar (biar bisa di-print)

## Banned (slop markers)

- Gradien dekoratif tanpa fungsi
- Emoji sebagai ikon fungsional
- Card bertumpuk-tumpuk dengan shadow
- Placeholder lorem ipsum
- Warna acak per tag (pakai netral saja)
- Animasi di luar feedback aksi
- Label ALL-CAPS berlebihan
- Angka `01 / 02 / 03` kalau bukan urutan asli

## Finish gate (Stage 2 akhir)

Render + periksa sekali. Perbaiki: clipping, overlap, kontrol gak bisa diakses,
interaksi mati, kontras rendah, hierarki gak kebaca.
