# Katalog Pertanyaan Metabase — Dashboard Publik

Dokumen ini adalah **spesifikasi kartu** untuk dashboard publik Disdikpora Kabupaten Karangasem.
Setiap pertanyaan di bawah sudah dijalankan langsung terhadap basis data `backbone_client`
dan angka ekspektasinya dicatat, sehingga bisa dipakai sebagai *sanity check* saat kartu dibuat massal.

| Item | Nilai |
|---|---|
| Basis data Metabase | `backbone_client` (database id **2**, role baca `analis`) |
| Skema sumber | `dbo` (transaksional), `ref` (referensi), `qc` (validasi) |
| Dashboard target | Dashboard publik (id **2**, `public_uuid` tersimpan di `docs/metabase-ids.md`) |
| Semester aktif | `ref.semester.periode_aktif = 1` → **20261** (2026/2027 Gasal) |
| Jumlah tab | 6 |
| Jumlah kartu | 46 (8 / 8 / 7 / 8 / 7 / 8) |
| Dashboard admin | **dihapus** (tidak dipakai, mengurangi beban render) |
| Tanggal validasi | 21 Oktober 2026 |

---

## 1. Keputusan desain

1. **Semua kartu native SQL** ke database id 2. Skema `datamart` (22 tabel) **kosong 0 baris**
   pada dump ini, sehingga tidak boleh dipakai.
2. **Filter memakai native field filter** (`type: dimension`) agar mendapat dropdown nilai,
   bukan input teks bebas.
3. **Tabel tidak diberi alias** pada kolom yang difilter. Metabase mengompilasi field filter
   menjadi `"nama_tabel"."nama_kolom"` (bukan alias SQL), jadi `FROM dbo.sekolah` harus ditulis
   tanpa alias `s`. Ini sudah diuji: query dengan alias gagal
   (`invalid reference to FROM-clause entry for table "mst_wilayah"`).
4. **Kecamatan diresolusi tanpa self-join ber-alias** — memakai subquery korelasi
   untuk mendapatkan kode kecamatan, lalu join ke `ref.mst_wilayah` tanpa alias.
5. **Peserta didik dihitung unik per NISN 10 digit**, bukan per `peserta_didik_id`.
   NISN kosong atau bukan 10 digit numerik **tidak dipakai** (dikeluarkan dari semua kartu).
6. **PTK aktif memakai penugasan induk** (`ptk_induk = 1`) agar satu PTK yang mengajar di
   dua sekolah tidak dihitung dua kali. Pengecualian tunggal: Q-SEK-06 memakai semua penugasan
   (lihat §6).
7. **Ukuran rombel dibandingkan standar per jenjang**, bukan satu ambang 32 murid:
   TK/KB/TPA 15, SD 28, SMP 32, Paket A 20, Paket B 25, Paket C 30 (Kepmendikdasmen 14/2026).
8. **Atribusi wilayah**: untuk semua entitas sekolah (sekolah, rombel, peserta didik, PTK,
   sarana prasarana), Kecamatan/Desa = **lokasi satuan pendidikan**, bukan lokasi murid.
   Untuk tab ATS, Kecamatan/Desa = **domisili ATS**.
9. **ATS dibatasi domisili Kabupaten Karangasem** (`kode_kabupaten = '220800'`). Tanpa filter ini,
   93 baris ATS berasal dari luar Karangasem (Denpasar, Badung, bahkan luar Bali).
10. **Tidak ada data pribadi** (nama murid/PTK/ATS, NIK, NISN, alamat) yang ditampilkan.
    Nama sekolah boleh tampil karena merupakan entitas publik.

---

## 2. Definisi baku "aktif dan valid"

| Entitas | Definisi | Ekspektasi |
|---|---|---|
| **Sekolah aktif** | `dbo.sekolah.keaktifan = 1 AND "Soft_delete" = 0` | **604** |
| **Rombel aktif (kelas)** | `"Soft_delete" = 0 AND jenis_rombel = 1 AND semester_id = (semester periode_aktif)` | **3.482** |
| **Registrasi aktif** | `registrasi_peserta_didik."Soft_delete" = 0 AND jenis_keluar_id IS NULL` di sekolah aktif | **70.463** murid unik |
| **Peserta didik aktif** | registrasi aktif **dan** `TRIM(peserta_didik.nisn) ~ '^[0-9]{10}$'`, dihitung unik per `nisn` | **68.952** |
| **NISN sah** | 10 digit numerik; tidak memakai `qc.vld_peserta_didik` sebagai dasar | **68.976** registrasi (97,89%) |
| **PTK aktif** | `ptk_terdaftar."Soft_delete" = 0 AND jenis_keluar_id IS NULL AND qc.vld_ptk.aktif = 1 AND ptk_induk = 1` | **4.981** unik (guru **3.879**) |
| **ATS usia sekolah** | `dbo.ats.soft_delete = 0 AND usia BETWEEN 7 AND 18 AND kode_kabupaten = '220800'` (domisili) | **3.032** |
| **Ruang kondisi tercatat** | `dbo.ruang_longitudinal.semester_id = semester periode_aktif` dan ruang tidak dihapus | **1.769** dari 11.449 ruang |

### 2.1 Standar ukuran rombel per jenjang

| Jenjang | Standar maksimum | Sumber |
|---|---:|---|
| TK / KB / TPA | 15 murid | standar PAUD (rentang ideal 10–15) |
| SD | 28 murid | standar rombel SD |
| SMP | 32 murid | standar rombel SMP |
| PKBM/SKB — Paket A (tingkat 31–36) | 20 murid | Kepmendikdasmen 14/2026 |
| PKBM/SKB — Paket B (tingkat 37–39) | 25 murid | Kepmendikdasmen 14/2026 |
| PKBM/SKB — Paket C (tingkat 40–42) | 30 murid | Kepmendikdasmen 14/2026 |

Kartu Q-ROM-05 dan Q-ROM-06 memakai tabel ini. Dengan standar tersebut, **448 dari 3.482 rombel
(12,9%) melebihi standar**; kalau memakai satu ambang 32 murid hanya 136 rombel yang terdeteksi,
dan seluruh TK/PAUD (159 rombel > 15) tidak akan pernah muncul.

### 2.2 Atribusi wilayah (penting)

| Tab | Sumber Kecamatan | Sumber Desa |
|---|---|---|
| Eksekutif, Sekolah, Rombel, Peserta Didik, Sarana Prasarana | `dbo.sekolah.kode_wilayah` → `ref.mst_wilayah.nama` (**lokasi sekolah**) | `dbo.sekolah.desa_kelurahan` |
| ATS | `dbo.ats.kecamatan` (**domisili ATS**) | `dbo.ats.desa_kelurahan` |

Artinya "peserta didik per kecamatan" = jumlah murid yang **bersekolah** di kecamatan tersebut,
bukan jumlah murid yang berdomisili di sana. Basis data tidak menyimpan domisili murid, sehingga
sudut pandang ini satu-satunya yang konsisten untuk semua entitas sekolah.

> Catatan: `dbo.peserta_didik` pada dump ini hanya berisi `peserta_didik_id`, `nama`, `nisn`, `nik`.
> **Tidak ada kolom `jenis_kelamin` maupun `agama` untuk peserta didik**, sehingga kartu demografi
> murid per gender/agama tidak dibuat. Hanya tabel `dbo.ats` yang punya `jenis_kelamin`.

---

## 3. Filter

### 3.1 Matriks filter per tab

| Filter | Tag | EXE | SEK | ROM | PD | ATS | SAR |
|---|---|:--:|:--:|:--:|:--:|:--:|:--:|
| Kecamatan | `kecamatan` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Desa/Kelurahan | `desa` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Jenjang satuan pendidikan | `jenjang` | ✅ | ✅ | ✅ | ✅ | — | ✅ |
| Status Negeri/Swasta | `status_sekolah` | ✅ | ✅ | ✅ | ✅ | — | ✅ |
| Jenis PTK | `jenis_ptk` | ✅¹ | — | — | — | — | — |
| Jabatan PTK | `jabatan_ptk` | ✅¹ | — | — | — | — | — |
| Jenjang ATS | `jenjang` (ATS) | — | — | — | — | ✅ | — |

¹ Hanya dipetakan ke kartu **Q-EXE-04 (Total PTK Aktif)**. Filter ini tidak dipetakan ke kartu lain
di tab Eksekutif karena kartu lain tidak menghitung PTK; memetakannya justru akan mengubah arti angka
(contoh: "Total Sekolah" tidak berubah bila difilter jenis PTK).

Tab ATS memakai filter **Jenjang (ATS)** tersendiri karena domain nilainya berbeda
(`SD / sederajat`, `Paket A/B/C`, dst.), bukan nama bentuk pendidikan sekolah.
Parameter dashboard-nya dipisah agar tidak tertukar.

Untuk semua tab selain ATS, filter Kecamatan dan Desa mengikuti **lokasi satuan pendidikan**
(§2.2). Tab ATS memakai **domisili ATS**.

### 3.2 Pemetaan tag ke field Metabase

| Tag | Tabel.kolom | Metabase field ID | Nilai dropdown |
|---|---|---|---|
| `kecamatan` | `ref.mst_wilayah.nama` | **2546** | 8 kecamatan |
| `desa` (sekolah) | `dbo.sekolah.desa_kelurahan` | **1790** | 88 desa/kelurahan |
| `desa` (ATS) | `dbo.ats.desa_kelurahan` | **1379** | desa ATS |
| `jenjang` (sekolah) | `ref.bentuk_pendidikan.nama` | **1993** | SD, SMP, TK, KB, PKBM, TPA, SKB |
| `jenjang` (ATS) | `dbo.ats.jenjang_pendidikan` | **1387** | SD / sederajat, SMP / sederajat, SMA / sederajat, Paket A/B/C, Tidak terdata |
| `status_sekolah` | `dbo.sekolah.status_sekolah` | **1811** | 1 = Negeri, 2 = Swasta² |
| `jenis_ptk` | `ref.jenis_ptk.jenis_ptk` | **2264** | Guru, Kepala Sekolah, Tenaga Kependidikan |
| `jabatan_ptk` | `ref.jabatan_ptk.jabatan_ptk` | **2072** | 109 jabatan |
| `kecamatan` (ATS) | `dbo.ats.kecamatan` | **1385** | 8 kecamatan (nama identik dengan sekolah) |

² Status memakai **static list** (`Negeri = 1`, `Swasta = 2`) sehingga dropdown sudah berlabel
tanpa perlu display values pada field 1811.

### 3.2.1 Sumber nilai dropdown (implementasi)

Dropdown **tidak** memakai seluruh nilai field. Field `ref.mst_wilayah.nama` berisi ribuan wilayah
di Indonesia, dan `dbo.ats.kecamatan` memuat nama dari luar Karangasem (ATS yang berdomisili di luar
kabupaten tetap tersimpan). Bila dibiarkan, dropdown menampilkan nama seperti "Lembudud" atau
"Pa Butal" yang tidak relevan.

Karena itu nilai dropdown diambil dari **6 kartu bantu** di collection *Filter — Daftar Nilai*
(id `6`). Kartu bantu ini bukan kartu analitik dan **tidak dihitung** dalam 46 kartu dashboard.

| Parameter | Sumber nilai | Opsi |
|---|---|---:|
| Kecamatan | Card `97` — `Daftar Kecamatan` | 8 |
| Desa/Kelurahan | Card `98` — `Daftar Desa/Kelurahan` | 88 |
| Jenjang Satuan Pendidikan | Card `99` — `Daftar Jenjang Satuan Pendidikan` | 7 |
| Status Sekolah | Static list (Negeri = 1, Swasta = 2) | 2 |
| Jenis PTK | Card `100` — `Daftar Jenis PTK` | 3 |
| Jabatan PTK | Card `101` — `Daftar Jabatan PTK` | 26 |
| Jenjang (ATS) | Card `102` — `Daftar Jenjang ATS` | 6 |

Kartu bantu boleh diubah kapan saja (mis. desa baru); dropdown ikut menyesuaikan tanpa menyentuh
SQL 46 kartu.

### 3.3 Filter yang **tidak tersedia** di basis data

| Filter yang diminta | Status | Bukti |
|---|---|---|
| **Status Kepegawaian** (PNS, GTY/PTY, Honorer, …) | ❌ Tidak ada | `ref.status_kepegawaian` ada, tetapi **tidak ada satu pun kolom `status_kepegawaian_id`** di `dbo.ptk`, `dbo.ptk_terdaftar`, maupun tabel `dbo` lain |
| **Pendidikan PTK** (S1, S2, D3, …) | ❌ Tidak ada | `dbo.ptk` hanya `ptk_id, nama, nik, nuptk`; `dbo.ptk_terdaftar` hanya data penugasan. Tidak ada tabel riwayat pendidikan PTK |
| **Jabatan PTK** | ✅ Tersedia | `dbo.ptk_terdaftar.jabatan_ptk_id` → `ref.jabatan_ptk` (dipakai sebagai pengganti terdekat) |

Jika kelak dump memuat tabel riwayat pendidikan/kepegawaian, filter bisa ditambahkan tanpa
mengubah struktur kartu: cukup menambah satu join dan satu tag.

### 3.4 Nilai filter saat ini

- **Kecamatan (8)**: Kec. Abang, Kec. Bebandem, Kec. Karangasem, Kec. Kubu, Kec. Manggis,
  Kec. Rendang, Kec. Selat, Kec. Sidemen.
- **Desa/Kelurahan**: 88 nilai; sumber dropdown sebaiknya *card* `SELECT DISTINCT desa_kelurahan`.
- **Jenjang sekolah (7)**: SD (357), TK (146), SMP (48), KB (39), PKBM (10), TPA (3), SKB (1).
  Tidak ada SMA/SMK karena kewenangan Dinas Pendidikan kabupaten berhenti di SMP.
- **Status**: Negeri 418, Swasta 186.
- **Jenis PTK (penugasan induk)**: Guru 3.879; Kepala Sekolah 573; Tenaga Kependidikan 529.

---

## 4. Blok CTE baku

Semua kartu memakai blok di bawah ini. **Jangan memberi alias pada `dbo.sekolah`,
`ref.mst_wilayah`, `ref.bentuk_pendidikan`, `ref.jenis_ptk`, `ref.jabatan_ptk`,
`dbo.ats`, dan `dbo.ptk_terdaftar`** — field filter Metabase membutuhkan nama tabel fisik.


```sql
-- Sekolah aktif + dimensi wilayah + filter tab (EXE/SEK/ROM/PD/SAR)
sekolah_dim AS (
  SELECT
    dbo.sekolah.sekolah_id,
    dbo.sekolah.npsn,
    dbo.sekolah.nama AS nama_sekolah,
    ref.mst_wilayah.nama AS kecamatan,
    dbo.sekolah.desa_kelurahan AS desa,
    ref.bentuk_pendidikan.nama AS jenjang,
    CASE dbo.sekolah.status_sekolah WHEN 1 THEN 'Negeri' ELSE 'Swasta' END AS status_sekolah,
    COALESCE(ref.status_kepemilikan.nama, 'Tidak diketahui') AS kepemilikan
  FROM dbo.sekolah
  JOIN ref.mst_wilayah
    ON ref.mst_wilayah.kode_wilayah = COALESCE(
         (SELECT CASE WHEN w.id_level_wilayah = 4 THEN w.mst_kode_wilayah ELSE w.kode_wilayah END
            FROM ref.mst_wilayah w
           WHERE w.kode_wilayah = dbo.sekolah.kode_wilayah),
         dbo.sekolah.kode_wilayah)
  JOIN ref.bentuk_pendidikan
    ON ref.bentuk_pendidikan.bentuk_pendidikan_id = dbo.sekolah.bentuk_pendidikan_id
  LEFT JOIN ref.status_kepemilikan
    ON ref.status_kepemilikan.status_kepemilikan_id = dbo.sekolah.status_kepemilikan_id
  WHERE dbo.sekolah.keaktifan = 1
    AND dbo.sekolah."Soft_delete" = 0
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
  [[AND {{status_sekolah}}]]
)
```

```sql
-- Rombel kelas semester aktif
rombel_dim AS (
  SELECT
    dbo.rombongan_belajar.rombongan_belajar_id,
    dbo.rombongan_belajar.tingkat_pendidikan_id,
    dbo.rombongan_belajar.nama AS nama_rombel,
    dbo.rombongan_belajar.ptk_id AS wali_kelas_id,
    sekolah_dim.sekolah_id,
    sekolah_dim.npsn,
    sekolah_dim.nama_sekolah,
    sekolah_dim.jenjang,
    sekolah_dim.kecamatan
  FROM dbo.rombongan_belajar
  JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.rombongan_belajar.sekolah_id
  WHERE dbo.rombongan_belajar."Soft_delete" = 0
    AND dbo.rombongan_belajar.jenis_rombel = 1
    AND dbo.rombongan_belajar.semester_id = (SELECT semester_id FROM ref.semester WHERE periode_aktif = 1)
)
```

```sql
-- Peserta didik aktif, HANYA NISN 10 digit. Kunci hitung: COUNT(DISTINCT nisn)
pd_dim AS (
  SELECT
    dbo.registrasi_peserta_didik.peserta_didik_id,
    TRIM(dbo.peserta_didik.nisn) AS nisn,
    dbo.registrasi_peserta_didik.sekolah_id,
    dbo.registrasi_peserta_didik.nipd,
    sekolah_dim.npsn,
    sekolah_dim.nama_sekolah,
    sekolah_dim.jenjang,
    sekolah_dim.kecamatan
  FROM dbo.registrasi_peserta_didik
  JOIN dbo.peserta_didik
    ON dbo.peserta_didik.peserta_didik_id = dbo.registrasi_peserta_didik.peserta_didik_id
  JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.registrasi_peserta_didik.sekolah_id
  WHERE dbo.registrasi_peserta_didik."Soft_delete" = 0
    AND dbo.registrasi_peserta_didik.jenis_keluar_id IS NULL
    AND TRIM(dbo.peserta_didik.nisn) ~ '^[0-9]{10}$'
)
```

```sql
-- Seluruh registrasi aktif (tanpa saringan NISN). Hanya untuk kartu kualitas data
-- Q-EXE-07 dan Q-PD-05.
pd_all AS (
  SELECT
    dbo.registrasi_peserta_didik.peserta_didik_id,
    TRIM(dbo.peserta_didik.nisn) AS nisn,
    CASE WHEN TRIM(dbo.peserta_didik.nisn) ~ '^[0-9]{10}$' THEN 1 ELSE 0 END AS nisn_valid,
    sekolah_dim.npsn,
    sekolah_dim.nama_sekolah,
    sekolah_dim.jenjang,
    sekolah_dim.kecamatan
  FROM dbo.registrasi_peserta_didik
  LEFT JOIN dbo.peserta_didik
    ON dbo.peserta_didik.peserta_didik_id = dbo.registrasi_peserta_didik.peserta_didik_id
  JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.registrasi_peserta_didik.sekolah_id
  WHERE dbo.registrasi_peserta_didik."Soft_delete" = 0
    AND dbo.registrasi_peserta_didik.jenis_keluar_id IS NULL
)
```

```sql
-- PTK aktif penugasan INDUK (ptk_induk = 1). Untuk Q-EXE-04 tambahkan dua baris
-- filter berikut sebelum tanda kurung tutup: [[AND {jenis_ptk}]] dan [[AND {jabatan_ptk}]]
ptk_dim AS (
  SELECT
    dbo.ptk_terdaftar.ptk_id,
    dbo.ptk_terdaftar.sekolah_id,
    ref.jenis_ptk.jenis_ptk,
    ref.jabatan_ptk.jabatan_ptk
  FROM dbo.ptk_terdaftar
  JOIN qc.vld_ptk ON qc.vld_ptk.ptk_id = dbo.ptk_terdaftar.ptk_id
  JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.ptk_terdaftar.sekolah_id
  LEFT JOIN ref.jenis_ptk ON ref.jenis_ptk.jenis_ptk_id = dbo.ptk_terdaftar.jenis_ptk_id
  LEFT JOIN ref.jabatan_ptk ON ref.jabatan_ptk.jabatan_ptk_id = dbo.ptk_terdaftar.jabatan_ptk_id
  WHERE dbo.ptk_terdaftar."Soft_delete" = 0
    AND dbo.ptk_terdaftar.jenis_keluar_id IS NULL
    AND qc.vld_ptk.aktif = 1
    AND dbo.ptk_terdaftar.ptk_induk = 1
)
```

```sql
-- Semua penugasan PTK, termasuk non-induk/PLT. Hanya dipakai Q-SEK-06 agar sekolah
-- dengan kepala sekolah berstatus PLT tidak salah ditandai "tanpa kepala sekolah".
ptk_all AS (
  SELECT
    dbo.ptk_terdaftar.ptk_id,
    dbo.ptk_terdaftar.sekolah_id,
    ref.jenis_ptk.jenis_ptk,
    ref.jabatan_ptk.jabatan_ptk
  FROM dbo.ptk_terdaftar
  JOIN qc.vld_ptk ON qc.vld_ptk.ptk_id = dbo.ptk_terdaftar.ptk_id
  JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.ptk_terdaftar.sekolah_id
  LEFT JOIN ref.jenis_ptk ON ref.jenis_ptk.jenis_ptk_id = dbo.ptk_terdaftar.jenis_ptk_id
  LEFT JOIN ref.jabatan_ptk ON ref.jabatan_ptk.jabatan_ptk_id = dbo.ptk_terdaftar.jabatan_ptk_id
  WHERE dbo.ptk_terdaftar."Soft_delete" = 0
    AND dbo.ptk_terdaftar.jenis_keluar_id IS NULL
    AND qc.vld_ptk.aktif = 1
)
```

```sql
-- ATS usia sekolah, dibatasi domisili Kabupaten Karangasem + filter tab ATS
ats_dim AS (
  SELECT
    dbo.ats.ats_id,
    TRIM(dbo.ats.status) AS status,
    dbo.ats.usia,
    dbo.ats.kecamatan,
    dbo.ats.desa_kelurahan AS desa,
    COALESCE(NULLIF(dbo.ats.jenjang_pendidikan, ''), 'Tidak terdata') AS jenjang,
    dbo.ats.tahun
  FROM dbo.ats
  WHERE dbo.ats.soft_delete = 0
    AND dbo.ats.usia BETWEEN 7 AND 18
    AND dbo.ats.kode_kabupaten = '220800'
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
)
```


---

## 5. Rencana eksekusi (pembuatan massal)

### 5.1 Urutan kerja

1. **Buat collection** "Dashboard Publik — Kartu" sebagai wadah 46 kartu.
2. **Buat 46 card** via `POST /api/card` (`dataset_query.type = native`, `database = 2`),
   satu per satu, lalu jalankan `POST /api/card/{id}/query` untuk memastikan `status = completed`
   dan jumlah baris sesuai kolom *Ekspektasi*.
3. **Set display** sesuai kolom *Tipe* (`scalar`, `bar`, `row`, `table`, `pie`, `gauge`) beserta
   `visualization_settings` (judul sumbu, format angka, warna).
4. **Buat 6 tab** pada dashboard 2: `PUT /api/dashboard/2` dengan array `tabs`
   (`[{"id": -1, "name": "Eksekutif"}, …]`).
5. **Pasang dashcard** via `POST /api/dashboard/2/cards` dengan `tab_id`, `row` **kumulatif**
   (`row = row sebelumnya + size_y`), `col`, `size_x`, `size_y`.
6. **Pasang parameter dashboard** (`PUT /api/dashboard/2`) dan `parameter_mappings` per dashcard
   sesuai matriks §3.1. Tetapkan juga **sumber nilai** tiap parameter (§3.2.1) — tanpa itu dropdown
   menarik nilai dari seluruh isi field dan menampilkan wilayah di luar Karangasem.
7. **Hapus dashboard admin** (`DELETE /api/dashboard/4`) setelah tab publik terverifikasi.
8. **Verifikasi** memakai checklist §7, lalu perbarui `docs/metabase-ids.md`.

### 5.2 Aturan tata letak (wajib)

- Grid Metabase = **24 kolom**; 1 unit baris ≈ 46 px.
- `row` harus **kumulatif**, bukan indeks sekuensial. Contoh benar: `row = 0`, lalu `6`, lalu `12`.
  Kesalahan `row = 0, 1, 2` membuat tinggi grid kurang dihitung sehingga kartu menimpa footer
  "Didukung oleh Metabase" (bug yang sudah diperbaiki di PR #20).
- Tinggi minimum aman: KPI `size_y = 4`, grafik `size_y = 8`, tabel `size_y = 10`.
- Wrapper aplikasi memakai layout flex satu viewport (bukan `calc(100vh - Xrem)`), sehingga
  dashboard boleh lebih tinggi dari layar tanpa memotong footer.

### 5.3 Contoh payload parameter mapping

```json
{
  "parameter_id": "<uuid parameter dashboard>",
  "card_id": 123,
  "target": ["dimension", ["template-tag", "kecamatan"]]
}
```

Untuk tab ATS, filter **Jenjang (ATS)** memakai `parameter_id` berbeda tetapi target tag tetap
`["dimension", ["template-tag", "jenjang"]]`.

### 5.4 Perkiraan waktu

| Tahap | Durasi |
|---|---|
| Buat + uji 46 card | ~25 menit (otomatis, ±30 detik/kartu) |
| Tab + dashcard + layout | ~10 menit |
| Parameter dashboard + mapping | ~5 menit |
| Verifikasi visual 3 viewport | ~10 menit |

---

## 6. Kartu per tab


### Tab 1 — Eksekutif (8 kartu)

Ringkasan agregat lintas sektor: sekolah, rombel, peserta didik, dan PTK.

**Tata letak yang disarankan**: KPI 6×4 (baris 0), grafik 12×8 (baris 4), grafik lebar 24×8 (baris 12).


| ID | Judul | Tipe | Ekspektasi |
|---|---|---|---|
| Q-EXE-01 | Total Sekolah Aktif | Scalar | 604 |
| Q-EXE-02 | Total Rombel Aktif (Kelas) | Scalar | 3482 |
| Q-EXE-03 | Total Peserta Didik Aktif | Scalar | 68952 |
| Q-EXE-04 | Total PTK Aktif | Scalar | 4981 |
| Q-EXE-05 | Rasio Peserta Didik per Guru | Scalar | 17.8 |
| Q-EXE-06 | Rata-rata Murid per Rombel | Scalar | 18.6 |
| Q-EXE-07 | Persentase Registrasi Aktif dengan NISN Sah | Scalar (persen) | 97.89 |
| Q-EXE-08 | Sebaran Sekolah per Jenjang | Bar (vertikal) | 7 baris — teratas: SD · 357 |



#### Q-EXE-01 · Total Sekolah Aktif

- **Tab**: Eksekutif
- **Tipe visualisasi**: Scalar
- **Definisi**: Jumlah satuan pendidikan dengan `keaktifan = 1` dan `Soft_delete = 0`.
- **Filter**: Kecamatan, Desa, Jenjang, Status
- **Ekspektasi (data saat ini)**: 604
- **Catatan**: Angka dasar seluruh dashboard. Semua kartu lain memakai definisi sekolah aktif yang sama.

```sql
WITH sekolah_dim AS (
  SELECT
    dbo.sekolah.sekolah_id,
    dbo.sekolah.npsn,
    dbo.sekolah.nama AS nama_sekolah,
    ref.mst_wilayah.nama AS kecamatan,
    dbo.sekolah.desa_kelurahan AS desa,
    ref.bentuk_pendidikan.nama AS jenjang,
    CASE dbo.sekolah.status_sekolah WHEN 1 THEN 'Negeri' ELSE 'Swasta' END AS status_sekolah,
    COALESCE(ref.status_kepemilikan.nama, 'Tidak diketahui') AS kepemilikan
  FROM dbo.sekolah
  JOIN ref.mst_wilayah
    ON ref.mst_wilayah.kode_wilayah = COALESCE(
         (SELECT CASE WHEN w.id_level_wilayah = 4 THEN w.mst_kode_wilayah ELSE w.kode_wilayah END
            FROM ref.mst_wilayah w
           WHERE w.kode_wilayah = dbo.sekolah.kode_wilayah),
         dbo.sekolah.kode_wilayah)
  JOIN ref.bentuk_pendidikan
    ON ref.bentuk_pendidikan.bentuk_pendidikan_id = dbo.sekolah.bentuk_pendidikan_id
  LEFT JOIN ref.status_kepemilikan
    ON ref.status_kepemilikan.status_kepemilikan_id = dbo.sekolah.status_kepemilikan_id
  WHERE dbo.sekolah.keaktifan = 1
    AND dbo.sekolah."Soft_delete" = 0
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
  [[AND {{status_sekolah}}]]
)
SELECT COUNT(*) AS total_sekolah
FROM sekolah_dim
```


#### Q-EXE-02 · Total Rombel Aktif (Kelas)

- **Tab**: Eksekutif
- **Tipe visualisasi**: Scalar
- **Definisi**: Jumlah rombongan belajar bertipe `Kelas` (`jenis_rombel = 1`) pada semester aktif (`ref.semester.periode_aktif = 1`) milik sekolah aktif.
- **Filter**: Kecamatan, Desa, Jenjang, Status
- **Ekspektasi (data saat ini)**: 3482
- **Catatan**: Rombel tipe Teori/Praktek/Pilihan tidak dihitung agar tidak dobel dengan kelas induk.

```sql
WITH sekolah_dim AS (
  SELECT
    dbo.sekolah.sekolah_id,
    dbo.sekolah.npsn,
    dbo.sekolah.nama AS nama_sekolah,
    ref.mst_wilayah.nama AS kecamatan,
    dbo.sekolah.desa_kelurahan AS desa,
    ref.bentuk_pendidikan.nama AS jenjang,
    CASE dbo.sekolah.status_sekolah WHEN 1 THEN 'Negeri' ELSE 'Swasta' END AS status_sekolah,
    COALESCE(ref.status_kepemilikan.nama, 'Tidak diketahui') AS kepemilikan
  FROM dbo.sekolah
  JOIN ref.mst_wilayah
    ON ref.mst_wilayah.kode_wilayah = COALESCE(
         (SELECT CASE WHEN w.id_level_wilayah = 4 THEN w.mst_kode_wilayah ELSE w.kode_wilayah END
            FROM ref.mst_wilayah w
           WHERE w.kode_wilayah = dbo.sekolah.kode_wilayah),
         dbo.sekolah.kode_wilayah)
  JOIN ref.bentuk_pendidikan
    ON ref.bentuk_pendidikan.bentuk_pendidikan_id = dbo.sekolah.bentuk_pendidikan_id
  LEFT JOIN ref.status_kepemilikan
    ON ref.status_kepemilikan.status_kepemilikan_id = dbo.sekolah.status_kepemilikan_id
  WHERE dbo.sekolah.keaktifan = 1
    AND dbo.sekolah."Soft_delete" = 0
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
  [[AND {{status_sekolah}}]]
),
rombel_dim AS (
  SELECT
    dbo.rombongan_belajar.rombongan_belajar_id,
    dbo.rombongan_belajar.tingkat_pendidikan_id,
    dbo.rombongan_belajar.nama AS nama_rombel,
    dbo.rombongan_belajar.ptk_id AS wali_kelas_id,
    sekolah_dim.sekolah_id,
    sekolah_dim.npsn,
    sekolah_dim.nama_sekolah,
    sekolah_dim.jenjang,
    sekolah_dim.kecamatan
  FROM dbo.rombongan_belajar
  JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.rombongan_belajar.sekolah_id
  WHERE dbo.rombongan_belajar."Soft_delete" = 0
    AND dbo.rombongan_belajar.jenis_rombel = 1
    AND dbo.rombongan_belajar.semester_id = (SELECT semester_id FROM ref.semester WHERE periode_aktif = 1)
)
SELECT COUNT(*) AS total_rombel
FROM rombel_dim
```


#### Q-EXE-03 · Total Peserta Didik Aktif

- **Tab**: Eksekutif
- **Tipe visualisasi**: Scalar
- **Definisi**: Jumlah unik peserta didik dengan registrasi aktif (`Soft_delete = 0` dan `jenis_keluar_id IS NULL`) pada sekolah aktif. Kunci unik adalah **NISN 10 digit numerik** (`^[0-9]{10}$`); murid tanpa NISN sah tidak dihitung.
- **Filter**: Kecamatan, Desa, Jenjang, Status
- **Ekspektasi (data saat ini)**: 68952
- **Catatan**: 68.976 registrasi ber-NISN sah; setelah dideduplikasi per NISN menjadi 68.952 karena 24 murid berbagi NISN dengan murid lain (data ganda).

```sql
WITH sekolah_dim AS (
  SELECT
    dbo.sekolah.sekolah_id,
    dbo.sekolah.npsn,
    dbo.sekolah.nama AS nama_sekolah,
    ref.mst_wilayah.nama AS kecamatan,
    dbo.sekolah.desa_kelurahan AS desa,
    ref.bentuk_pendidikan.nama AS jenjang,
    CASE dbo.sekolah.status_sekolah WHEN 1 THEN 'Negeri' ELSE 'Swasta' END AS status_sekolah,
    COALESCE(ref.status_kepemilikan.nama, 'Tidak diketahui') AS kepemilikan
  FROM dbo.sekolah
  JOIN ref.mst_wilayah
    ON ref.mst_wilayah.kode_wilayah = COALESCE(
         (SELECT CASE WHEN w.id_level_wilayah = 4 THEN w.mst_kode_wilayah ELSE w.kode_wilayah END
            FROM ref.mst_wilayah w
           WHERE w.kode_wilayah = dbo.sekolah.kode_wilayah),
         dbo.sekolah.kode_wilayah)
  JOIN ref.bentuk_pendidikan
    ON ref.bentuk_pendidikan.bentuk_pendidikan_id = dbo.sekolah.bentuk_pendidikan_id
  LEFT JOIN ref.status_kepemilikan
    ON ref.status_kepemilikan.status_kepemilikan_id = dbo.sekolah.status_kepemilikan_id
  WHERE dbo.sekolah.keaktifan = 1
    AND dbo.sekolah."Soft_delete" = 0
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
  [[AND {{status_sekolah}}]]
),
pd_dim AS (
  SELECT
    dbo.registrasi_peserta_didik.peserta_didik_id,
    TRIM(dbo.peserta_didik.nisn) AS nisn,
    dbo.registrasi_peserta_didik.sekolah_id,
    dbo.registrasi_peserta_didik.nipd,
    sekolah_dim.npsn,
    sekolah_dim.nama_sekolah,
    sekolah_dim.jenjang,
    sekolah_dim.kecamatan
  FROM dbo.registrasi_peserta_didik
  JOIN dbo.peserta_didik
    ON dbo.peserta_didik.peserta_didik_id = dbo.registrasi_peserta_didik.peserta_didik_id
  JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.registrasi_peserta_didik.sekolah_id
  WHERE dbo.registrasi_peserta_didik."Soft_delete" = 0
    AND dbo.registrasi_peserta_didik.jenis_keluar_id IS NULL
    AND TRIM(dbo.peserta_didik.nisn) ~ '^[0-9]{10}$'
)
SELECT COUNT(DISTINCT nisn) AS total_peserta_didik
FROM pd_dim
```


#### Q-EXE-04 · Total PTK Aktif

- **Tab**: Eksekutif
- **Tipe visualisasi**: Scalar
- **Definisi**: Jumlah unik PTK dengan penugasan **induk** aktif di sekolah aktif: `ptk_terdaftar.Soft_delete = 0`, belum keluar, `qc.vld_ptk.aktif = 1`, dan `ptk_induk = 1`. Satu PTK dihitung sekali walau punya penugasan tambahan di sekolah lain.
- **Filter**: Kecamatan, Desa, Jenjang, Status, Jenis PTK, Jabatan PTK
- **Ekspektasi (data saat ini)**: 4981
- **Catatan**: Ini satu-satunya kartu yang memakai filter Jenis PTK dan Jabatan PTK.

```sql
WITH sekolah_dim AS (
  SELECT
    dbo.sekolah.sekolah_id,
    dbo.sekolah.npsn,
    dbo.sekolah.nama AS nama_sekolah,
    ref.mst_wilayah.nama AS kecamatan,
    dbo.sekolah.desa_kelurahan AS desa,
    ref.bentuk_pendidikan.nama AS jenjang,
    CASE dbo.sekolah.status_sekolah WHEN 1 THEN 'Negeri' ELSE 'Swasta' END AS status_sekolah,
    COALESCE(ref.status_kepemilikan.nama, 'Tidak diketahui') AS kepemilikan
  FROM dbo.sekolah
  JOIN ref.mst_wilayah
    ON ref.mst_wilayah.kode_wilayah = COALESCE(
         (SELECT CASE WHEN w.id_level_wilayah = 4 THEN w.mst_kode_wilayah ELSE w.kode_wilayah END
            FROM ref.mst_wilayah w
           WHERE w.kode_wilayah = dbo.sekolah.kode_wilayah),
         dbo.sekolah.kode_wilayah)
  JOIN ref.bentuk_pendidikan
    ON ref.bentuk_pendidikan.bentuk_pendidikan_id = dbo.sekolah.bentuk_pendidikan_id
  LEFT JOIN ref.status_kepemilikan
    ON ref.status_kepemilikan.status_kepemilikan_id = dbo.sekolah.status_kepemilikan_id
  WHERE dbo.sekolah.keaktifan = 1
    AND dbo.sekolah."Soft_delete" = 0
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
  [[AND {{status_sekolah}}]]
),
ptk_dim AS (
  SELECT
    dbo.ptk_terdaftar.ptk_id,
    dbo.ptk_terdaftar.sekolah_id,
    ref.jenis_ptk.jenis_ptk,
    ref.jabatan_ptk.jabatan_ptk
  FROM dbo.ptk_terdaftar
  JOIN qc.vld_ptk ON qc.vld_ptk.ptk_id = dbo.ptk_terdaftar.ptk_id
  JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.ptk_terdaftar.sekolah_id
  LEFT JOIN ref.jenis_ptk ON ref.jenis_ptk.jenis_ptk_id = dbo.ptk_terdaftar.jenis_ptk_id
  LEFT JOIN ref.jabatan_ptk ON ref.jabatan_ptk.jabatan_ptk_id = dbo.ptk_terdaftar.jabatan_ptk_id
  WHERE dbo.ptk_terdaftar."Soft_delete" = 0
    AND dbo.ptk_terdaftar.jenis_keluar_id IS NULL
    AND qc.vld_ptk.aktif = 1
    AND dbo.ptk_terdaftar.ptk_induk = 1
  [[AND {{jenis_ptk}}]]
  [[AND {{jabatan_ptk}}]]
)
SELECT COUNT(DISTINCT ptk_id) AS total_ptk
FROM ptk_dim
```


#### Q-EXE-05 · Rasio Peserta Didik per Guru

- **Tab**: Eksekutif
- **Tipe visualisasi**: Scalar
- **Definisi**: Total peserta didik ber-NISN sah (10 digit) dibagi jumlah guru aktif pada penugasan induk (`jenis_ptk = 'Guru'`). Angka 17 berarti satu guru melayani 17 murid.
- **Filter**: Kecamatan, Desa, Jenjang, Status
- **Ekspektasi (data saat ini)**: 17.8

```sql
WITH sekolah_dim AS (
  SELECT
    dbo.sekolah.sekolah_id,
    dbo.sekolah.npsn,
    dbo.sekolah.nama AS nama_sekolah,
    ref.mst_wilayah.nama AS kecamatan,
    dbo.sekolah.desa_kelurahan AS desa,
    ref.bentuk_pendidikan.nama AS jenjang,
    CASE dbo.sekolah.status_sekolah WHEN 1 THEN 'Negeri' ELSE 'Swasta' END AS status_sekolah,
    COALESCE(ref.status_kepemilikan.nama, 'Tidak diketahui') AS kepemilikan
  FROM dbo.sekolah
  JOIN ref.mst_wilayah
    ON ref.mst_wilayah.kode_wilayah = COALESCE(
         (SELECT CASE WHEN w.id_level_wilayah = 4 THEN w.mst_kode_wilayah ELSE w.kode_wilayah END
            FROM ref.mst_wilayah w
           WHERE w.kode_wilayah = dbo.sekolah.kode_wilayah),
         dbo.sekolah.kode_wilayah)
  JOIN ref.bentuk_pendidikan
    ON ref.bentuk_pendidikan.bentuk_pendidikan_id = dbo.sekolah.bentuk_pendidikan_id
  LEFT JOIN ref.status_kepemilikan
    ON ref.status_kepemilikan.status_kepemilikan_id = dbo.sekolah.status_kepemilikan_id
  WHERE dbo.sekolah.keaktifan = 1
    AND dbo.sekolah."Soft_delete" = 0
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
  [[AND {{status_sekolah}}]]
),
pd_dim AS (
  SELECT
    dbo.registrasi_peserta_didik.peserta_didik_id,
    TRIM(dbo.peserta_didik.nisn) AS nisn,
    dbo.registrasi_peserta_didik.sekolah_id,
    dbo.registrasi_peserta_didik.nipd,
    sekolah_dim.npsn,
    sekolah_dim.nama_sekolah,
    sekolah_dim.jenjang,
    sekolah_dim.kecamatan
  FROM dbo.registrasi_peserta_didik
  JOIN dbo.peserta_didik
    ON dbo.peserta_didik.peserta_didik_id = dbo.registrasi_peserta_didik.peserta_didik_id
  JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.registrasi_peserta_didik.sekolah_id
  WHERE dbo.registrasi_peserta_didik."Soft_delete" = 0
    AND dbo.registrasi_peserta_didik.jenis_keluar_id IS NULL
    AND TRIM(dbo.peserta_didik.nisn) ~ '^[0-9]{10}$'
),
ptk_dim AS (
  SELECT
    dbo.ptk_terdaftar.ptk_id,
    dbo.ptk_terdaftar.sekolah_id,
    ref.jenis_ptk.jenis_ptk,
    ref.jabatan_ptk.jabatan_ptk
  FROM dbo.ptk_terdaftar
  JOIN qc.vld_ptk ON qc.vld_ptk.ptk_id = dbo.ptk_terdaftar.ptk_id
  JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.ptk_terdaftar.sekolah_id
  LEFT JOIN ref.jenis_ptk ON ref.jenis_ptk.jenis_ptk_id = dbo.ptk_terdaftar.jenis_ptk_id
  LEFT JOIN ref.jabatan_ptk ON ref.jabatan_ptk.jabatan_ptk_id = dbo.ptk_terdaftar.jabatan_ptk_id
  WHERE dbo.ptk_terdaftar."Soft_delete" = 0
    AND dbo.ptk_terdaftar.jenis_keluar_id IS NULL
    AND qc.vld_ptk.aktif = 1
    AND dbo.ptk_terdaftar.ptk_induk = 1
)
SELECT ROUND(
         (SELECT COUNT(DISTINCT nisn) FROM pd_dim)::numeric
         / NULLIF((SELECT COUNT(DISTINCT ptk_id) FROM ptk_dim WHERE jenis_ptk = 'Guru'), 0),
         1
       ) AS rasio_pd_per_guru
```


#### Q-EXE-06 · Rata-rata Murid per Rombel

- **Tab**: Eksekutif
- **Tipe visualisasi**: Scalar
- **Definisi**: Rata-rata jumlah murid per rombel kelas aktif, dihitung dari `dbo.anggota_rombel` aktif. Basisnya keanggotaan rombel (seluruh murid terdaftar), bukan NISN sah, supaya satu pembagian tidak mencampur dua basis hitung.
- **Filter**: Kecamatan, Desa, Jenjang, Status
- **Ekspektasi (data saat ini)**: 18.6
- **Catatan**: Basis berbeda dengan Q-EXE-03/05 yang memakai NISN sah; lihat §2.

```sql
WITH sekolah_dim AS (
  SELECT
    dbo.sekolah.sekolah_id,
    dbo.sekolah.npsn,
    dbo.sekolah.nama AS nama_sekolah,
    ref.mst_wilayah.nama AS kecamatan,
    dbo.sekolah.desa_kelurahan AS desa,
    ref.bentuk_pendidikan.nama AS jenjang,
    CASE dbo.sekolah.status_sekolah WHEN 1 THEN 'Negeri' ELSE 'Swasta' END AS status_sekolah,
    COALESCE(ref.status_kepemilikan.nama, 'Tidak diketahui') AS kepemilikan
  FROM dbo.sekolah
  JOIN ref.mst_wilayah
    ON ref.mst_wilayah.kode_wilayah = COALESCE(
         (SELECT CASE WHEN w.id_level_wilayah = 4 THEN w.mst_kode_wilayah ELSE w.kode_wilayah END
            FROM ref.mst_wilayah w
           WHERE w.kode_wilayah = dbo.sekolah.kode_wilayah),
         dbo.sekolah.kode_wilayah)
  JOIN ref.bentuk_pendidikan
    ON ref.bentuk_pendidikan.bentuk_pendidikan_id = dbo.sekolah.bentuk_pendidikan_id
  LEFT JOIN ref.status_kepemilikan
    ON ref.status_kepemilikan.status_kepemilikan_id = dbo.sekolah.status_kepemilikan_id
  WHERE dbo.sekolah.keaktifan = 1
    AND dbo.sekolah."Soft_delete" = 0
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
  [[AND {{status_sekolah}}]]
),
rombel_dim AS (
  SELECT
    dbo.rombongan_belajar.rombongan_belajar_id,
    dbo.rombongan_belajar.tingkat_pendidikan_id,
    dbo.rombongan_belajar.nama AS nama_rombel,
    dbo.rombongan_belajar.ptk_id AS wali_kelas_id,
    sekolah_dim.sekolah_id,
    sekolah_dim.npsn,
    sekolah_dim.nama_sekolah,
    sekolah_dim.jenjang,
    sekolah_dim.kecamatan
  FROM dbo.rombongan_belajar
  JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.rombongan_belajar.sekolah_id
  WHERE dbo.rombongan_belajar."Soft_delete" = 0
    AND dbo.rombongan_belajar.jenis_rombel = 1
    AND dbo.rombongan_belajar.semester_id = (SELECT semester_id FROM ref.semester WHERE periode_aktif = 1)
),
anggota AS (
  SELECT dbo.anggota_rombel.rombongan_belajar_id,
         COUNT(DISTINCT dbo.anggota_rombel.peserta_didik_id) AS jumlah_pd
  FROM dbo.anggota_rombel
  JOIN rombel_dim ON rombel_dim.rombongan_belajar_id = dbo.anggota_rombel.rombongan_belajar_id
  WHERE dbo.anggota_rombel."Soft_delete" = 0
  GROUP BY 1
)
SELECT ROUND(AVG(jumlah_pd), 1) AS rata_murid_per_rombel
FROM anggota
```


#### Q-EXE-07 · Persentase Registrasi Aktif dengan NISN Sah

- **Tab**: Eksekutif
- **Tipe visualisasi**: Scalar (persen)
- **Definisi**: Persentase registrasi peserta didik aktif yang memiliki NISN 10 digit numerik. Indikator kualitas data identitas murid: sisanya adalah murid yang belum punya NISN sah dan karena itu tidak masuk kartu peserta didik.
- **Filter**: Kecamatan, Desa, Jenjang, Status
- **Ekspektasi (data saat ini)**: 97.89
- **Catatan**: Penyebut = seluruh registrasi aktif (70.463), bukan hanya yang ber-NISN, sehingga angka ini mengukur cakupan data. Tabel QC memakai kode 1/2/4 (lihat §8) dan tidak dipakai sebagai dasar hitung.

```sql
WITH sekolah_dim AS (
  SELECT
    dbo.sekolah.sekolah_id,
    dbo.sekolah.npsn,
    dbo.sekolah.nama AS nama_sekolah,
    ref.mst_wilayah.nama AS kecamatan,
    dbo.sekolah.desa_kelurahan AS desa,
    ref.bentuk_pendidikan.nama AS jenjang,
    CASE dbo.sekolah.status_sekolah WHEN 1 THEN 'Negeri' ELSE 'Swasta' END AS status_sekolah,
    COALESCE(ref.status_kepemilikan.nama, 'Tidak diketahui') AS kepemilikan
  FROM dbo.sekolah
  JOIN ref.mst_wilayah
    ON ref.mst_wilayah.kode_wilayah = COALESCE(
         (SELECT CASE WHEN w.id_level_wilayah = 4 THEN w.mst_kode_wilayah ELSE w.kode_wilayah END
            FROM ref.mst_wilayah w
           WHERE w.kode_wilayah = dbo.sekolah.kode_wilayah),
         dbo.sekolah.kode_wilayah)
  JOIN ref.bentuk_pendidikan
    ON ref.bentuk_pendidikan.bentuk_pendidikan_id = dbo.sekolah.bentuk_pendidikan_id
  LEFT JOIN ref.status_kepemilikan
    ON ref.status_kepemilikan.status_kepemilikan_id = dbo.sekolah.status_kepemilikan_id
  WHERE dbo.sekolah.keaktifan = 1
    AND dbo.sekolah."Soft_delete" = 0
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
  [[AND {{status_sekolah}}]]
),
pd_all AS (
  SELECT
    dbo.registrasi_peserta_didik.peserta_didik_id,
    TRIM(dbo.peserta_didik.nisn) AS nisn,
    CASE WHEN TRIM(dbo.peserta_didik.nisn) ~ '^[0-9]{10}$' THEN 1 ELSE 0 END AS nisn_valid,
    sekolah_dim.npsn,
    sekolah_dim.nama_sekolah,
    sekolah_dim.jenjang,
    sekolah_dim.kecamatan
  FROM dbo.registrasi_peserta_didik
  LEFT JOIN dbo.peserta_didik
    ON dbo.peserta_didik.peserta_didik_id = dbo.registrasi_peserta_didik.peserta_didik_id
  JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.registrasi_peserta_didik.sekolah_id
  WHERE dbo.registrasi_peserta_didik."Soft_delete" = 0
    AND dbo.registrasi_peserta_didik.jenis_keluar_id IS NULL
)
SELECT ROUND(
         100.0 * COUNT(*) FILTER (WHERE nisn_valid = 1) / NULLIF(COUNT(*), 0),
         2
       ) AS persen_nisn_valid
FROM (SELECT DISTINCT peserta_didik_id, nisn_valid FROM pd_all) AS pd_unik
```


#### Q-EXE-08 · Sebaran Sekolah per Jenjang

- **Tab**: Eksekutif
- **Tipe visualisasi**: Bar (vertikal)
- **Definisi**: Jumlah sekolah aktif untuk setiap bentuk pendidikan (SD, SMP, TK, KB, PKBM, TPA, SKB).
- **Filter**: Kecamatan, Desa, Jenjang, Status
- **Ekspektasi (data saat ini)**: 7 baris — teratas: SD · 357

```sql
WITH sekolah_dim AS (
  SELECT
    dbo.sekolah.sekolah_id,
    dbo.sekolah.npsn,
    dbo.sekolah.nama AS nama_sekolah,
    ref.mst_wilayah.nama AS kecamatan,
    dbo.sekolah.desa_kelurahan AS desa,
    ref.bentuk_pendidikan.nama AS jenjang,
    CASE dbo.sekolah.status_sekolah WHEN 1 THEN 'Negeri' ELSE 'Swasta' END AS status_sekolah,
    COALESCE(ref.status_kepemilikan.nama, 'Tidak diketahui') AS kepemilikan
  FROM dbo.sekolah
  JOIN ref.mst_wilayah
    ON ref.mst_wilayah.kode_wilayah = COALESCE(
         (SELECT CASE WHEN w.id_level_wilayah = 4 THEN w.mst_kode_wilayah ELSE w.kode_wilayah END
            FROM ref.mst_wilayah w
           WHERE w.kode_wilayah = dbo.sekolah.kode_wilayah),
         dbo.sekolah.kode_wilayah)
  JOIN ref.bentuk_pendidikan
    ON ref.bentuk_pendidikan.bentuk_pendidikan_id = dbo.sekolah.bentuk_pendidikan_id
  LEFT JOIN ref.status_kepemilikan
    ON ref.status_kepemilikan.status_kepemilikan_id = dbo.sekolah.status_kepemilikan_id
  WHERE dbo.sekolah.keaktifan = 1
    AND dbo.sekolah."Soft_delete" = 0
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
  [[AND {{status_sekolah}}]]
)
SELECT jenjang, COUNT(*) AS jumlah_sekolah
FROM sekolah_dim
GROUP BY jenjang
ORDER BY jumlah_sekolah DESC
```


### Tab 2 — Sekolah (8 kartu)

Agregasi dan insight satuan pendidikan aktif, termasuk daftar sekolah dan kualitas data.

**Tata letak yang disarankan**: Grafik 12×8 (baris 0 dan 8), grafik 8×8 (baris 16), tabel 24×10 (baris 24).


| ID | Judul | Tipe | Ekspektasi |
|---|---|---|---|
| Q-SEK-01 | Jumlah Sekolah per Kecamatan | Bar (horizontal) | 8 baris — teratas: Kec. Karangasem · 115 |
| Q-SEK-02 | Sekolah per Jenjang dan Status | Bar bertumpuk | 10 baris — teratas: KB · Swasta · 39 |
| Q-SEK-03 | Sebaran Akreditasi Sekolah | Bar / Donut | 5 baris — teratas: B · 346 |
| Q-SEK-04 | Sekolah per Status Kepemilikan | Bar (horizontal) | 5 baris — teratas: Pemerintah Daerah · 318 |
| Q-SEK-05 | Rata-rata Rombel per Sekolah | Bar | 7 baris — teratas: SMP · 48 · 680 · 14.2 |
| Q-SEK-06 | Sekolah Tanpa Kepala Sekolah | Tabel | 16 baris — teratas: KB SURYA KUMARA · 69759372 · KB · Swasta · Kec. Abang · Pidpid |
| Q-SEK-07 | Sekolah Tanpa Rombel | Tabel | 5 baris — teratas: TK WERDHI KUMARA · 69975165 · TK · Swasta · Kec. Bebandem · Jungutan |
| Q-SEK-08 | Daftar Sekolah dan Jumlah Rombel, PD, PTK | Tabel | 604 baris — teratas: SMP NEGERI 2 AMLAPURA · 50102807 · SMP · Negeri · Kec. Karangasem · Subagan · 32 · 1410 · 67 |



#### Q-SEK-01 · Jumlah Sekolah per Kecamatan

- **Tab**: Sekolah
- **Tipe visualisasi**: Bar (horizontal)
- **Definisi**: Sebaran jumlah sekolah aktif menurut kecamatan, diurutkan dari yang terbanyak.
- **Filter**: Kecamatan, Desa, Jenjang, Status
- **Ekspektasi (data saat ini)**: 8 baris — teratas: Kec. Karangasem · 115

```sql
WITH sekolah_dim AS (
  SELECT
    dbo.sekolah.sekolah_id,
    dbo.sekolah.npsn,
    dbo.sekolah.nama AS nama_sekolah,
    ref.mst_wilayah.nama AS kecamatan,
    dbo.sekolah.desa_kelurahan AS desa,
    ref.bentuk_pendidikan.nama AS jenjang,
    CASE dbo.sekolah.status_sekolah WHEN 1 THEN 'Negeri' ELSE 'Swasta' END AS status_sekolah,
    COALESCE(ref.status_kepemilikan.nama, 'Tidak diketahui') AS kepemilikan
  FROM dbo.sekolah
  JOIN ref.mst_wilayah
    ON ref.mst_wilayah.kode_wilayah = COALESCE(
         (SELECT CASE WHEN w.id_level_wilayah = 4 THEN w.mst_kode_wilayah ELSE w.kode_wilayah END
            FROM ref.mst_wilayah w
           WHERE w.kode_wilayah = dbo.sekolah.kode_wilayah),
         dbo.sekolah.kode_wilayah)
  JOIN ref.bentuk_pendidikan
    ON ref.bentuk_pendidikan.bentuk_pendidikan_id = dbo.sekolah.bentuk_pendidikan_id
  LEFT JOIN ref.status_kepemilikan
    ON ref.status_kepemilikan.status_kepemilikan_id = dbo.sekolah.status_kepemilikan_id
  WHERE dbo.sekolah.keaktifan = 1
    AND dbo.sekolah."Soft_delete" = 0
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
  [[AND {{status_sekolah}}]]
)
SELECT kecamatan, COUNT(*) AS jumlah_sekolah
FROM sekolah_dim
GROUP BY kecamatan
ORDER BY jumlah_sekolah DESC
```


#### Q-SEK-02 · Sekolah per Jenjang dan Status

- **Tab**: Sekolah
- **Tipe visualisasi**: Bar bertumpuk
- **Definisi**: Jumlah sekolah aktif per jenjang, dipecah Negeri dan Swasta.
- **Filter**: Kecamatan, Desa, Jenjang, Status
- **Ekspektasi (data saat ini)**: 10 baris — teratas: KB · Swasta · 39

```sql
WITH sekolah_dim AS (
  SELECT
    dbo.sekolah.sekolah_id,
    dbo.sekolah.npsn,
    dbo.sekolah.nama AS nama_sekolah,
    ref.mst_wilayah.nama AS kecamatan,
    dbo.sekolah.desa_kelurahan AS desa,
    ref.bentuk_pendidikan.nama AS jenjang,
    CASE dbo.sekolah.status_sekolah WHEN 1 THEN 'Negeri' ELSE 'Swasta' END AS status_sekolah,
    COALESCE(ref.status_kepemilikan.nama, 'Tidak diketahui') AS kepemilikan
  FROM dbo.sekolah
  JOIN ref.mst_wilayah
    ON ref.mst_wilayah.kode_wilayah = COALESCE(
         (SELECT CASE WHEN w.id_level_wilayah = 4 THEN w.mst_kode_wilayah ELSE w.kode_wilayah END
            FROM ref.mst_wilayah w
           WHERE w.kode_wilayah = dbo.sekolah.kode_wilayah),
         dbo.sekolah.kode_wilayah)
  JOIN ref.bentuk_pendidikan
    ON ref.bentuk_pendidikan.bentuk_pendidikan_id = dbo.sekolah.bentuk_pendidikan_id
  LEFT JOIN ref.status_kepemilikan
    ON ref.status_kepemilikan.status_kepemilikan_id = dbo.sekolah.status_kepemilikan_id
  WHERE dbo.sekolah.keaktifan = 1
    AND dbo.sekolah."Soft_delete" = 0
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
  [[AND {{status_sekolah}}]]
)
SELECT jenjang, status_sekolah, COUNT(*) AS jumlah_sekolah
FROM sekolah_dim
GROUP BY jenjang, status_sekolah
ORDER BY jenjang, status_sekolah
```


#### Q-SEK-03 · Sebaran Akreditasi Sekolah

- **Tab**: Sekolah
- **Tipe visualisasi**: Bar / Donut
- **Definisi**: Akreditasi terakhir tiap sekolah (A, B, C, Terakreditasi, atau Belum terdata), diambil dari `dbo.akreditasi_sp` dengan `tanggal_sk` terbaru per sekolah.
- **Filter**: Kecamatan, Desa, Jenjang, Status
- **Ekspektasi (data saat ini)**: 5 baris — teratas: B · 346
- **Catatan**: Satu sekolah dapat punya beberapa SK akreditasi; hanya SK terbaru yang dipakai.

```sql
WITH sekolah_dim AS (
  SELECT
    dbo.sekolah.sekolah_id,
    dbo.sekolah.npsn,
    dbo.sekolah.nama AS nama_sekolah,
    ref.mst_wilayah.nama AS kecamatan,
    dbo.sekolah.desa_kelurahan AS desa,
    ref.bentuk_pendidikan.nama AS jenjang,
    CASE dbo.sekolah.status_sekolah WHEN 1 THEN 'Negeri' ELSE 'Swasta' END AS status_sekolah,
    COALESCE(ref.status_kepemilikan.nama, 'Tidak diketahui') AS kepemilikan
  FROM dbo.sekolah
  JOIN ref.mst_wilayah
    ON ref.mst_wilayah.kode_wilayah = COALESCE(
         (SELECT CASE WHEN w.id_level_wilayah = 4 THEN w.mst_kode_wilayah ELSE w.kode_wilayah END
            FROM ref.mst_wilayah w
           WHERE w.kode_wilayah = dbo.sekolah.kode_wilayah),
         dbo.sekolah.kode_wilayah)
  JOIN ref.bentuk_pendidikan
    ON ref.bentuk_pendidikan.bentuk_pendidikan_id = dbo.sekolah.bentuk_pendidikan_id
  LEFT JOIN ref.status_kepemilikan
    ON ref.status_kepemilikan.status_kepemilikan_id = dbo.sekolah.status_kepemilikan_id
  WHERE dbo.sekolah.keaktifan = 1
    AND dbo.sekolah."Soft_delete" = 0
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
  [[AND {{status_sekolah}}]]
),
akreditasi_terakhir AS (
  SELECT DISTINCT ON (dbo.akreditasi_sp.sekolah_id)
         dbo.akreditasi_sp.sekolah_id,
         ref.akreditasi.nama AS akreditasi
  FROM dbo.akreditasi_sp
  LEFT JOIN ref.akreditasi ON ref.akreditasi.akreditasi_id = dbo.akreditasi_sp.akreditasi_id
  ORDER BY dbo.akreditasi_sp.sekolah_id, dbo.akreditasi_sp.tanggal_sk DESC NULLS LAST
)
SELECT COALESCE(akreditasi_terakhir.akreditasi, 'Belum terdata') AS akreditasi,
       COUNT(*) AS jumlah_sekolah
FROM sekolah_dim
LEFT JOIN akreditasi_terakhir ON akreditasi_terakhir.sekolah_id = sekolah_dim.sekolah_id
GROUP BY 1
ORDER BY 2 DESC
```


#### Q-SEK-04 · Sekolah per Status Kepemilikan

- **Tab**: Sekolah
- **Tipe visualisasi**: Bar (horizontal)
- **Definisi**: Jumlah sekolah aktif menurut status kepemilikan aset (Pemerintah Daerah, Yayasan, dan sebagainya).
- **Filter**: Kecamatan, Desa, Jenjang, Status
- **Ekspektasi (data saat ini)**: 5 baris — teratas: Pemerintah Daerah · 318

```sql
WITH sekolah_dim AS (
  SELECT
    dbo.sekolah.sekolah_id,
    dbo.sekolah.npsn,
    dbo.sekolah.nama AS nama_sekolah,
    ref.mst_wilayah.nama AS kecamatan,
    dbo.sekolah.desa_kelurahan AS desa,
    ref.bentuk_pendidikan.nama AS jenjang,
    CASE dbo.sekolah.status_sekolah WHEN 1 THEN 'Negeri' ELSE 'Swasta' END AS status_sekolah,
    COALESCE(ref.status_kepemilikan.nama, 'Tidak diketahui') AS kepemilikan
  FROM dbo.sekolah
  JOIN ref.mst_wilayah
    ON ref.mst_wilayah.kode_wilayah = COALESCE(
         (SELECT CASE WHEN w.id_level_wilayah = 4 THEN w.mst_kode_wilayah ELSE w.kode_wilayah END
            FROM ref.mst_wilayah w
           WHERE w.kode_wilayah = dbo.sekolah.kode_wilayah),
         dbo.sekolah.kode_wilayah)
  JOIN ref.bentuk_pendidikan
    ON ref.bentuk_pendidikan.bentuk_pendidikan_id = dbo.sekolah.bentuk_pendidikan_id
  LEFT JOIN ref.status_kepemilikan
    ON ref.status_kepemilikan.status_kepemilikan_id = dbo.sekolah.status_kepemilikan_id
  WHERE dbo.sekolah.keaktifan = 1
    AND dbo.sekolah."Soft_delete" = 0
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
  [[AND {{status_sekolah}}]]
)
SELECT kepemilikan, COUNT(*) AS jumlah_sekolah
FROM sekolah_dim
GROUP BY kepemilikan
ORDER BY jumlah_sekolah DESC
```


#### Q-SEK-05 · Rata-rata Rombel per Sekolah

- **Tab**: Sekolah
- **Tipe visualisasi**: Bar
- **Definisi**: Untuk setiap jenjang: total rombel kelas dibagi jumlah sekolah aktif. Menunjukkan rata-rata jumlah rombel yang dikelola satu sekolah.
- **Filter**: Kecamatan, Desa, Jenjang, Status
- **Ekspektasi (data saat ini)**: 7 baris — teratas: SMP · 48 · 680 · 14.2

```sql
WITH sekolah_dim AS (
  SELECT
    dbo.sekolah.sekolah_id,
    dbo.sekolah.npsn,
    dbo.sekolah.nama AS nama_sekolah,
    ref.mst_wilayah.nama AS kecamatan,
    dbo.sekolah.desa_kelurahan AS desa,
    ref.bentuk_pendidikan.nama AS jenjang,
    CASE dbo.sekolah.status_sekolah WHEN 1 THEN 'Negeri' ELSE 'Swasta' END AS status_sekolah,
    COALESCE(ref.status_kepemilikan.nama, 'Tidak diketahui') AS kepemilikan
  FROM dbo.sekolah
  JOIN ref.mst_wilayah
    ON ref.mst_wilayah.kode_wilayah = COALESCE(
         (SELECT CASE WHEN w.id_level_wilayah = 4 THEN w.mst_kode_wilayah ELSE w.kode_wilayah END
            FROM ref.mst_wilayah w
           WHERE w.kode_wilayah = dbo.sekolah.kode_wilayah),
         dbo.sekolah.kode_wilayah)
  JOIN ref.bentuk_pendidikan
    ON ref.bentuk_pendidikan.bentuk_pendidikan_id = dbo.sekolah.bentuk_pendidikan_id
  LEFT JOIN ref.status_kepemilikan
    ON ref.status_kepemilikan.status_kepemilikan_id = dbo.sekolah.status_kepemilikan_id
  WHERE dbo.sekolah.keaktifan = 1
    AND dbo.sekolah."Soft_delete" = 0
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
  [[AND {{status_sekolah}}]]
),
rombel_dim AS (
  SELECT
    dbo.rombongan_belajar.rombongan_belajar_id,
    dbo.rombongan_belajar.tingkat_pendidikan_id,
    dbo.rombongan_belajar.nama AS nama_rombel,
    dbo.rombongan_belajar.ptk_id AS wali_kelas_id,
    sekolah_dim.sekolah_id,
    sekolah_dim.npsn,
    sekolah_dim.nama_sekolah,
    sekolah_dim.jenjang,
    sekolah_dim.kecamatan
  FROM dbo.rombongan_belajar
  JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.rombongan_belajar.sekolah_id
  WHERE dbo.rombongan_belajar."Soft_delete" = 0
    AND dbo.rombongan_belajar.jenis_rombel = 1
    AND dbo.rombongan_belajar.semester_id = (SELECT semester_id FROM ref.semester WHERE periode_aktif = 1)
)
SELECT sekolah_dim.jenjang,
       COUNT(DISTINCT sekolah_dim.sekolah_id) AS jumlah_sekolah,
       COUNT(rombel_dim.rombongan_belajar_id) AS total_rombel,
       ROUND(COUNT(rombel_dim.rombongan_belajar_id)::numeric
             / NULLIF(COUNT(DISTINCT sekolah_dim.sekolah_id), 0), 1) AS rata_rombel_per_sekolah
FROM sekolah_dim
LEFT JOIN rombel_dim ON rombel_dim.sekolah_id = sekolah_dim.sekolah_id
GROUP BY sekolah_dim.jenjang
ORDER BY rata_rombel_per_sekolah DESC
```


#### Q-SEK-06 · Sekolah Tanpa Kepala Sekolah

- **Tab**: Sekolah
- **Tipe visualisasi**: Tabel
- **Definisi**: Daftar sekolah aktif yang tidak memiliki satu pun PTK aktif berjenis `Kepala Sekolah`. Kartu tindak lanjut untuk pengisian jabatan.
- **Filter**: Kecamatan, Desa, Jenjang, Status
- **Ekspektasi (data saat ini)**: 16 baris — teratas: KB SURYA KUMARA · 69759372 · KB · Swasta · Kec. Abang · Pidpid
- **Catatan**: Sengaja memakai **semua penugasan** (termasuk non-induk/PLT), bukan hanya penugasan induk: 15 baris Kepala Sekolah/PLT berstatus non-induk, sehingga tanpa pengecualian ini sekolahnya salah ditandai kosong. Nama sekolah aman untuk dashboard publik.

```sql
WITH sekolah_dim AS (
  SELECT
    dbo.sekolah.sekolah_id,
    dbo.sekolah.npsn,
    dbo.sekolah.nama AS nama_sekolah,
    ref.mst_wilayah.nama AS kecamatan,
    dbo.sekolah.desa_kelurahan AS desa,
    ref.bentuk_pendidikan.nama AS jenjang,
    CASE dbo.sekolah.status_sekolah WHEN 1 THEN 'Negeri' ELSE 'Swasta' END AS status_sekolah,
    COALESCE(ref.status_kepemilikan.nama, 'Tidak diketahui') AS kepemilikan
  FROM dbo.sekolah
  JOIN ref.mst_wilayah
    ON ref.mst_wilayah.kode_wilayah = COALESCE(
         (SELECT CASE WHEN w.id_level_wilayah = 4 THEN w.mst_kode_wilayah ELSE w.kode_wilayah END
            FROM ref.mst_wilayah w
           WHERE w.kode_wilayah = dbo.sekolah.kode_wilayah),
         dbo.sekolah.kode_wilayah)
  JOIN ref.bentuk_pendidikan
    ON ref.bentuk_pendidikan.bentuk_pendidikan_id = dbo.sekolah.bentuk_pendidikan_id
  LEFT JOIN ref.status_kepemilikan
    ON ref.status_kepemilikan.status_kepemilikan_id = dbo.sekolah.status_kepemilikan_id
  WHERE dbo.sekolah.keaktifan = 1
    AND dbo.sekolah."Soft_delete" = 0
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
  [[AND {{status_sekolah}}]]
),
ptk_all AS (
  SELECT
    dbo.ptk_terdaftar.ptk_id,
    dbo.ptk_terdaftar.sekolah_id,
    ref.jenis_ptk.jenis_ptk,
    ref.jabatan_ptk.jabatan_ptk
  FROM dbo.ptk_terdaftar
  JOIN qc.vld_ptk ON qc.vld_ptk.ptk_id = dbo.ptk_terdaftar.ptk_id
  JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.ptk_terdaftar.sekolah_id
  LEFT JOIN ref.jenis_ptk ON ref.jenis_ptk.jenis_ptk_id = dbo.ptk_terdaftar.jenis_ptk_id
  LEFT JOIN ref.jabatan_ptk ON ref.jabatan_ptk.jabatan_ptk_id = dbo.ptk_terdaftar.jabatan_ptk_id
  WHERE dbo.ptk_terdaftar."Soft_delete" = 0
    AND dbo.ptk_terdaftar.jenis_keluar_id IS NULL
    AND qc.vld_ptk.aktif = 1
)
SELECT sekolah_dim.nama_sekolah,
       sekolah_dim.npsn,
       sekolah_dim.jenjang,
       sekolah_dim.status_sekolah,
       sekolah_dim.kecamatan,
       sekolah_dim.desa
FROM sekolah_dim
WHERE NOT EXISTS (
        SELECT 1 FROM ptk_all
         WHERE ptk_all.sekolah_id = sekolah_dim.sekolah_id
           AND ptk_all.jenis_ptk = 'Kepala Sekolah'
      )
ORDER BY sekolah_dim.kecamatan, sekolah_dim.nama_sekolah
```


#### Q-SEK-07 · Sekolah Tanpa Rombel

- **Tab**: Sekolah
- **Tipe visualisasi**: Tabel
- **Definisi**: Daftar sekolah aktif yang tidak memiliki rombel kelas pada semester aktif. Biasanya menandakan sekolah baru, tidak beroperasi, atau data rombel belum diisi.
- **Filter**: Kecamatan, Desa, Jenjang, Status
- **Ekspektasi (data saat ini)**: 5 baris — teratas: TK WERDHI KUMARA · 69975165 · TK · Swasta · Kec. Bebandem · Jungutan

```sql
WITH sekolah_dim AS (
  SELECT
    dbo.sekolah.sekolah_id,
    dbo.sekolah.npsn,
    dbo.sekolah.nama AS nama_sekolah,
    ref.mst_wilayah.nama AS kecamatan,
    dbo.sekolah.desa_kelurahan AS desa,
    ref.bentuk_pendidikan.nama AS jenjang,
    CASE dbo.sekolah.status_sekolah WHEN 1 THEN 'Negeri' ELSE 'Swasta' END AS status_sekolah,
    COALESCE(ref.status_kepemilikan.nama, 'Tidak diketahui') AS kepemilikan
  FROM dbo.sekolah
  JOIN ref.mst_wilayah
    ON ref.mst_wilayah.kode_wilayah = COALESCE(
         (SELECT CASE WHEN w.id_level_wilayah = 4 THEN w.mst_kode_wilayah ELSE w.kode_wilayah END
            FROM ref.mst_wilayah w
           WHERE w.kode_wilayah = dbo.sekolah.kode_wilayah),
         dbo.sekolah.kode_wilayah)
  JOIN ref.bentuk_pendidikan
    ON ref.bentuk_pendidikan.bentuk_pendidikan_id = dbo.sekolah.bentuk_pendidikan_id
  LEFT JOIN ref.status_kepemilikan
    ON ref.status_kepemilikan.status_kepemilikan_id = dbo.sekolah.status_kepemilikan_id
  WHERE dbo.sekolah.keaktifan = 1
    AND dbo.sekolah."Soft_delete" = 0
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
  [[AND {{status_sekolah}}]]
),
rombel_dim AS (
  SELECT
    dbo.rombongan_belajar.rombongan_belajar_id,
    dbo.rombongan_belajar.tingkat_pendidikan_id,
    dbo.rombongan_belajar.nama AS nama_rombel,
    dbo.rombongan_belajar.ptk_id AS wali_kelas_id,
    sekolah_dim.sekolah_id,
    sekolah_dim.npsn,
    sekolah_dim.nama_sekolah,
    sekolah_dim.jenjang,
    sekolah_dim.kecamatan
  FROM dbo.rombongan_belajar
  JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.rombongan_belajar.sekolah_id
  WHERE dbo.rombongan_belajar."Soft_delete" = 0
    AND dbo.rombongan_belajar.jenis_rombel = 1
    AND dbo.rombongan_belajar.semester_id = (SELECT semester_id FROM ref.semester WHERE periode_aktif = 1)
)
SELECT sekolah_dim.nama_sekolah,
       sekolah_dim.npsn,
       sekolah_dim.jenjang,
       sekolah_dim.status_sekolah,
       sekolah_dim.kecamatan,
       sekolah_dim.desa
FROM sekolah_dim
WHERE NOT EXISTS (
        SELECT 1 FROM rombel_dim WHERE rombel_dim.sekolah_id = sekolah_dim.sekolah_id
      )
ORDER BY sekolah_dim.kecamatan, sekolah_dim.nama_sekolah
```


#### Q-SEK-08 · Daftar Sekolah dan Jumlah Rombel, PD, PTK

- **Tab**: Sekolah
- **Tipe visualisasi**: Tabel
- **Definisi**: Satu baris per sekolah aktif berisi identitas sekolah, kecamatan, desa, jenjang, status, serta jumlah rombel, peserta didik, dan PTK aktif. Kartu rujukan (drill-down) untuk tab Sekolah.
- **Filter**: Kecamatan, Desa, Jenjang, Status
- **Ekspektasi (data saat ini)**: 604 baris — teratas: SMP NEGERI 2 AMLAPURA · 50102807 · SMP · Negeri · Kec. Karangasem · Subagan · 32 · 1410 · 67
- **Catatan**: Tabel terbesar di tab ini; gunakan paginasi bawaan Metabase. Subquery berkorelasi dipakai agar tidak terjadi fan-out join. Bila kolom PD dijumlahkan hasilnya 69.326, lebih besar dari total kabupaten (68.952) karena 355 murid terdaftar di dua sekolah.

```sql
WITH sekolah_dim AS (
  SELECT
    dbo.sekolah.sekolah_id,
    dbo.sekolah.npsn,
    dbo.sekolah.nama AS nama_sekolah,
    ref.mst_wilayah.nama AS kecamatan,
    dbo.sekolah.desa_kelurahan AS desa,
    ref.bentuk_pendidikan.nama AS jenjang,
    CASE dbo.sekolah.status_sekolah WHEN 1 THEN 'Negeri' ELSE 'Swasta' END AS status_sekolah,
    COALESCE(ref.status_kepemilikan.nama, 'Tidak diketahui') AS kepemilikan
  FROM dbo.sekolah
  JOIN ref.mst_wilayah
    ON ref.mst_wilayah.kode_wilayah = COALESCE(
         (SELECT CASE WHEN w.id_level_wilayah = 4 THEN w.mst_kode_wilayah ELSE w.kode_wilayah END
            FROM ref.mst_wilayah w
           WHERE w.kode_wilayah = dbo.sekolah.kode_wilayah),
         dbo.sekolah.kode_wilayah)
  JOIN ref.bentuk_pendidikan
    ON ref.bentuk_pendidikan.bentuk_pendidikan_id = dbo.sekolah.bentuk_pendidikan_id
  LEFT JOIN ref.status_kepemilikan
    ON ref.status_kepemilikan.status_kepemilikan_id = dbo.sekolah.status_kepemilikan_id
  WHERE dbo.sekolah.keaktifan = 1
    AND dbo.sekolah."Soft_delete" = 0
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
  [[AND {{status_sekolah}}]]
),
rombel_dim AS (
  SELECT
    dbo.rombongan_belajar.rombongan_belajar_id,
    dbo.rombongan_belajar.tingkat_pendidikan_id,
    dbo.rombongan_belajar.nama AS nama_rombel,
    dbo.rombongan_belajar.ptk_id AS wali_kelas_id,
    sekolah_dim.sekolah_id,
    sekolah_dim.npsn,
    sekolah_dim.nama_sekolah,
    sekolah_dim.jenjang,
    sekolah_dim.kecamatan
  FROM dbo.rombongan_belajar
  JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.rombongan_belajar.sekolah_id
  WHERE dbo.rombongan_belajar."Soft_delete" = 0
    AND dbo.rombongan_belajar.jenis_rombel = 1
    AND dbo.rombongan_belajar.semester_id = (SELECT semester_id FROM ref.semester WHERE periode_aktif = 1)
),
pd_dim AS (
  SELECT
    dbo.registrasi_peserta_didik.peserta_didik_id,
    TRIM(dbo.peserta_didik.nisn) AS nisn,
    dbo.registrasi_peserta_didik.sekolah_id,
    dbo.registrasi_peserta_didik.nipd,
    sekolah_dim.npsn,
    sekolah_dim.nama_sekolah,
    sekolah_dim.jenjang,
    sekolah_dim.kecamatan
  FROM dbo.registrasi_peserta_didik
  JOIN dbo.peserta_didik
    ON dbo.peserta_didik.peserta_didik_id = dbo.registrasi_peserta_didik.peserta_didik_id
  JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.registrasi_peserta_didik.sekolah_id
  WHERE dbo.registrasi_peserta_didik."Soft_delete" = 0
    AND dbo.registrasi_peserta_didik.jenis_keluar_id IS NULL
    AND TRIM(dbo.peserta_didik.nisn) ~ '^[0-9]{10}$'
),
ptk_dim AS (
  SELECT
    dbo.ptk_terdaftar.ptk_id,
    dbo.ptk_terdaftar.sekolah_id,
    ref.jenis_ptk.jenis_ptk,
    ref.jabatan_ptk.jabatan_ptk
  FROM dbo.ptk_terdaftar
  JOIN qc.vld_ptk ON qc.vld_ptk.ptk_id = dbo.ptk_terdaftar.ptk_id
  JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.ptk_terdaftar.sekolah_id
  LEFT JOIN ref.jenis_ptk ON ref.jenis_ptk.jenis_ptk_id = dbo.ptk_terdaftar.jenis_ptk_id
  LEFT JOIN ref.jabatan_ptk ON ref.jabatan_ptk.jabatan_ptk_id = dbo.ptk_terdaftar.jabatan_ptk_id
  WHERE dbo.ptk_terdaftar."Soft_delete" = 0
    AND dbo.ptk_terdaftar.jenis_keluar_id IS NULL
    AND qc.vld_ptk.aktif = 1
    AND dbo.ptk_terdaftar.ptk_induk = 1
)
SELECT sekolah_dim.nama_sekolah,
       sekolah_dim.npsn,
       sekolah_dim.jenjang,
       sekolah_dim.status_sekolah,
       sekolah_dim.kecamatan,
       sekolah_dim.desa,
       (SELECT COUNT(*) FROM rombel_dim
         WHERE rombel_dim.sekolah_id = sekolah_dim.sekolah_id) AS jumlah_rombel,
       (SELECT COUNT(DISTINCT nisn) FROM pd_dim
         WHERE pd_dim.sekolah_id = sekolah_dim.sekolah_id) AS jumlah_pd,
       (SELECT COUNT(DISTINCT ptk_id) FROM ptk_dim
         WHERE ptk_dim.sekolah_id = sekolah_dim.sekolah_id) AS jumlah_ptk
FROM sekolah_dim
ORDER BY jumlah_pd DESC
```


### Tab 3 — Rombel (7 kartu)

Agregasi dan insight rombel aktif bertipe Kelas pada semester aktif, termasuk kepatuhan terhadap standar ukuran rombel.

**Tata letak yang disarankan**: KPI 6×4 (baris 0), grafik 12×8 (baris 4), grafik 24×8 (baris 12), tabel 24×10 (baris 20).


| ID | Judul | Tipe | Ekspektasi |
|---|---|---|---|
| Q-ROM-01 | Total Rombel Aktif (Kelas) | Scalar | 3482 |
| Q-ROM-02 | Rombel per Jenjang | Bar | 7 baris — teratas: SD · 2246 |
| Q-ROM-03 | Rombel per Kecamatan | Bar (horizontal) | 8 baris — teratas: Kec. Karangasem · 724 |
| Q-ROM-04 | Rata-rata Murid per Rombel per Jenjang | Bar | 7 baris — teratas: SMP · 680 · 25.0 |
| Q-ROM-05 | Kepatuhan Ukuran Rombel terhadap Standar per Jenjang | Bar bertumpuk | 13 baris — teratas: KB · 01. Sesuai standar · 49 |
| Q-ROM-06 | Rombel Melebihi Standar per Jenjang | Tabel | 448 baris — teratas: PKBM MANDIRI BINA CIPTA · P9970471 · PKBM · Kec. Karangasem · Paket C 12 · 47 · 30 · 17 |
| Q-ROM-07 | Rombel dengan Wali Kelas Tidak Aktif | Tabel | 11 baris — teratas: SKB KARANGASEM · P2967017 · SKB · Kec. Karangasem · 4 |



#### Q-ROM-01 · Total Rombel Aktif (Kelas)

- **Tab**: Rombel
- **Tipe visualisasi**: Scalar
- **Definisi**: Jumlah rombel kelas pada semester aktif di sekolah aktif (KPI pembuka tab Rombel).
- **Filter**: Kecamatan, Desa, Jenjang, Status
- **Ekspektasi (data saat ini)**: 3482

```sql
WITH sekolah_dim AS (
  SELECT
    dbo.sekolah.sekolah_id,
    dbo.sekolah.npsn,
    dbo.sekolah.nama AS nama_sekolah,
    ref.mst_wilayah.nama AS kecamatan,
    dbo.sekolah.desa_kelurahan AS desa,
    ref.bentuk_pendidikan.nama AS jenjang,
    CASE dbo.sekolah.status_sekolah WHEN 1 THEN 'Negeri' ELSE 'Swasta' END AS status_sekolah,
    COALESCE(ref.status_kepemilikan.nama, 'Tidak diketahui') AS kepemilikan
  FROM dbo.sekolah
  JOIN ref.mst_wilayah
    ON ref.mst_wilayah.kode_wilayah = COALESCE(
         (SELECT CASE WHEN w.id_level_wilayah = 4 THEN w.mst_kode_wilayah ELSE w.kode_wilayah END
            FROM ref.mst_wilayah w
           WHERE w.kode_wilayah = dbo.sekolah.kode_wilayah),
         dbo.sekolah.kode_wilayah)
  JOIN ref.bentuk_pendidikan
    ON ref.bentuk_pendidikan.bentuk_pendidikan_id = dbo.sekolah.bentuk_pendidikan_id
  LEFT JOIN ref.status_kepemilikan
    ON ref.status_kepemilikan.status_kepemilikan_id = dbo.sekolah.status_kepemilikan_id
  WHERE dbo.sekolah.keaktifan = 1
    AND dbo.sekolah."Soft_delete" = 0
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
  [[AND {{status_sekolah}}]]
),
rombel_dim AS (
  SELECT
    dbo.rombongan_belajar.rombongan_belajar_id,
    dbo.rombongan_belajar.tingkat_pendidikan_id,
    dbo.rombongan_belajar.nama AS nama_rombel,
    dbo.rombongan_belajar.ptk_id AS wali_kelas_id,
    sekolah_dim.sekolah_id,
    sekolah_dim.npsn,
    sekolah_dim.nama_sekolah,
    sekolah_dim.jenjang,
    sekolah_dim.kecamatan
  FROM dbo.rombongan_belajar
  JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.rombongan_belajar.sekolah_id
  WHERE dbo.rombongan_belajar."Soft_delete" = 0
    AND dbo.rombongan_belajar.jenis_rombel = 1
    AND dbo.rombongan_belajar.semester_id = (SELECT semester_id FROM ref.semester WHERE periode_aktif = 1)
)
SELECT COUNT(*) AS total_rombel
FROM rombel_dim
```


#### Q-ROM-02 · Rombel per Jenjang

- **Tab**: Rombel
- **Tipe visualisasi**: Bar
- **Definisi**: Jumlah rombel kelas pada setiap jenjang pendidikan.
- **Filter**: Kecamatan, Desa, Jenjang, Status
- **Ekspektasi (data saat ini)**: 7 baris — teratas: SD · 2246

```sql
WITH sekolah_dim AS (
  SELECT
    dbo.sekolah.sekolah_id,
    dbo.sekolah.npsn,
    dbo.sekolah.nama AS nama_sekolah,
    ref.mst_wilayah.nama AS kecamatan,
    dbo.sekolah.desa_kelurahan AS desa,
    ref.bentuk_pendidikan.nama AS jenjang,
    CASE dbo.sekolah.status_sekolah WHEN 1 THEN 'Negeri' ELSE 'Swasta' END AS status_sekolah,
    COALESCE(ref.status_kepemilikan.nama, 'Tidak diketahui') AS kepemilikan
  FROM dbo.sekolah
  JOIN ref.mst_wilayah
    ON ref.mst_wilayah.kode_wilayah = COALESCE(
         (SELECT CASE WHEN w.id_level_wilayah = 4 THEN w.mst_kode_wilayah ELSE w.kode_wilayah END
            FROM ref.mst_wilayah w
           WHERE w.kode_wilayah = dbo.sekolah.kode_wilayah),
         dbo.sekolah.kode_wilayah)
  JOIN ref.bentuk_pendidikan
    ON ref.bentuk_pendidikan.bentuk_pendidikan_id = dbo.sekolah.bentuk_pendidikan_id
  LEFT JOIN ref.status_kepemilikan
    ON ref.status_kepemilikan.status_kepemilikan_id = dbo.sekolah.status_kepemilikan_id
  WHERE dbo.sekolah.keaktifan = 1
    AND dbo.sekolah."Soft_delete" = 0
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
  [[AND {{status_sekolah}}]]
),
rombel_dim AS (
  SELECT
    dbo.rombongan_belajar.rombongan_belajar_id,
    dbo.rombongan_belajar.tingkat_pendidikan_id,
    dbo.rombongan_belajar.nama AS nama_rombel,
    dbo.rombongan_belajar.ptk_id AS wali_kelas_id,
    sekolah_dim.sekolah_id,
    sekolah_dim.npsn,
    sekolah_dim.nama_sekolah,
    sekolah_dim.jenjang,
    sekolah_dim.kecamatan
  FROM dbo.rombongan_belajar
  JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.rombongan_belajar.sekolah_id
  WHERE dbo.rombongan_belajar."Soft_delete" = 0
    AND dbo.rombongan_belajar.jenis_rombel = 1
    AND dbo.rombongan_belajar.semester_id = (SELECT semester_id FROM ref.semester WHERE periode_aktif = 1)
)
SELECT jenjang, COUNT(*) AS jumlah_rombel
FROM rombel_dim
GROUP BY jenjang
ORDER BY jumlah_rombel DESC
```


#### Q-ROM-03 · Rombel per Kecamatan

- **Tab**: Rombel
- **Tipe visualisasi**: Bar (horizontal)
- **Definisi**: Jumlah rombel kelas pada setiap kecamatan.
- **Filter**: Kecamatan, Desa, Jenjang, Status
- **Ekspektasi (data saat ini)**: 8 baris — teratas: Kec. Karangasem · 724

```sql
WITH sekolah_dim AS (
  SELECT
    dbo.sekolah.sekolah_id,
    dbo.sekolah.npsn,
    dbo.sekolah.nama AS nama_sekolah,
    ref.mst_wilayah.nama AS kecamatan,
    dbo.sekolah.desa_kelurahan AS desa,
    ref.bentuk_pendidikan.nama AS jenjang,
    CASE dbo.sekolah.status_sekolah WHEN 1 THEN 'Negeri' ELSE 'Swasta' END AS status_sekolah,
    COALESCE(ref.status_kepemilikan.nama, 'Tidak diketahui') AS kepemilikan
  FROM dbo.sekolah
  JOIN ref.mst_wilayah
    ON ref.mst_wilayah.kode_wilayah = COALESCE(
         (SELECT CASE WHEN w.id_level_wilayah = 4 THEN w.mst_kode_wilayah ELSE w.kode_wilayah END
            FROM ref.mst_wilayah w
           WHERE w.kode_wilayah = dbo.sekolah.kode_wilayah),
         dbo.sekolah.kode_wilayah)
  JOIN ref.bentuk_pendidikan
    ON ref.bentuk_pendidikan.bentuk_pendidikan_id = dbo.sekolah.bentuk_pendidikan_id
  LEFT JOIN ref.status_kepemilikan
    ON ref.status_kepemilikan.status_kepemilikan_id = dbo.sekolah.status_kepemilikan_id
  WHERE dbo.sekolah.keaktifan = 1
    AND dbo.sekolah."Soft_delete" = 0
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
  [[AND {{status_sekolah}}]]
),
rombel_dim AS (
  SELECT
    dbo.rombongan_belajar.rombongan_belajar_id,
    dbo.rombongan_belajar.tingkat_pendidikan_id,
    dbo.rombongan_belajar.nama AS nama_rombel,
    dbo.rombongan_belajar.ptk_id AS wali_kelas_id,
    sekolah_dim.sekolah_id,
    sekolah_dim.npsn,
    sekolah_dim.nama_sekolah,
    sekolah_dim.jenjang,
    sekolah_dim.kecamatan
  FROM dbo.rombongan_belajar
  JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.rombongan_belajar.sekolah_id
  WHERE dbo.rombongan_belajar."Soft_delete" = 0
    AND dbo.rombongan_belajar.jenis_rombel = 1
    AND dbo.rombongan_belajar.semester_id = (SELECT semester_id FROM ref.semester WHERE periode_aktif = 1)
)
SELECT kecamatan, COUNT(*) AS jumlah_rombel
FROM rombel_dim
GROUP BY kecamatan
ORDER BY jumlah_rombel DESC
```


#### Q-ROM-04 · Rata-rata Murid per Rombel per Jenjang

- **Tab**: Rombel
- **Tipe visualisasi**: Bar
- **Definisi**: Untuk setiap jenjang: rata-rata jumlah murid per rombel kelas, dihitung dari `dbo.anggota_rombel` aktif yang terhubung ke rombel kelas aktif.
- **Filter**: Kecamatan, Desa, Jenjang, Status
- **Ekspektasi (data saat ini)**: 7 baris — teratas: SMP · 680 · 25.0
- **Catatan**: Angka rata-rata saja tidak cukup: bandingkan dengan standar per jenjang pada Q-ROM-05.

```sql
WITH sekolah_dim AS (
  SELECT
    dbo.sekolah.sekolah_id,
    dbo.sekolah.npsn,
    dbo.sekolah.nama AS nama_sekolah,
    ref.mst_wilayah.nama AS kecamatan,
    dbo.sekolah.desa_kelurahan AS desa,
    ref.bentuk_pendidikan.nama AS jenjang,
    CASE dbo.sekolah.status_sekolah WHEN 1 THEN 'Negeri' ELSE 'Swasta' END AS status_sekolah,
    COALESCE(ref.status_kepemilikan.nama, 'Tidak diketahui') AS kepemilikan
  FROM dbo.sekolah
  JOIN ref.mst_wilayah
    ON ref.mst_wilayah.kode_wilayah = COALESCE(
         (SELECT CASE WHEN w.id_level_wilayah = 4 THEN w.mst_kode_wilayah ELSE w.kode_wilayah END
            FROM ref.mst_wilayah w
           WHERE w.kode_wilayah = dbo.sekolah.kode_wilayah),
         dbo.sekolah.kode_wilayah)
  JOIN ref.bentuk_pendidikan
    ON ref.bentuk_pendidikan.bentuk_pendidikan_id = dbo.sekolah.bentuk_pendidikan_id
  LEFT JOIN ref.status_kepemilikan
    ON ref.status_kepemilikan.status_kepemilikan_id = dbo.sekolah.status_kepemilikan_id
  WHERE dbo.sekolah.keaktifan = 1
    AND dbo.sekolah."Soft_delete" = 0
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
  [[AND {{status_sekolah}}]]
),
rombel_dim AS (
  SELECT
    dbo.rombongan_belajar.rombongan_belajar_id,
    dbo.rombongan_belajar.tingkat_pendidikan_id,
    dbo.rombongan_belajar.nama AS nama_rombel,
    dbo.rombongan_belajar.ptk_id AS wali_kelas_id,
    sekolah_dim.sekolah_id,
    sekolah_dim.npsn,
    sekolah_dim.nama_sekolah,
    sekolah_dim.jenjang,
    sekolah_dim.kecamatan
  FROM dbo.rombongan_belajar
  JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.rombongan_belajar.sekolah_id
  WHERE dbo.rombongan_belajar."Soft_delete" = 0
    AND dbo.rombongan_belajar.jenis_rombel = 1
    AND dbo.rombongan_belajar.semester_id = (SELECT semester_id FROM ref.semester WHERE periode_aktif = 1)
),
anggota AS (
  SELECT dbo.anggota_rombel.rombongan_belajar_id,
         COUNT(DISTINCT dbo.anggota_rombel.peserta_didik_id) AS jumlah_pd
  FROM dbo.anggota_rombel
  JOIN rombel_dim ON rombel_dim.rombongan_belajar_id = dbo.anggota_rombel.rombongan_belajar_id
  WHERE dbo.anggota_rombel."Soft_delete" = 0
  GROUP BY 1
)
SELECT rombel_dim.jenjang,
       COUNT(*) AS jumlah_rombel,
       ROUND(AVG(anggota.jumlah_pd), 1) AS rata_murid_per_rombel
FROM anggota
JOIN rombel_dim ON rombel_dim.rombongan_belajar_id = anggota.rombongan_belajar_id
GROUP BY 1
ORDER BY rata_murid_per_rombel DESC
```


#### Q-ROM-05 · Kepatuhan Ukuran Rombel terhadap Standar per Jenjang

- **Tab**: Rombel
- **Tipe visualisasi**: Bar bertumpuk
- **Definisi**: Setiap rombel kelas dibandingkan dengan standar ukuran jenjangnya, lalu dihitung jumlah rombel yang **sesuai standar** dan **melebihi standar** per jenjang. Standar: TK/KB/TPA 15, SD 28, SMP 32, Paket A 20, Paket B 25, Paket C 30 (Kepmendikdasmen 14/2026).
- **Filter**: Kecamatan, Desa, Jenjang, Status
- **Ekspektasi (data saat ini)**: 13 baris — teratas: KB · 01. Sesuai standar · 49
- **Catatan**: Ambang berbeda per jenjang; memakai satu ambang 32 membuat TK/PAUD tidak pernah terdeteksi padat dan PKBM salah kategori.

```sql
WITH sekolah_dim AS (
  SELECT
    dbo.sekolah.sekolah_id,
    dbo.sekolah.npsn,
    dbo.sekolah.nama AS nama_sekolah,
    ref.mst_wilayah.nama AS kecamatan,
    dbo.sekolah.desa_kelurahan AS desa,
    ref.bentuk_pendidikan.nama AS jenjang,
    CASE dbo.sekolah.status_sekolah WHEN 1 THEN 'Negeri' ELSE 'Swasta' END AS status_sekolah,
    COALESCE(ref.status_kepemilikan.nama, 'Tidak diketahui') AS kepemilikan
  FROM dbo.sekolah
  JOIN ref.mst_wilayah
    ON ref.mst_wilayah.kode_wilayah = COALESCE(
         (SELECT CASE WHEN w.id_level_wilayah = 4 THEN w.mst_kode_wilayah ELSE w.kode_wilayah END
            FROM ref.mst_wilayah w
           WHERE w.kode_wilayah = dbo.sekolah.kode_wilayah),
         dbo.sekolah.kode_wilayah)
  JOIN ref.bentuk_pendidikan
    ON ref.bentuk_pendidikan.bentuk_pendidikan_id = dbo.sekolah.bentuk_pendidikan_id
  LEFT JOIN ref.status_kepemilikan
    ON ref.status_kepemilikan.status_kepemilikan_id = dbo.sekolah.status_kepemilikan_id
  WHERE dbo.sekolah.keaktifan = 1
    AND dbo.sekolah."Soft_delete" = 0
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
  [[AND {{status_sekolah}}]]
),
rombel_dim AS (
  SELECT
    dbo.rombongan_belajar.rombongan_belajar_id,
    dbo.rombongan_belajar.tingkat_pendidikan_id,
    dbo.rombongan_belajar.nama AS nama_rombel,
    dbo.rombongan_belajar.ptk_id AS wali_kelas_id,
    sekolah_dim.sekolah_id,
    sekolah_dim.npsn,
    sekolah_dim.nama_sekolah,
    sekolah_dim.jenjang,
    sekolah_dim.kecamatan
  FROM dbo.rombongan_belajar
  JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.rombongan_belajar.sekolah_id
  WHERE dbo.rombongan_belajar."Soft_delete" = 0
    AND dbo.rombongan_belajar.jenis_rombel = 1
    AND dbo.rombongan_belajar.semester_id = (SELECT semester_id FROM ref.semester WHERE periode_aktif = 1)
),
anggota AS (
  SELECT dbo.anggota_rombel.rombongan_belajar_id,
         COUNT(DISTINCT dbo.anggota_rombel.peserta_didik_id) AS jumlah_pd
  FROM dbo.anggota_rombel
  JOIN rombel_dim ON rombel_dim.rombongan_belajar_id = dbo.anggota_rombel.rombongan_belajar_id
  WHERE dbo.anggota_rombel."Soft_delete" = 0
  GROUP BY 1
),
ukuran AS (
  SELECT rombel_dim.jenjang,
         anggota.jumlah_pd,
         CASE
         WHEN rombel_dim.jenjang IN ('TK', 'KB', 'TPA') THEN 15
         WHEN rombel_dim.jenjang = 'SD' THEN 28
         WHEN rombel_dim.jenjang = 'SMP' THEN 32
         WHEN rombel_dim.jenjang IN ('PKBM', 'SKB')
              AND rombel_dim.tingkat_pendidikan_id BETWEEN 31 AND 36 THEN 20
         WHEN rombel_dim.jenjang IN ('PKBM', 'SKB')
              AND rombel_dim.tingkat_pendidikan_id BETWEEN 37 AND 39 THEN 25
         WHEN rombel_dim.jenjang IN ('PKBM', 'SKB')
              AND rombel_dim.tingkat_pendidikan_id BETWEEN 40 AND 42 THEN 30
         ELSE 32
       END AS standar_maks
  FROM anggota
  JOIN rombel_dim ON rombel_dim.rombongan_belajar_id = anggota.rombongan_belajar_id
)
SELECT jenjang,
       CASE WHEN jumlah_pd <= standar_maks THEN '01. Sesuai standar'
            ELSE '02. Melebihi standar'
       END AS kategori,
       COUNT(*) AS jumlah_rombel
FROM ukuran
GROUP BY 1, 2
ORDER BY 1, 2
```


#### Q-ROM-06 · Rombel Melebihi Standar per Jenjang

- **Tab**: Rombel
- **Tipe visualisasi**: Tabel
- **Definisi**: Daftar rombel kelas yang jumlah muridnya melebihi standar jenjangnya, beserta selisihnya. Standar: TK/KB/TPA 15, SD 28, SMP 32, Paket A 20, Paket B 25, Paket C 30. Diurutkan dari kelebihan terbesar.
- **Filter**: Kecamatan, Desa, Jenjang, Status
- **Ekspektasi (data saat ini)**: 448 baris — teratas: PKBM MANDIRI BINA CIPTA · P9970471 · PKBM · Kec. Karangasem · Paket C 12 · 47 · 30 · 17
- **Catatan**: Kartu tindak lanjut pemerataan rombel; ambang mengikuti jenjang, bukan satu angka.

```sql
WITH sekolah_dim AS (
  SELECT
    dbo.sekolah.sekolah_id,
    dbo.sekolah.npsn,
    dbo.sekolah.nama AS nama_sekolah,
    ref.mst_wilayah.nama AS kecamatan,
    dbo.sekolah.desa_kelurahan AS desa,
    ref.bentuk_pendidikan.nama AS jenjang,
    CASE dbo.sekolah.status_sekolah WHEN 1 THEN 'Negeri' ELSE 'Swasta' END AS status_sekolah,
    COALESCE(ref.status_kepemilikan.nama, 'Tidak diketahui') AS kepemilikan
  FROM dbo.sekolah
  JOIN ref.mst_wilayah
    ON ref.mst_wilayah.kode_wilayah = COALESCE(
         (SELECT CASE WHEN w.id_level_wilayah = 4 THEN w.mst_kode_wilayah ELSE w.kode_wilayah END
            FROM ref.mst_wilayah w
           WHERE w.kode_wilayah = dbo.sekolah.kode_wilayah),
         dbo.sekolah.kode_wilayah)
  JOIN ref.bentuk_pendidikan
    ON ref.bentuk_pendidikan.bentuk_pendidikan_id = dbo.sekolah.bentuk_pendidikan_id
  LEFT JOIN ref.status_kepemilikan
    ON ref.status_kepemilikan.status_kepemilikan_id = dbo.sekolah.status_kepemilikan_id
  WHERE dbo.sekolah.keaktifan = 1
    AND dbo.sekolah."Soft_delete" = 0
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
  [[AND {{status_sekolah}}]]
),
rombel_dim AS (
  SELECT
    dbo.rombongan_belajar.rombongan_belajar_id,
    dbo.rombongan_belajar.tingkat_pendidikan_id,
    dbo.rombongan_belajar.nama AS nama_rombel,
    dbo.rombongan_belajar.ptk_id AS wali_kelas_id,
    sekolah_dim.sekolah_id,
    sekolah_dim.npsn,
    sekolah_dim.nama_sekolah,
    sekolah_dim.jenjang,
    sekolah_dim.kecamatan
  FROM dbo.rombongan_belajar
  JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.rombongan_belajar.sekolah_id
  WHERE dbo.rombongan_belajar."Soft_delete" = 0
    AND dbo.rombongan_belajar.jenis_rombel = 1
    AND dbo.rombongan_belajar.semester_id = (SELECT semester_id FROM ref.semester WHERE periode_aktif = 1)
),
anggota AS (
  SELECT dbo.anggota_rombel.rombongan_belajar_id,
         COUNT(DISTINCT dbo.anggota_rombel.peserta_didik_id) AS jumlah_pd
  FROM dbo.anggota_rombel
  JOIN rombel_dim ON rombel_dim.rombongan_belajar_id = dbo.anggota_rombel.rombongan_belajar_id
  WHERE dbo.anggota_rombel."Soft_delete" = 0
  GROUP BY 1
),
ukuran AS (
  SELECT rombel_dim.nama_sekolah,
         rombel_dim.npsn,
         rombel_dim.jenjang,
         rombel_dim.kecamatan,
         rombel_dim.nama_rombel,
         anggota.jumlah_pd,
         CASE
         WHEN rombel_dim.jenjang IN ('TK', 'KB', 'TPA') THEN 15
         WHEN rombel_dim.jenjang = 'SD' THEN 28
         WHEN rombel_dim.jenjang = 'SMP' THEN 32
         WHEN rombel_dim.jenjang IN ('PKBM', 'SKB')
              AND rombel_dim.tingkat_pendidikan_id BETWEEN 31 AND 36 THEN 20
         WHEN rombel_dim.jenjang IN ('PKBM', 'SKB')
              AND rombel_dim.tingkat_pendidikan_id BETWEEN 37 AND 39 THEN 25
         WHEN rombel_dim.jenjang IN ('PKBM', 'SKB')
              AND rombel_dim.tingkat_pendidikan_id BETWEEN 40 AND 42 THEN 30
         ELSE 32
       END AS standar_maks
  FROM anggota
  JOIN rombel_dim ON rombel_dim.rombongan_belajar_id = anggota.rombongan_belajar_id
)
SELECT nama_sekolah,
       npsn,
       jenjang,
       kecamatan,
       nama_rombel,
       jumlah_pd,
       standar_maks,
       jumlah_pd - standar_maks AS kelebihan
FROM ukuran
WHERE jumlah_pd > standar_maks
ORDER BY kelebihan DESC, jumlah_pd DESC
```


#### Q-ROM-07 · Rombel dengan Wali Kelas Tidak Aktif

- **Tab**: Rombel
- **Tipe visualisasi**: Tabel
- **Definisi**: Daftar sekolah yang memiliki rombel kelas tanpa wali kelas aktif: `ptk_id` kosong, atau PTK-nya tidak lagi aktif (`qc.vld_ptk.aktif <> 1` / sudah keluar).
- **Filter**: Kecamatan, Desa, Jenjang, Status
- **Ekspektasi (data saat ini)**: 11 baris — teratas: SKB KARANGASEM · P2967017 · SKB · Kec. Karangasem · 4
- **Catatan**: Kartu kualitas data: wali kelas yang sudah mutasi/pensiun tetap tersimpan di `ptk_id`.

```sql
WITH sekolah_dim AS (
  SELECT
    dbo.sekolah.sekolah_id,
    dbo.sekolah.npsn,
    dbo.sekolah.nama AS nama_sekolah,
    ref.mst_wilayah.nama AS kecamatan,
    dbo.sekolah.desa_kelurahan AS desa,
    ref.bentuk_pendidikan.nama AS jenjang,
    CASE dbo.sekolah.status_sekolah WHEN 1 THEN 'Negeri' ELSE 'Swasta' END AS status_sekolah,
    COALESCE(ref.status_kepemilikan.nama, 'Tidak diketahui') AS kepemilikan
  FROM dbo.sekolah
  JOIN ref.mst_wilayah
    ON ref.mst_wilayah.kode_wilayah = COALESCE(
         (SELECT CASE WHEN w.id_level_wilayah = 4 THEN w.mst_kode_wilayah ELSE w.kode_wilayah END
            FROM ref.mst_wilayah w
           WHERE w.kode_wilayah = dbo.sekolah.kode_wilayah),
         dbo.sekolah.kode_wilayah)
  JOIN ref.bentuk_pendidikan
    ON ref.bentuk_pendidikan.bentuk_pendidikan_id = dbo.sekolah.bentuk_pendidikan_id
  LEFT JOIN ref.status_kepemilikan
    ON ref.status_kepemilikan.status_kepemilikan_id = dbo.sekolah.status_kepemilikan_id
  WHERE dbo.sekolah.keaktifan = 1
    AND dbo.sekolah."Soft_delete" = 0
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
  [[AND {{status_sekolah}}]]
),
rombel_dim AS (
  SELECT
    dbo.rombongan_belajar.rombongan_belajar_id,
    dbo.rombongan_belajar.tingkat_pendidikan_id,
    dbo.rombongan_belajar.nama AS nama_rombel,
    dbo.rombongan_belajar.ptk_id AS wali_kelas_id,
    sekolah_dim.sekolah_id,
    sekolah_dim.npsn,
    sekolah_dim.nama_sekolah,
    sekolah_dim.jenjang,
    sekolah_dim.kecamatan
  FROM dbo.rombongan_belajar
  JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.rombongan_belajar.sekolah_id
  WHERE dbo.rombongan_belajar."Soft_delete" = 0
    AND dbo.rombongan_belajar.jenis_rombel = 1
    AND dbo.rombongan_belajar.semester_id = (SELECT semester_id FROM ref.semester WHERE periode_aktif = 1)
)
SELECT rombel_dim.nama_sekolah,
       rombel_dim.npsn,
       rombel_dim.jenjang,
       rombel_dim.kecamatan,
       COUNT(*) AS rombel_tanpa_wali_aktif
FROM rombel_dim
WHERE rombel_dim.wali_kelas_id IS NULL
   OR NOT EXISTS (
        SELECT 1
        FROM dbo.ptk_terdaftar
        JOIN qc.vld_ptk ON qc.vld_ptk.ptk_id = dbo.ptk_terdaftar.ptk_id
        WHERE dbo.ptk_terdaftar.ptk_id = rombel_dim.wali_kelas_id
          AND dbo.ptk_terdaftar."Soft_delete" = 0
          AND dbo.ptk_terdaftar.jenis_keluar_id IS NULL
          AND qc.vld_ptk.aktif = 1
      )
GROUP BY 1, 2, 3, 4
ORDER BY rombel_tanpa_wali_aktif DESC
```


### Tab 4 — Peserta Didik (8 kartu)

Agregasi dan insight peserta didik aktif, identitas NISN, dan mobilitas.

**Tata letak yang disarankan**: KPI 6×4 (baris 0), grafik 12×8 (baris 4, 12, 20), tabel 24×10 (baris 28).


| ID | Judul | Tipe | Ekspektasi |
|---|---|---|---|
| Q-PD-01 | Total Peserta Didik Aktif | Scalar | 68952 |
| Q-PD-02 | Peserta Didik per Jenjang | Bar | 7 baris — teratas: SD · 40009 |
| Q-PD-03 | Peserta Didik per Kecamatan | Bar (horizontal) | 8 baris — teratas: Kec. Karangasem · 15245 |
| Q-PD-04 | Peserta Didik per Tingkat Kelas | Bar | 25 baris — teratas: Kelas 1 · 6319 |
| Q-PD-05 | Status NISN Peserta Didik | Bar (horizontal) | 2 baris — teratas: 01. NISN sah (dihitung) · 68952 |
| Q-PD-06 | Peserta Didik Keluar per Jenis Keluar | Bar | 7 baris — teratas: Mutasi · 107 |
| Q-PD-07 | Rata-rata Jarak dan Waktu Tempuh ke Sekolah | Tabel | 7 baris — teratas: SD · 39914 · 0.47 · 9.2 |
| Q-PD-08 | 20 Sekolah dengan Peserta Didik Terbanyak | Tabel | 20 baris — teratas: SMP NEGERI 2 AMLAPURA · 50102807 · SMP · Kec. Karangasem · 1410 |



#### Q-PD-01 · Total Peserta Didik Aktif

- **Tab**: Peserta Didik
- **Tipe visualisasi**: Scalar
- **Definisi**: Jumlah unik peserta didik aktif di sekolah aktif, dihitung per **NISN 10 digit** (KPI pembuka tab Peserta Didik).
- **Filter**: Kecamatan, Desa, Jenjang, Status
- **Ekspektasi (data saat ini)**: 68952
- **Catatan**: Karena kunci = NISN, murid yang terdaftar di dua sekolah dihitung sekali di tingkat kabupaten, tetapi tetap muncul di kedua sekolah pada Q-SEK-08/Q-PD-08.

```sql
WITH sekolah_dim AS (
  SELECT
    dbo.sekolah.sekolah_id,
    dbo.sekolah.npsn,
    dbo.sekolah.nama AS nama_sekolah,
    ref.mst_wilayah.nama AS kecamatan,
    dbo.sekolah.desa_kelurahan AS desa,
    ref.bentuk_pendidikan.nama AS jenjang,
    CASE dbo.sekolah.status_sekolah WHEN 1 THEN 'Negeri' ELSE 'Swasta' END AS status_sekolah,
    COALESCE(ref.status_kepemilikan.nama, 'Tidak diketahui') AS kepemilikan
  FROM dbo.sekolah
  JOIN ref.mst_wilayah
    ON ref.mst_wilayah.kode_wilayah = COALESCE(
         (SELECT CASE WHEN w.id_level_wilayah = 4 THEN w.mst_kode_wilayah ELSE w.kode_wilayah END
            FROM ref.mst_wilayah w
           WHERE w.kode_wilayah = dbo.sekolah.kode_wilayah),
         dbo.sekolah.kode_wilayah)
  JOIN ref.bentuk_pendidikan
    ON ref.bentuk_pendidikan.bentuk_pendidikan_id = dbo.sekolah.bentuk_pendidikan_id
  LEFT JOIN ref.status_kepemilikan
    ON ref.status_kepemilikan.status_kepemilikan_id = dbo.sekolah.status_kepemilikan_id
  WHERE dbo.sekolah.keaktifan = 1
    AND dbo.sekolah."Soft_delete" = 0
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
  [[AND {{status_sekolah}}]]
),
pd_dim AS (
  SELECT
    dbo.registrasi_peserta_didik.peserta_didik_id,
    TRIM(dbo.peserta_didik.nisn) AS nisn,
    dbo.registrasi_peserta_didik.sekolah_id,
    dbo.registrasi_peserta_didik.nipd,
    sekolah_dim.npsn,
    sekolah_dim.nama_sekolah,
    sekolah_dim.jenjang,
    sekolah_dim.kecamatan
  FROM dbo.registrasi_peserta_didik
  JOIN dbo.peserta_didik
    ON dbo.peserta_didik.peserta_didik_id = dbo.registrasi_peserta_didik.peserta_didik_id
  JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.registrasi_peserta_didik.sekolah_id
  WHERE dbo.registrasi_peserta_didik."Soft_delete" = 0
    AND dbo.registrasi_peserta_didik.jenis_keluar_id IS NULL
    AND TRIM(dbo.peserta_didik.nisn) ~ '^[0-9]{10}$'
)
SELECT COUNT(DISTINCT nisn) AS total_peserta_didik
FROM pd_dim
```


#### Q-PD-02 · Peserta Didik per Jenjang

- **Tab**: Peserta Didik
- **Tipe visualisasi**: Bar
- **Definisi**: Jumlah peserta didik aktif pada setiap jenjang pendidikan.
- **Filter**: Kecamatan, Desa, Jenjang, Status
- **Ekspektasi (data saat ini)**: 7 baris — teratas: SD · 40009

```sql
WITH sekolah_dim AS (
  SELECT
    dbo.sekolah.sekolah_id,
    dbo.sekolah.npsn,
    dbo.sekolah.nama AS nama_sekolah,
    ref.mst_wilayah.nama AS kecamatan,
    dbo.sekolah.desa_kelurahan AS desa,
    ref.bentuk_pendidikan.nama AS jenjang,
    CASE dbo.sekolah.status_sekolah WHEN 1 THEN 'Negeri' ELSE 'Swasta' END AS status_sekolah,
    COALESCE(ref.status_kepemilikan.nama, 'Tidak diketahui') AS kepemilikan
  FROM dbo.sekolah
  JOIN ref.mst_wilayah
    ON ref.mst_wilayah.kode_wilayah = COALESCE(
         (SELECT CASE WHEN w.id_level_wilayah = 4 THEN w.mst_kode_wilayah ELSE w.kode_wilayah END
            FROM ref.mst_wilayah w
           WHERE w.kode_wilayah = dbo.sekolah.kode_wilayah),
         dbo.sekolah.kode_wilayah)
  JOIN ref.bentuk_pendidikan
    ON ref.bentuk_pendidikan.bentuk_pendidikan_id = dbo.sekolah.bentuk_pendidikan_id
  LEFT JOIN ref.status_kepemilikan
    ON ref.status_kepemilikan.status_kepemilikan_id = dbo.sekolah.status_kepemilikan_id
  WHERE dbo.sekolah.keaktifan = 1
    AND dbo.sekolah."Soft_delete" = 0
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
  [[AND {{status_sekolah}}]]
),
pd_dim AS (
  SELECT
    dbo.registrasi_peserta_didik.peserta_didik_id,
    TRIM(dbo.peserta_didik.nisn) AS nisn,
    dbo.registrasi_peserta_didik.sekolah_id,
    dbo.registrasi_peserta_didik.nipd,
    sekolah_dim.npsn,
    sekolah_dim.nama_sekolah,
    sekolah_dim.jenjang,
    sekolah_dim.kecamatan
  FROM dbo.registrasi_peserta_didik
  JOIN dbo.peserta_didik
    ON dbo.peserta_didik.peserta_didik_id = dbo.registrasi_peserta_didik.peserta_didik_id
  JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.registrasi_peserta_didik.sekolah_id
  WHERE dbo.registrasi_peserta_didik."Soft_delete" = 0
    AND dbo.registrasi_peserta_didik.jenis_keluar_id IS NULL
    AND TRIM(dbo.peserta_didik.nisn) ~ '^[0-9]{10}$'
)
SELECT jenjang, COUNT(DISTINCT nisn) AS jumlah_pd
FROM pd_dim
GROUP BY jenjang
ORDER BY jumlah_pd DESC
```


#### Q-PD-03 · Peserta Didik per Kecamatan

- **Tab**: Peserta Didik
- **Tipe visualisasi**: Bar (horizontal)
- **Definisi**: Jumlah peserta didik aktif pada setiap kecamatan.
- **Filter**: Kecamatan, Desa, Jenjang, Status
- **Ekspektasi (data saat ini)**: 8 baris — teratas: Kec. Karangasem · 15245

```sql
WITH sekolah_dim AS (
  SELECT
    dbo.sekolah.sekolah_id,
    dbo.sekolah.npsn,
    dbo.sekolah.nama AS nama_sekolah,
    ref.mst_wilayah.nama AS kecamatan,
    dbo.sekolah.desa_kelurahan AS desa,
    ref.bentuk_pendidikan.nama AS jenjang,
    CASE dbo.sekolah.status_sekolah WHEN 1 THEN 'Negeri' ELSE 'Swasta' END AS status_sekolah,
    COALESCE(ref.status_kepemilikan.nama, 'Tidak diketahui') AS kepemilikan
  FROM dbo.sekolah
  JOIN ref.mst_wilayah
    ON ref.mst_wilayah.kode_wilayah = COALESCE(
         (SELECT CASE WHEN w.id_level_wilayah = 4 THEN w.mst_kode_wilayah ELSE w.kode_wilayah END
            FROM ref.mst_wilayah w
           WHERE w.kode_wilayah = dbo.sekolah.kode_wilayah),
         dbo.sekolah.kode_wilayah)
  JOIN ref.bentuk_pendidikan
    ON ref.bentuk_pendidikan.bentuk_pendidikan_id = dbo.sekolah.bentuk_pendidikan_id
  LEFT JOIN ref.status_kepemilikan
    ON ref.status_kepemilikan.status_kepemilikan_id = dbo.sekolah.status_kepemilikan_id
  WHERE dbo.sekolah.keaktifan = 1
    AND dbo.sekolah."Soft_delete" = 0
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
  [[AND {{status_sekolah}}]]
),
pd_dim AS (
  SELECT
    dbo.registrasi_peserta_didik.peserta_didik_id,
    TRIM(dbo.peserta_didik.nisn) AS nisn,
    dbo.registrasi_peserta_didik.sekolah_id,
    dbo.registrasi_peserta_didik.nipd,
    sekolah_dim.npsn,
    sekolah_dim.nama_sekolah,
    sekolah_dim.jenjang,
    sekolah_dim.kecamatan
  FROM dbo.registrasi_peserta_didik
  JOIN dbo.peserta_didik
    ON dbo.peserta_didik.peserta_didik_id = dbo.registrasi_peserta_didik.peserta_didik_id
  JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.registrasi_peserta_didik.sekolah_id
  WHERE dbo.registrasi_peserta_didik."Soft_delete" = 0
    AND dbo.registrasi_peserta_didik.jenis_keluar_id IS NULL
    AND TRIM(dbo.peserta_didik.nisn) ~ '^[0-9]{10}$'
)
SELECT kecamatan, COUNT(DISTINCT nisn) AS jumlah_pd
FROM pd_dim
GROUP BY kecamatan
ORDER BY jumlah_pd DESC
```


#### Q-PD-04 · Peserta Didik per Tingkat Kelas

- **Tab**: Peserta Didik
- **Tipe visualisasi**: Bar
- **Definisi**: Jumlah peserta didik aktif menurut tingkat/kelas (Kelas 1 sampai Kelas 12, termasuk tingkat SLB/SMK bila ada), dihubungkan melalui keanggotaan rombel aktif.
- **Filter**: Kecamatan, Desa, Jenjang, Status
- **Ekspektasi (data saat ini)**: 25 baris — teratas: Kelas 1 · 6319
- **Catatan**: Murid yang belum masuk rombel mana pun tidak muncul di kartu ini (lihat Q-PD-01 untuk total).

```sql
WITH sekolah_dim AS (
  SELECT
    dbo.sekolah.sekolah_id,
    dbo.sekolah.npsn,
    dbo.sekolah.nama AS nama_sekolah,
    ref.mst_wilayah.nama AS kecamatan,
    dbo.sekolah.desa_kelurahan AS desa,
    ref.bentuk_pendidikan.nama AS jenjang,
    CASE dbo.sekolah.status_sekolah WHEN 1 THEN 'Negeri' ELSE 'Swasta' END AS status_sekolah,
    COALESCE(ref.status_kepemilikan.nama, 'Tidak diketahui') AS kepemilikan
  FROM dbo.sekolah
  JOIN ref.mst_wilayah
    ON ref.mst_wilayah.kode_wilayah = COALESCE(
         (SELECT CASE WHEN w.id_level_wilayah = 4 THEN w.mst_kode_wilayah ELSE w.kode_wilayah END
            FROM ref.mst_wilayah w
           WHERE w.kode_wilayah = dbo.sekolah.kode_wilayah),
         dbo.sekolah.kode_wilayah)
  JOIN ref.bentuk_pendidikan
    ON ref.bentuk_pendidikan.bentuk_pendidikan_id = dbo.sekolah.bentuk_pendidikan_id
  LEFT JOIN ref.status_kepemilikan
    ON ref.status_kepemilikan.status_kepemilikan_id = dbo.sekolah.status_kepemilikan_id
  WHERE dbo.sekolah.keaktifan = 1
    AND dbo.sekolah."Soft_delete" = 0
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
  [[AND {{status_sekolah}}]]
),
rombel_dim AS (
  SELECT
    dbo.rombongan_belajar.rombongan_belajar_id,
    dbo.rombongan_belajar.tingkat_pendidikan_id,
    dbo.rombongan_belajar.nama AS nama_rombel,
    dbo.rombongan_belajar.ptk_id AS wali_kelas_id,
    sekolah_dim.sekolah_id,
    sekolah_dim.npsn,
    sekolah_dim.nama_sekolah,
    sekolah_dim.jenjang,
    sekolah_dim.kecamatan
  FROM dbo.rombongan_belajar
  JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.rombongan_belajar.sekolah_id
  WHERE dbo.rombongan_belajar."Soft_delete" = 0
    AND dbo.rombongan_belajar.jenis_rombel = 1
    AND dbo.rombongan_belajar.semester_id = (SELECT semester_id FROM ref.semester WHERE periode_aktif = 1)
),
pd_dim AS (
  SELECT
    dbo.registrasi_peserta_didik.peserta_didik_id,
    TRIM(dbo.peserta_didik.nisn) AS nisn,
    dbo.registrasi_peserta_didik.sekolah_id,
    dbo.registrasi_peserta_didik.nipd,
    sekolah_dim.npsn,
    sekolah_dim.nama_sekolah,
    sekolah_dim.jenjang,
    sekolah_dim.kecamatan
  FROM dbo.registrasi_peserta_didik
  JOIN dbo.peserta_didik
    ON dbo.peserta_didik.peserta_didik_id = dbo.registrasi_peserta_didik.peserta_didik_id
  JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.registrasi_peserta_didik.sekolah_id
  WHERE dbo.registrasi_peserta_didik."Soft_delete" = 0
    AND dbo.registrasi_peserta_didik.jenis_keluar_id IS NULL
    AND TRIM(dbo.peserta_didik.nisn) ~ '^[0-9]{10}$'
)
SELECT ref.tingkat_pendidikan.nama AS tingkat,
       COUNT(DISTINCT pd_dim.nisn) AS jumlah_pd
FROM pd_dim
JOIN dbo.anggota_rombel
  ON dbo.anggota_rombel.peserta_didik_id = pd_dim.peserta_didik_id
 AND dbo.anggota_rombel."Soft_delete" = 0
JOIN rombel_dim ON rombel_dim.rombongan_belajar_id = dbo.anggota_rombel.rombongan_belajar_id
JOIN ref.tingkat_pendidikan
  ON ref.tingkat_pendidikan.tingkat_pendidikan_id = rombel_dim.tingkat_pendidikan_id
GROUP BY rombel_dim.tingkat_pendidikan_id, ref.tingkat_pendidikan.nama
ORDER BY rombel_dim.tingkat_pendidikan_id
```


#### Q-PD-05 · Status NISN Peserta Didik

- **Tab**: Peserta Didik
- **Tipe visualisasi**: Bar (horizontal)
- **Definisi**: Komposisi registrasi peserta didik aktif menurut status NISN: **sah** (10 digit numerik, masuk perhitungan) atau **tidak sah** (kosong/format lain, dikecualikan dari seluruh kartu).
- **Filter**: Kecamatan, Desa, Jenjang, Status
- **Ekspektasi (data saat ini)**: 2 baris — teratas: 01. NISN sah (dihitung) · 68952
- **Catatan**: Kartu transparansi: memperlihatkan berapa murid yang keluar dari cakupan karena NISN belum sah. Pada basis NISN, 24 murid yang berbagi NISN dengan murid lain dihitung satu, sehingga 68.952 + 1.487 = 70.439 (bukan 70.463 registrasi unik).

```sql
WITH sekolah_dim AS (
  SELECT
    dbo.sekolah.sekolah_id,
    dbo.sekolah.npsn,
    dbo.sekolah.nama AS nama_sekolah,
    ref.mst_wilayah.nama AS kecamatan,
    dbo.sekolah.desa_kelurahan AS desa,
    ref.bentuk_pendidikan.nama AS jenjang,
    CASE dbo.sekolah.status_sekolah WHEN 1 THEN 'Negeri' ELSE 'Swasta' END AS status_sekolah,
    COALESCE(ref.status_kepemilikan.nama, 'Tidak diketahui') AS kepemilikan
  FROM dbo.sekolah
  JOIN ref.mst_wilayah
    ON ref.mst_wilayah.kode_wilayah = COALESCE(
         (SELECT CASE WHEN w.id_level_wilayah = 4 THEN w.mst_kode_wilayah ELSE w.kode_wilayah END
            FROM ref.mst_wilayah w
           WHERE w.kode_wilayah = dbo.sekolah.kode_wilayah),
         dbo.sekolah.kode_wilayah)
  JOIN ref.bentuk_pendidikan
    ON ref.bentuk_pendidikan.bentuk_pendidikan_id = dbo.sekolah.bentuk_pendidikan_id
  LEFT JOIN ref.status_kepemilikan
    ON ref.status_kepemilikan.status_kepemilikan_id = dbo.sekolah.status_kepemilikan_id
  WHERE dbo.sekolah.keaktifan = 1
    AND dbo.sekolah."Soft_delete" = 0
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
  [[AND {{status_sekolah}}]]
),
pd_all AS (
  SELECT
    dbo.registrasi_peserta_didik.peserta_didik_id,
    TRIM(dbo.peserta_didik.nisn) AS nisn,
    CASE WHEN TRIM(dbo.peserta_didik.nisn) ~ '^[0-9]{10}$' THEN 1 ELSE 0 END AS nisn_valid,
    sekolah_dim.npsn,
    sekolah_dim.nama_sekolah,
    sekolah_dim.jenjang,
    sekolah_dim.kecamatan
  FROM dbo.registrasi_peserta_didik
  LEFT JOIN dbo.peserta_didik
    ON dbo.peserta_didik.peserta_didik_id = dbo.registrasi_peserta_didik.peserta_didik_id
  JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.registrasi_peserta_didik.sekolah_id
  WHERE dbo.registrasi_peserta_didik."Soft_delete" = 0
    AND dbo.registrasi_peserta_didik.jenis_keluar_id IS NULL
)
SELECT '01. NISN sah (dihitung)' AS status_nisn,
       COUNT(DISTINCT nisn) AS jumlah_pd
FROM pd_all
WHERE nisn_valid = 1
UNION ALL
SELECT '02. NISN tidak sah (dikecualikan)' AS status_nisn,
       COUNT(DISTINCT peserta_didik_id) AS jumlah_pd
FROM pd_all
WHERE nisn_valid = 0
ORDER BY 1
```


#### Q-PD-06 · Peserta Didik Keluar per Jenis Keluar

- **Tab**: Peserta Didik
- **Tipe visualisasi**: Bar
- **Definisi**: Jumlah registrasi peserta didik yang sudah keluar (Lulus, Mutasi, Putus Sekolah, dan sebagainya) pada sekolah aktif. Bukan bagian dari murid aktif.
- **Filter**: Kecamatan, Desa, Jenjang, Status
- **Ekspektasi (data saat ini)**: 7 baris — teratas: Mutasi · 107
- **Catatan**: Kartu konteks: angka kecil karena hanya menampung registrasi yang ditutup pada semester ini.

```sql
WITH sekolah_dim AS (
  SELECT
    dbo.sekolah.sekolah_id,
    dbo.sekolah.npsn,
    dbo.sekolah.nama AS nama_sekolah,
    ref.mst_wilayah.nama AS kecamatan,
    dbo.sekolah.desa_kelurahan AS desa,
    ref.bentuk_pendidikan.nama AS jenjang,
    CASE dbo.sekolah.status_sekolah WHEN 1 THEN 'Negeri' ELSE 'Swasta' END AS status_sekolah,
    COALESCE(ref.status_kepemilikan.nama, 'Tidak diketahui') AS kepemilikan
  FROM dbo.sekolah
  JOIN ref.mst_wilayah
    ON ref.mst_wilayah.kode_wilayah = COALESCE(
         (SELECT CASE WHEN w.id_level_wilayah = 4 THEN w.mst_kode_wilayah ELSE w.kode_wilayah END
            FROM ref.mst_wilayah w
           WHERE w.kode_wilayah = dbo.sekolah.kode_wilayah),
         dbo.sekolah.kode_wilayah)
  JOIN ref.bentuk_pendidikan
    ON ref.bentuk_pendidikan.bentuk_pendidikan_id = dbo.sekolah.bentuk_pendidikan_id
  LEFT JOIN ref.status_kepemilikan
    ON ref.status_kepemilikan.status_kepemilikan_id = dbo.sekolah.status_kepemilikan_id
  WHERE dbo.sekolah.keaktifan = 1
    AND dbo.sekolah."Soft_delete" = 0
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
  [[AND {{status_sekolah}}]]
)
SELECT COALESCE(ref.jenis_keluar.ket_keluar, 'Tidak diketahui') AS jenis_keluar,
       COUNT(*) AS jumlah_pd
FROM dbo.registrasi_peserta_didik
JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.registrasi_peserta_didik.sekolah_id
LEFT JOIN ref.jenis_keluar
  ON ref.jenis_keluar.jenis_keluar_id = dbo.registrasi_peserta_didik.jenis_keluar_id
WHERE dbo.registrasi_peserta_didik."Soft_delete" = 0
  AND dbo.registrasi_peserta_didik.jenis_keluar_id IS NOT NULL
GROUP BY 1
ORDER BY 2 DESC
```


#### Q-PD-07 · Rata-rata Jarak dan Waktu Tempuh ke Sekolah

- **Tab**: Peserta Didik
- **Tipe visualisasi**: Tabel
- **Definisi**: Untuk setiap jenjang: rata-rata jarak rumah–sekolah (km) dan waktu tempuh (menit) dari `dbo.peserta_didik_longitudinal` semester aktif.
- **Filter**: Kecamatan, Desa, Jenjang, Status
- **Ekspektasi (data saat ini)**: 7 baris — teratas: SD · 39914 · 0.47 · 9.2
- **Catatan**: Hanya murid yang punya baris longitudinal yang dihitung.

```sql
WITH sekolah_dim AS (
  SELECT
    dbo.sekolah.sekolah_id,
    dbo.sekolah.npsn,
    dbo.sekolah.nama AS nama_sekolah,
    ref.mst_wilayah.nama AS kecamatan,
    dbo.sekolah.desa_kelurahan AS desa,
    ref.bentuk_pendidikan.nama AS jenjang,
    CASE dbo.sekolah.status_sekolah WHEN 1 THEN 'Negeri' ELSE 'Swasta' END AS status_sekolah,
    COALESCE(ref.status_kepemilikan.nama, 'Tidak diketahui') AS kepemilikan
  FROM dbo.sekolah
  JOIN ref.mst_wilayah
    ON ref.mst_wilayah.kode_wilayah = COALESCE(
         (SELECT CASE WHEN w.id_level_wilayah = 4 THEN w.mst_kode_wilayah ELSE w.kode_wilayah END
            FROM ref.mst_wilayah w
           WHERE w.kode_wilayah = dbo.sekolah.kode_wilayah),
         dbo.sekolah.kode_wilayah)
  JOIN ref.bentuk_pendidikan
    ON ref.bentuk_pendidikan.bentuk_pendidikan_id = dbo.sekolah.bentuk_pendidikan_id
  LEFT JOIN ref.status_kepemilikan
    ON ref.status_kepemilikan.status_kepemilikan_id = dbo.sekolah.status_kepemilikan_id
  WHERE dbo.sekolah.keaktifan = 1
    AND dbo.sekolah."Soft_delete" = 0
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
  [[AND {{status_sekolah}}]]
),
pd_dim AS (
  SELECT
    dbo.registrasi_peserta_didik.peserta_didik_id,
    TRIM(dbo.peserta_didik.nisn) AS nisn,
    dbo.registrasi_peserta_didik.sekolah_id,
    dbo.registrasi_peserta_didik.nipd,
    sekolah_dim.npsn,
    sekolah_dim.nama_sekolah,
    sekolah_dim.jenjang,
    sekolah_dim.kecamatan
  FROM dbo.registrasi_peserta_didik
  JOIN dbo.peserta_didik
    ON dbo.peserta_didik.peserta_didik_id = dbo.registrasi_peserta_didik.peserta_didik_id
  JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.registrasi_peserta_didik.sekolah_id
  WHERE dbo.registrasi_peserta_didik."Soft_delete" = 0
    AND dbo.registrasi_peserta_didik.jenis_keluar_id IS NULL
    AND TRIM(dbo.peserta_didik.nisn) ~ '^[0-9]{10}$'
),
pd_unik AS (
  SELECT DISTINCT ON (nisn) nisn, peserta_didik_id, jenjang
  FROM pd_dim
  ORDER BY nisn, peserta_didik_id
)
SELECT pd_unik.jenjang,
       COUNT(*) AS jumlah_pd_terdata,
       ROUND(AVG(dbo.peserta_didik_longitudinal.jarak_rumah_ke_sekolah_km), 2) AS rata_jarak_km,
       ROUND(AVG(dbo.peserta_didik_longitudinal.menit_tempuh_ke_sekolah), 1) AS rata_menit_tempuh
FROM dbo.peserta_didik_longitudinal
JOIN pd_unik ON pd_unik.peserta_didik_id = dbo.peserta_didik_longitudinal.peserta_didik_id
WHERE dbo.peserta_didik_longitudinal."Soft_delete" = 0
  AND dbo.peserta_didik_longitudinal.semester_id = (SELECT semester_id FROM ref.semester WHERE periode_aktif = 1)
GROUP BY pd_unik.jenjang
ORDER BY jumlah_pd_terdata DESC
```


#### Q-PD-08 · 20 Sekolah dengan Peserta Didik Terbanyak

- **Tab**: Peserta Didik
- **Tipe visualisasi**: Tabel
- **Definisi**: Peringkat 20 sekolah aktif berdasarkan jumlah peserta didik aktif.
- **Filter**: Kecamatan, Desa, Jenjang, Status
- **Ekspektasi (data saat ini)**: 20 baris — teratas: SMP NEGERI 2 AMLAPURA · 50102807 · SMP · Kec. Karangasem · 1410

```sql
WITH sekolah_dim AS (
  SELECT
    dbo.sekolah.sekolah_id,
    dbo.sekolah.npsn,
    dbo.sekolah.nama AS nama_sekolah,
    ref.mst_wilayah.nama AS kecamatan,
    dbo.sekolah.desa_kelurahan AS desa,
    ref.bentuk_pendidikan.nama AS jenjang,
    CASE dbo.sekolah.status_sekolah WHEN 1 THEN 'Negeri' ELSE 'Swasta' END AS status_sekolah,
    COALESCE(ref.status_kepemilikan.nama, 'Tidak diketahui') AS kepemilikan
  FROM dbo.sekolah
  JOIN ref.mst_wilayah
    ON ref.mst_wilayah.kode_wilayah = COALESCE(
         (SELECT CASE WHEN w.id_level_wilayah = 4 THEN w.mst_kode_wilayah ELSE w.kode_wilayah END
            FROM ref.mst_wilayah w
           WHERE w.kode_wilayah = dbo.sekolah.kode_wilayah),
         dbo.sekolah.kode_wilayah)
  JOIN ref.bentuk_pendidikan
    ON ref.bentuk_pendidikan.bentuk_pendidikan_id = dbo.sekolah.bentuk_pendidikan_id
  LEFT JOIN ref.status_kepemilikan
    ON ref.status_kepemilikan.status_kepemilikan_id = dbo.sekolah.status_kepemilikan_id
  WHERE dbo.sekolah.keaktifan = 1
    AND dbo.sekolah."Soft_delete" = 0
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
  [[AND {{status_sekolah}}]]
),
pd_dim AS (
  SELECT
    dbo.registrasi_peserta_didik.peserta_didik_id,
    TRIM(dbo.peserta_didik.nisn) AS nisn,
    dbo.registrasi_peserta_didik.sekolah_id,
    dbo.registrasi_peserta_didik.nipd,
    sekolah_dim.npsn,
    sekolah_dim.nama_sekolah,
    sekolah_dim.jenjang,
    sekolah_dim.kecamatan
  FROM dbo.registrasi_peserta_didik
  JOIN dbo.peserta_didik
    ON dbo.peserta_didik.peserta_didik_id = dbo.registrasi_peserta_didik.peserta_didik_id
  JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.registrasi_peserta_didik.sekolah_id
  WHERE dbo.registrasi_peserta_didik."Soft_delete" = 0
    AND dbo.registrasi_peserta_didik.jenis_keluar_id IS NULL
    AND TRIM(dbo.peserta_didik.nisn) ~ '^[0-9]{10}$'
)
SELECT nama_sekolah,
       npsn,
       jenjang,
       kecamatan,
       COUNT(DISTINCT nisn) AS jumlah_pd
FROM pd_dim
GROUP BY 1, 2, 3, 4
ORDER BY jumlah_pd DESC
LIMIT 20
```


### Tab 5 — ATS (7 kartu)

Agregasi anak tidak sekolah usia 7–18 tahun yang berdomisili di Kabupaten Karangasem.

**Tata letak yang disarankan**: KPI 6×4 (baris 0), grafik 12×8 (baris 4 dan 12), grafik 24×8 (baris 20), tabel 24×8 (baris 28).


| ID | Judul | Tipe | Ekspektasi |
|---|---|---|---|
| Q-ATS-01 | Total ATS Usia Sekolah (7–18 Tahun) | Scalar | 3032 |
| Q-ATS-02 | Komposisi Status ATS | Bar / Donut | 3 baris — teratas: BPB · 1475 |
| Q-ATS-03 | ATS per Kecamatan | Bar (horizontal) | 8 baris — teratas: Kec. Kubu · 1217 |
| Q-ATS-04 | ATS per Jenjang Pendidikan Terakhir | Bar | 7 baris — teratas: Tidak terdata · 1218 |
| Q-ATS-05 | ATS per Kelompok Usia | Bar | 3 baris — teratas: 13-15 (SMP) · 759 |
| Q-ATS-06 | ATS per Tahun Pendataan | Bar | 3 baris — teratas: 2023 · 2490 |
| Q-ATS-07 | 15 Desa dengan ATS Terbanyak | Tabel | 15 baris — teratas: Tianyar Tengah · Kec. Kubu · 321 |



#### Q-ATS-01 · Total ATS Usia Sekolah (7–18 Tahun)

- **Tab**: ATS
- **Tipe visualisasi**: Scalar
- **Definisi**: Jumlah anak tidak sekolah (ATS) dengan `soft_delete = 0` dan usia 7–18 tahun, yaitu rentang usia yang masih dapat diintervensi melalui jalur pendidikan formal.
- **Filter**: Kecamatan, Desa, Jenjang ATS
- **Ekspektasi (data saat ini)**: 3032

```sql
WITH ats_dim AS (
  SELECT
    dbo.ats.ats_id,
    TRIM(dbo.ats.status) AS status,
    dbo.ats.usia,
    dbo.ats.kecamatan,
    dbo.ats.desa_kelurahan AS desa,
    COALESCE(NULLIF(dbo.ats.jenjang_pendidikan, ''), 'Tidak terdata') AS jenjang,
    dbo.ats.tahun
  FROM dbo.ats
  WHERE dbo.ats.soft_delete = 0
    AND dbo.ats.usia BETWEEN 7 AND 18
    AND dbo.ats.kode_kabupaten = '220800'
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
)
SELECT COUNT(*) AS total_ats
FROM ats_dim
```


#### Q-ATS-02 · Komposisi Status ATS

- **Tab**: ATS
- **Tipe visualisasi**: Bar / Donut
- **Definisi**: Sebaran ATS menurut status: BPB (belum pernah bersekolah), LTM (lulus tidak melanjutkan), dan DO (drop out). Nilai dinormalisasi dengan `TRIM()` karena basis data menyimpan dua varian ('DO' dan 'DO ').
- **Filter**: Kecamatan, Desa, Jenjang ATS
- **Ekspektasi (data saat ini)**: 3 baris — teratas: BPB · 1475

```sql
WITH ats_dim AS (
  SELECT
    dbo.ats.ats_id,
    TRIM(dbo.ats.status) AS status,
    dbo.ats.usia,
    dbo.ats.kecamatan,
    dbo.ats.desa_kelurahan AS desa,
    COALESCE(NULLIF(dbo.ats.jenjang_pendidikan, ''), 'Tidak terdata') AS jenjang,
    dbo.ats.tahun
  FROM dbo.ats
  WHERE dbo.ats.soft_delete = 0
    AND dbo.ats.usia BETWEEN 7 AND 18
    AND dbo.ats.kode_kabupaten = '220800'
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
)
SELECT status, COUNT(*) AS jumlah_ats
FROM ats_dim
GROUP BY status
ORDER BY jumlah_ats DESC
```


#### Q-ATS-03 · ATS per Kecamatan

- **Tab**: ATS
- **Tipe visualisasi**: Bar (horizontal)
- **Definisi**: Jumlah ATS usia 7–18 tahun pada setiap kecamatan.
- **Filter**: Kecamatan, Desa, Jenjang ATS
- **Ekspektasi (data saat ini)**: 8 baris — teratas: Kec. Kubu · 1217

```sql
WITH ats_dim AS (
  SELECT
    dbo.ats.ats_id,
    TRIM(dbo.ats.status) AS status,
    dbo.ats.usia,
    dbo.ats.kecamatan,
    dbo.ats.desa_kelurahan AS desa,
    COALESCE(NULLIF(dbo.ats.jenjang_pendidikan, ''), 'Tidak terdata') AS jenjang,
    dbo.ats.tahun
  FROM dbo.ats
  WHERE dbo.ats.soft_delete = 0
    AND dbo.ats.usia BETWEEN 7 AND 18
    AND dbo.ats.kode_kabupaten = '220800'
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
)
SELECT kecamatan, COUNT(*) AS jumlah_ats
FROM ats_dim
GROUP BY kecamatan
ORDER BY jumlah_ats DESC
```


#### Q-ATS-04 · ATS per Jenjang Pendidikan Terakhir

- **Tab**: ATS
- **Tipe visualisasi**: Bar
- **Definisi**: Jumlah ATS menurut jenjang pendidikan terakhir yang tercatat (SD, SMP, SMA, Paket A/B/C, atau tidak terdata).
- **Filter**: Kecamatan, Desa, Jenjang ATS
- **Ekspektasi (data saat ini)**: 7 baris — teratas: Tidak terdata · 1218

```sql
WITH ats_dim AS (
  SELECT
    dbo.ats.ats_id,
    TRIM(dbo.ats.status) AS status,
    dbo.ats.usia,
    dbo.ats.kecamatan,
    dbo.ats.desa_kelurahan AS desa,
    COALESCE(NULLIF(dbo.ats.jenjang_pendidikan, ''), 'Tidak terdata') AS jenjang,
    dbo.ats.tahun
  FROM dbo.ats
  WHERE dbo.ats.soft_delete = 0
    AND dbo.ats.usia BETWEEN 7 AND 18
    AND dbo.ats.kode_kabupaten = '220800'
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
)
SELECT jenjang, COUNT(*) AS jumlah_ats
FROM ats_dim
GROUP BY jenjang
ORDER BY jumlah_ats DESC
```


#### Q-ATS-05 · ATS per Kelompok Usia

- **Tab**: ATS
- **Tipe visualisasi**: Bar
- **Definisi**: Jumlah ATS dikelompokkan menurut rentang usia sekolah: 7–12 (SD), 13–15 (SMP), dan 16–18 (SMA).
- **Filter**: Kecamatan, Desa, Jenjang ATS
- **Ekspektasi (data saat ini)**: 3 baris — teratas: 13-15 (SMP) · 759

```sql
WITH ats_dim AS (
  SELECT
    dbo.ats.ats_id,
    TRIM(dbo.ats.status) AS status,
    dbo.ats.usia,
    dbo.ats.kecamatan,
    dbo.ats.desa_kelurahan AS desa,
    COALESCE(NULLIF(dbo.ats.jenjang_pendidikan, ''), 'Tidak terdata') AS jenjang,
    dbo.ats.tahun
  FROM dbo.ats
  WHERE dbo.ats.soft_delete = 0
    AND dbo.ats.usia BETWEEN 7 AND 18
    AND dbo.ats.kode_kabupaten = '220800'
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
)
SELECT CASE
         WHEN usia BETWEEN 7 AND 12 THEN '7-12 (SD)'
         WHEN usia BETWEEN 13 AND 15 THEN '13-15 (SMP)'
         ELSE '16-18 (SMA)'
       END AS kelompok_usia,
       COUNT(*) AS jumlah_ats
FROM ats_dim
GROUP BY 1
ORDER BY 1
```


#### Q-ATS-06 · ATS per Tahun Pendataan

- **Tab**: ATS
- **Tipe visualisasi**: Bar
- **Definisi**: Jumlah ATS usia 7–18 tahun menurut tahun pendataan (`tahun`). Menunjukkan tahun basis data ATS paling lengkap.
- **Filter**: Kecamatan, Desa, Jenjang ATS
- **Ekspektasi (data saat ini)**: 3 baris — teratas: 2023 · 2490
- **Catatan**: Sebagian besar data berasal dari pendataan 2023; tahun lain belum tentu lengkap.

```sql
WITH ats_dim AS (
  SELECT
    dbo.ats.ats_id,
    TRIM(dbo.ats.status) AS status,
    dbo.ats.usia,
    dbo.ats.kecamatan,
    dbo.ats.desa_kelurahan AS desa,
    COALESCE(NULLIF(dbo.ats.jenjang_pendidikan, ''), 'Tidak terdata') AS jenjang,
    dbo.ats.tahun
  FROM dbo.ats
  WHERE dbo.ats.soft_delete = 0
    AND dbo.ats.usia BETWEEN 7 AND 18
    AND dbo.ats.kode_kabupaten = '220800'
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
)
SELECT tahun, COUNT(*) AS jumlah_ats
FROM ats_dim
GROUP BY tahun
ORDER BY tahun
```


#### Q-ATS-07 · 15 Desa dengan ATS Terbanyak

- **Tab**: ATS
- **Tipe visualisasi**: Tabel
- **Definisi**: Peringkat 15 desa/kelurahan dengan jumlah ATS usia 7–18 tahun terbanyak.
- **Filter**: Kecamatan, Desa, Jenjang ATS
- **Ekspektasi (data saat ini)**: 15 baris — teratas: Tianyar Tengah · Kec. Kubu · 321
- **Catatan**: Agregat per desa, bukan data per anak. Nama, NIK, dan NISN ATS tidak pernah ditampilkan.

```sql
WITH ats_dim AS (
  SELECT
    dbo.ats.ats_id,
    TRIM(dbo.ats.status) AS status,
    dbo.ats.usia,
    dbo.ats.kecamatan,
    dbo.ats.desa_kelurahan AS desa,
    COALESCE(NULLIF(dbo.ats.jenjang_pendidikan, ''), 'Tidak terdata') AS jenjang,
    dbo.ats.tahun
  FROM dbo.ats
  WHERE dbo.ats.soft_delete = 0
    AND dbo.ats.usia BETWEEN 7 AND 18
    AND dbo.ats.kode_kabupaten = '220800'
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
)
SELECT desa, kecamatan, COUNT(*) AS jumlah_ats
FROM ats_dim
GROUP BY 1, 2
ORDER BY jumlah_ats DESC
LIMIT 15
```


### Tab 6 — Sarana Prasarana (8 kartu)

Agregasi aset sarana prasarana sekolah aktif beserta kondisinya.

**Tata letak yang disarankan**: Grafik 12×8 (baris 0 dan 8), KPI 6×4 (baris 16), grafik 12×8 (baris 20), tabel/grafik 24×8 (baris 28).


| ID | Judul | Tipe | Ekspektasi |
|---|---|---|---|
| Q-SAR-01 | Jumlah Sarana per Kategori | Tabel | 5 baris — teratas: Alat · 109971 |
| Q-SAR-02 | Kondisi Ruang (Tercatat) | Bar | 4 baris — teratas: Rusak Sedang · 654 |
| Q-SAR-03 | Rata-rata Tingkat Kerusakan Ruang | Scalar (persen) | 26.85 |
| Q-SAR-04 | Ruang Rusak Berat per Kecamatan | Bar (horizontal) | 8 baris — teratas: Kec. Abang · 70 |
| Q-SAR-05 | Kecukupan Ruang Kelas terhadap Rombel | Bar | 7 baris — teratas: TPA · 7 · 8 · 0.88 |
| Q-SAR-06 | Persentase Sekolah Memiliki Perpustakaan | Scalar (persen) | 66.06 |
| Q-SAR-07 | Sumber Air Bersih Sekolah | Bar / Donut | 9 baris — teratas: Ledeng/PAM · 438 |
| Q-SAR-08 | 10 Jenis Alat Terbanyak | Bar (horizontal) | 10 baris — teratas: Tempat Sampah · 6397 |



#### Q-SAR-01 · Jumlah Sarana per Kategori

- **Tab**: Sarana Prasarana
- **Tipe visualisasi**: Tabel
- **Definisi**: Rekapitulasi jumlah aset sarana pada sekolah aktif: ruang, bangunan, tanah, alat, dan judul buku.
- **Filter**: Kecamatan, Desa, Jenjang, Status
- **Ekspektasi (data saat ini)**: 5 baris — teratas: Alat · 109971
- **Catatan**: `dbo.buku` menyimpan satu baris per judul/bibliografi, bukan jumlah eksemplar.

```sql
WITH sekolah_dim AS (
  SELECT
    dbo.sekolah.sekolah_id,
    dbo.sekolah.npsn,
    dbo.sekolah.nama AS nama_sekolah,
    ref.mst_wilayah.nama AS kecamatan,
    dbo.sekolah.desa_kelurahan AS desa,
    ref.bentuk_pendidikan.nama AS jenjang,
    CASE dbo.sekolah.status_sekolah WHEN 1 THEN 'Negeri' ELSE 'Swasta' END AS status_sekolah,
    COALESCE(ref.status_kepemilikan.nama, 'Tidak diketahui') AS kepemilikan
  FROM dbo.sekolah
  JOIN ref.mst_wilayah
    ON ref.mst_wilayah.kode_wilayah = COALESCE(
         (SELECT CASE WHEN w.id_level_wilayah = 4 THEN w.mst_kode_wilayah ELSE w.kode_wilayah END
            FROM ref.mst_wilayah w
           WHERE w.kode_wilayah = dbo.sekolah.kode_wilayah),
         dbo.sekolah.kode_wilayah)
  JOIN ref.bentuk_pendidikan
    ON ref.bentuk_pendidikan.bentuk_pendidikan_id = dbo.sekolah.bentuk_pendidikan_id
  LEFT JOIN ref.status_kepemilikan
    ON ref.status_kepemilikan.status_kepemilikan_id = dbo.sekolah.status_kepemilikan_id
  WHERE dbo.sekolah.keaktifan = 1
    AND dbo.sekolah."Soft_delete" = 0
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
  [[AND {{status_sekolah}}]]
)
SELECT 'Ruang' AS kategori, COUNT(*) AS jumlah
FROM dbo.ruang
JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.ruang.sekolah_id
WHERE dbo.ruang."Soft_delete" = 0
UNION ALL
SELECT 'Bangunan', COUNT(*)
FROM dbo.bangunan
JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.bangunan.sekolah_id
WHERE dbo.bangunan."Soft_delete" = 0
UNION ALL
SELECT 'Tanah', COUNT(*)
FROM dbo.tanah
JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.tanah.sekolah_id
WHERE dbo.tanah."Soft_delete" = 0
UNION ALL
SELECT 'Alat', COUNT(*)
FROM dbo.alat
JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.alat.sekolah_id
WHERE dbo.alat."Soft_delete" = 0
UNION ALL
SELECT 'Judul buku', COUNT(*)
FROM dbo.buku
JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.buku.sekolah_id
WHERE dbo.buku."Soft_delete" = 0
ORDER BY jumlah DESC
```


#### Q-SAR-02 · Kondisi Ruang (Tercatat)

- **Tab**: Sarana Prasarana
- **Tipe visualisasi**: Bar
- **Definisi**: Jumlah ruang menurut klasifikasi kerusakan pada semester aktif: tidak ada kerusakan, rusak ringan, rusak sedang, dan rusak berat.
- **Filter**: Kecamatan, Desa, Jenjang, Status
- **Ekspektasi (data saat ini)**: 4 baris — teratas: Rusak Sedang · 654
- **Catatan**: Hanya ruang yang memiliki baris kondisi pada semester aktif yang dihitung (cakupan terbatas).

```sql
WITH sekolah_dim AS (
  SELECT
    dbo.sekolah.sekolah_id,
    dbo.sekolah.npsn,
    dbo.sekolah.nama AS nama_sekolah,
    ref.mst_wilayah.nama AS kecamatan,
    dbo.sekolah.desa_kelurahan AS desa,
    ref.bentuk_pendidikan.nama AS jenjang,
    CASE dbo.sekolah.status_sekolah WHEN 1 THEN 'Negeri' ELSE 'Swasta' END AS status_sekolah,
    COALESCE(ref.status_kepemilikan.nama, 'Tidak diketahui') AS kepemilikan
  FROM dbo.sekolah
  JOIN ref.mst_wilayah
    ON ref.mst_wilayah.kode_wilayah = COALESCE(
         (SELECT CASE WHEN w.id_level_wilayah = 4 THEN w.mst_kode_wilayah ELSE w.kode_wilayah END
            FROM ref.mst_wilayah w
           WHERE w.kode_wilayah = dbo.sekolah.kode_wilayah),
         dbo.sekolah.kode_wilayah)
  JOIN ref.bentuk_pendidikan
    ON ref.bentuk_pendidikan.bentuk_pendidikan_id = dbo.sekolah.bentuk_pendidikan_id
  LEFT JOIN ref.status_kepemilikan
    ON ref.status_kepemilikan.status_kepemilikan_id = dbo.sekolah.status_kepemilikan_id
  WHERE dbo.sekolah.keaktifan = 1
    AND dbo.sekolah."Soft_delete" = 0
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
  [[AND {{status_sekolah}}]]
)
SELECT ref.jenis_kerusakan.klasifikasi AS kondisi,
       COUNT(*) AS jumlah_ruang
FROM dbo.ruang_longitudinal
JOIN dbo.ruang ON dbo.ruang.id_ruang = dbo.ruang_longitudinal.id_ruang
JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.ruang.sekolah_id
LEFT JOIN ref.jenis_kerusakan
  ON ref.jenis_kerusakan.kerusakan_id = dbo.ruang_longitudinal.kerusakan_id
WHERE dbo.ruang."Soft_delete" = 0
  AND dbo.ruang_longitudinal.semester_id = (SELECT semester_id FROM ref.semester WHERE periode_aktif = 1)
GROUP BY 1
ORDER BY 2 DESC
```


#### Q-SAR-03 · Rata-rata Tingkat Kerusakan Ruang

- **Tab**: Sarana Prasarana
- **Tipe visualisasi**: Scalar (persen)
- **Definisi**: Rata-rata nilai `nilai_kerusakan` (skala 0–100) seluruh ruang yang kondisi tercatat pada semester aktif. Semakin tinggi, semakin parah kerusakan rata-rata.
- **Filter**: Kecamatan, Desa, Jenjang, Status
- **Ekspektasi (data saat ini)**: 26.85
- **Catatan**: Bukan nilai rupiah; kolom aslinya berupa skor kerusakan 0–100.

```sql
WITH sekolah_dim AS (
  SELECT
    dbo.sekolah.sekolah_id,
    dbo.sekolah.npsn,
    dbo.sekolah.nama AS nama_sekolah,
    ref.mst_wilayah.nama AS kecamatan,
    dbo.sekolah.desa_kelurahan AS desa,
    ref.bentuk_pendidikan.nama AS jenjang,
    CASE dbo.sekolah.status_sekolah WHEN 1 THEN 'Negeri' ELSE 'Swasta' END AS status_sekolah,
    COALESCE(ref.status_kepemilikan.nama, 'Tidak diketahui') AS kepemilikan
  FROM dbo.sekolah
  JOIN ref.mst_wilayah
    ON ref.mst_wilayah.kode_wilayah = COALESCE(
         (SELECT CASE WHEN w.id_level_wilayah = 4 THEN w.mst_kode_wilayah ELSE w.kode_wilayah END
            FROM ref.mst_wilayah w
           WHERE w.kode_wilayah = dbo.sekolah.kode_wilayah),
         dbo.sekolah.kode_wilayah)
  JOIN ref.bentuk_pendidikan
    ON ref.bentuk_pendidikan.bentuk_pendidikan_id = dbo.sekolah.bentuk_pendidikan_id
  LEFT JOIN ref.status_kepemilikan
    ON ref.status_kepemilikan.status_kepemilikan_id = dbo.sekolah.status_kepemilikan_id
  WHERE dbo.sekolah.keaktifan = 1
    AND dbo.sekolah."Soft_delete" = 0
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
  [[AND {{status_sekolah}}]]
)
SELECT ROUND(AVG(dbo.ruang_longitudinal.nilai_kerusakan), 2) AS rata_tingkat_kerusakan
FROM dbo.ruang_longitudinal
JOIN dbo.ruang ON dbo.ruang.id_ruang = dbo.ruang_longitudinal.id_ruang
JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.ruang.sekolah_id
WHERE dbo.ruang."Soft_delete" = 0
  AND dbo.ruang_longitudinal.semester_id = (SELECT semester_id FROM ref.semester WHERE periode_aktif = 1)
```


#### Q-SAR-04 · Ruang Rusak Berat per Kecamatan

- **Tab**: Sarana Prasarana
- **Tipe visualisasi**: Bar (horizontal)
- **Definisi**: Jumlah ruang dengan klasifikasi Rusak Berat pada setiap kecamatan, pada semester aktif.
- **Filter**: Kecamatan, Desa, Jenjang, Status
- **Ekspektasi (data saat ini)**: 8 baris — teratas: Kec. Abang · 70
- **Catatan**: Kartu prioritas rehabilitasi.

```sql
WITH sekolah_dim AS (
  SELECT
    dbo.sekolah.sekolah_id,
    dbo.sekolah.npsn,
    dbo.sekolah.nama AS nama_sekolah,
    ref.mst_wilayah.nama AS kecamatan,
    dbo.sekolah.desa_kelurahan AS desa,
    ref.bentuk_pendidikan.nama AS jenjang,
    CASE dbo.sekolah.status_sekolah WHEN 1 THEN 'Negeri' ELSE 'Swasta' END AS status_sekolah,
    COALESCE(ref.status_kepemilikan.nama, 'Tidak diketahui') AS kepemilikan
  FROM dbo.sekolah
  JOIN ref.mst_wilayah
    ON ref.mst_wilayah.kode_wilayah = COALESCE(
         (SELECT CASE WHEN w.id_level_wilayah = 4 THEN w.mst_kode_wilayah ELSE w.kode_wilayah END
            FROM ref.mst_wilayah w
           WHERE w.kode_wilayah = dbo.sekolah.kode_wilayah),
         dbo.sekolah.kode_wilayah)
  JOIN ref.bentuk_pendidikan
    ON ref.bentuk_pendidikan.bentuk_pendidikan_id = dbo.sekolah.bentuk_pendidikan_id
  LEFT JOIN ref.status_kepemilikan
    ON ref.status_kepemilikan.status_kepemilikan_id = dbo.sekolah.status_kepemilikan_id
  WHERE dbo.sekolah.keaktifan = 1
    AND dbo.sekolah."Soft_delete" = 0
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
  [[AND {{status_sekolah}}]]
)
SELECT sekolah_dim.kecamatan, COUNT(*) AS jumlah_ruang_rusak_berat
FROM dbo.ruang_longitudinal
JOIN dbo.ruang ON dbo.ruang.id_ruang = dbo.ruang_longitudinal.id_ruang
JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.ruang.sekolah_id
JOIN ref.jenis_kerusakan
  ON ref.jenis_kerusakan.kerusakan_id = dbo.ruang_longitudinal.kerusakan_id
WHERE dbo.ruang."Soft_delete" = 0
  AND dbo.ruang_longitudinal.semester_id = (SELECT semester_id FROM ref.semester WHERE periode_aktif = 1)
  AND ref.jenis_kerusakan.klasifikasi = 'Rusak Berat'
GROUP BY 1
ORDER BY 2 DESC
```


#### Q-SAR-05 · Kecukupan Ruang Kelas terhadap Rombel

- **Tab**: Sarana Prasarana
- **Tipe visualisasi**: Bar
- **Definisi**: Untuk setiap jenjang: jumlah ruang teori/kelas dibandingkan jumlah rombel kelas aktif, beserta rasionya. Rasio < 1 berarti kekurangan ruang kelas.
- **Filter**: Kecamatan, Desa, Jenjang, Status
- **Ekspektasi (data saat ini)**: 7 baris — teratas: TPA · 7 · 8 · 0.88

```sql
WITH sekolah_dim AS (
  SELECT
    dbo.sekolah.sekolah_id,
    dbo.sekolah.npsn,
    dbo.sekolah.nama AS nama_sekolah,
    ref.mst_wilayah.nama AS kecamatan,
    dbo.sekolah.desa_kelurahan AS desa,
    ref.bentuk_pendidikan.nama AS jenjang,
    CASE dbo.sekolah.status_sekolah WHEN 1 THEN 'Negeri' ELSE 'Swasta' END AS status_sekolah,
    COALESCE(ref.status_kepemilikan.nama, 'Tidak diketahui') AS kepemilikan
  FROM dbo.sekolah
  JOIN ref.mst_wilayah
    ON ref.mst_wilayah.kode_wilayah = COALESCE(
         (SELECT CASE WHEN w.id_level_wilayah = 4 THEN w.mst_kode_wilayah ELSE w.kode_wilayah END
            FROM ref.mst_wilayah w
           WHERE w.kode_wilayah = dbo.sekolah.kode_wilayah),
         dbo.sekolah.kode_wilayah)
  JOIN ref.bentuk_pendidikan
    ON ref.bentuk_pendidikan.bentuk_pendidikan_id = dbo.sekolah.bentuk_pendidikan_id
  LEFT JOIN ref.status_kepemilikan
    ON ref.status_kepemilikan.status_kepemilikan_id = dbo.sekolah.status_kepemilikan_id
  WHERE dbo.sekolah.keaktifan = 1
    AND dbo.sekolah."Soft_delete" = 0
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
  [[AND {{status_sekolah}}]]
),
rombel_dim AS (
  SELECT
    dbo.rombongan_belajar.rombongan_belajar_id,
    dbo.rombongan_belajar.tingkat_pendidikan_id,
    dbo.rombongan_belajar.nama AS nama_rombel,
    dbo.rombongan_belajar.ptk_id AS wali_kelas_id,
    sekolah_dim.sekolah_id,
    sekolah_dim.npsn,
    sekolah_dim.nama_sekolah,
    sekolah_dim.jenjang,
    sekolah_dim.kecamatan
  FROM dbo.rombongan_belajar
  JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.rombongan_belajar.sekolah_id
  WHERE dbo.rombongan_belajar."Soft_delete" = 0
    AND dbo.rombongan_belajar.jenis_rombel = 1
    AND dbo.rombongan_belajar.semester_id = (SELECT semester_id FROM ref.semester WHERE periode_aktif = 1)
),
per_sekolah AS (
  SELECT sekolah_dim.sekolah_id,
         sekolah_dim.jenjang,
         (SELECT COUNT(*)
            FROM dbo.ruang
            JOIN ref.jenis_prasarana
              ON ref.jenis_prasarana.jenis_prasarana_id = dbo.ruang.jenis_prasarana_id
           WHERE dbo.ruang.sekolah_id = sekolah_dim.sekolah_id
             AND dbo.ruang."Soft_delete" = 0
             AND ref.jenis_prasarana.nama = 'Ruang Teori/Kelas') AS ruang_kelas,
         (SELECT COUNT(*) FROM rombel_dim WHERE rombel_dim.sekolah_id = sekolah_dim.sekolah_id) AS rombel
  FROM sekolah_dim
)
SELECT jenjang,
       SUM(ruang_kelas) AS total_ruang_kelas,
       SUM(rombel) AS total_rombel,
       ROUND(SUM(ruang_kelas)::numeric / NULLIF(SUM(rombel), 0), 2) AS rasio_ruang_vs_rombel
FROM per_sekolah
GROUP BY jenjang
ORDER BY rasio_ruang_vs_rombel
```


#### Q-SAR-06 · Persentase Sekolah Memiliki Perpustakaan

- **Tab**: Sarana Prasarana
- **Tipe visualisasi**: Scalar (persen)
- **Definisi**: Persentase sekolah aktif yang memiliki minimal satu ruang berjenis perpustakaan.
- **Filter**: Kecamatan, Desa, Jenjang, Status
- **Ekspektasi (data saat ini)**: 66.06

```sql
WITH sekolah_dim AS (
  SELECT
    dbo.sekolah.sekolah_id,
    dbo.sekolah.npsn,
    dbo.sekolah.nama AS nama_sekolah,
    ref.mst_wilayah.nama AS kecamatan,
    dbo.sekolah.desa_kelurahan AS desa,
    ref.bentuk_pendidikan.nama AS jenjang,
    CASE dbo.sekolah.status_sekolah WHEN 1 THEN 'Negeri' ELSE 'Swasta' END AS status_sekolah,
    COALESCE(ref.status_kepemilikan.nama, 'Tidak diketahui') AS kepemilikan
  FROM dbo.sekolah
  JOIN ref.mst_wilayah
    ON ref.mst_wilayah.kode_wilayah = COALESCE(
         (SELECT CASE WHEN w.id_level_wilayah = 4 THEN w.mst_kode_wilayah ELSE w.kode_wilayah END
            FROM ref.mst_wilayah w
           WHERE w.kode_wilayah = dbo.sekolah.kode_wilayah),
         dbo.sekolah.kode_wilayah)
  JOIN ref.bentuk_pendidikan
    ON ref.bentuk_pendidikan.bentuk_pendidikan_id = dbo.sekolah.bentuk_pendidikan_id
  LEFT JOIN ref.status_kepemilikan
    ON ref.status_kepemilikan.status_kepemilikan_id = dbo.sekolah.status_kepemilikan_id
  WHERE dbo.sekolah.keaktifan = 1
    AND dbo.sekolah."Soft_delete" = 0
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
  [[AND {{status_sekolah}}]]
),
perpus AS (
  SELECT DISTINCT dbo.ruang.sekolah_id
  FROM dbo.ruang
  JOIN ref.jenis_prasarana
    ON ref.jenis_prasarana.jenis_prasarana_id = dbo.ruang.jenis_prasarana_id
  WHERE dbo.ruang."Soft_delete" = 0
    AND ref.jenis_prasarana.nama ILIKE '%perpustakaan%'
)
SELECT ROUND(100.0 * COUNT(perpus.sekolah_id) / NULLIF(COUNT(*), 0), 2) AS persen_sekolah_berperpustakaan
FROM sekolah_dim
LEFT JOIN perpus ON perpus.sekolah_id = sekolah_dim.sekolah_id
```


#### Q-SAR-07 · Sumber Air Bersih Sekolah

- **Tab**: Sarana Prasarana
- **Tipe visualisasi**: Bar / Donut
- **Definisi**: Sebaran sekolah aktif menurut sumber air yang digunakan, dari `dbo.sanitasi` semester aktif.
- **Filter**: Kecamatan, Desa, Jenjang, Status
- **Ekspektasi (data saat ini)**: 9 baris — teratas: Ledeng/PAM · 438

```sql
WITH sekolah_dim AS (
  SELECT
    dbo.sekolah.sekolah_id,
    dbo.sekolah.npsn,
    dbo.sekolah.nama AS nama_sekolah,
    ref.mst_wilayah.nama AS kecamatan,
    dbo.sekolah.desa_kelurahan AS desa,
    ref.bentuk_pendidikan.nama AS jenjang,
    CASE dbo.sekolah.status_sekolah WHEN 1 THEN 'Negeri' ELSE 'Swasta' END AS status_sekolah,
    COALESCE(ref.status_kepemilikan.nama, 'Tidak diketahui') AS kepemilikan
  FROM dbo.sekolah
  JOIN ref.mst_wilayah
    ON ref.mst_wilayah.kode_wilayah = COALESCE(
         (SELECT CASE WHEN w.id_level_wilayah = 4 THEN w.mst_kode_wilayah ELSE w.kode_wilayah END
            FROM ref.mst_wilayah w
           WHERE w.kode_wilayah = dbo.sekolah.kode_wilayah),
         dbo.sekolah.kode_wilayah)
  JOIN ref.bentuk_pendidikan
    ON ref.bentuk_pendidikan.bentuk_pendidikan_id = dbo.sekolah.bentuk_pendidikan_id
  LEFT JOIN ref.status_kepemilikan
    ON ref.status_kepemilikan.status_kepemilikan_id = dbo.sekolah.status_kepemilikan_id
  WHERE dbo.sekolah.keaktifan = 1
    AND dbo.sekolah."Soft_delete" = 0
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
  [[AND {{status_sekolah}}]]
)
SELECT COALESCE(ref.sumber_air.nama, 'Tidak terdata') AS sumber_air,
       COUNT(*) AS jumlah_sekolah
FROM dbo.sanitasi
JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.sanitasi.sekolah_id
LEFT JOIN ref.sumber_air ON ref.sumber_air.sumber_air_id = dbo.sanitasi.sumber_air_id
WHERE dbo.sanitasi."Soft_delete" = 0
  AND dbo.sanitasi.semester_id = (SELECT semester_id FROM ref.semester WHERE periode_aktif = 1)
GROUP BY 1
ORDER BY 2 DESC
```


#### Q-SAR-08 · 10 Jenis Alat Terbanyak

- **Tab**: Sarana Prasarana
- **Tipe visualisasi**: Bar (horizontal)
- **Definisi**: Sepuluh jenis sarana alat dengan jumlah unit terbanyak pada sekolah aktif.
- **Filter**: Kecamatan, Desa, Jenjang, Status
- **Ekspektasi (data saat ini)**: 10 baris — teratas: Tempat Sampah · 6397

```sql
WITH sekolah_dim AS (
  SELECT
    dbo.sekolah.sekolah_id,
    dbo.sekolah.npsn,
    dbo.sekolah.nama AS nama_sekolah,
    ref.mst_wilayah.nama AS kecamatan,
    dbo.sekolah.desa_kelurahan AS desa,
    ref.bentuk_pendidikan.nama AS jenjang,
    CASE dbo.sekolah.status_sekolah WHEN 1 THEN 'Negeri' ELSE 'Swasta' END AS status_sekolah,
    COALESCE(ref.status_kepemilikan.nama, 'Tidak diketahui') AS kepemilikan
  FROM dbo.sekolah
  JOIN ref.mst_wilayah
    ON ref.mst_wilayah.kode_wilayah = COALESCE(
         (SELECT CASE WHEN w.id_level_wilayah = 4 THEN w.mst_kode_wilayah ELSE w.kode_wilayah END
            FROM ref.mst_wilayah w
           WHERE w.kode_wilayah = dbo.sekolah.kode_wilayah),
         dbo.sekolah.kode_wilayah)
  JOIN ref.bentuk_pendidikan
    ON ref.bentuk_pendidikan.bentuk_pendidikan_id = dbo.sekolah.bentuk_pendidikan_id
  LEFT JOIN ref.status_kepemilikan
    ON ref.status_kepemilikan.status_kepemilikan_id = dbo.sekolah.status_kepemilikan_id
  WHERE dbo.sekolah.keaktifan = 1
    AND dbo.sekolah."Soft_delete" = 0
  [[AND {{kecamatan}}]]
  [[AND {{desa}}]]
  [[AND {{jenjang}}]]
  [[AND {{status_sekolah}}]]
)
SELECT ref.jenis_sarana.nama AS jenis_alat, COUNT(*) AS jumlah_unit
FROM dbo.alat
JOIN sekolah_dim ON sekolah_dim.sekolah_id = dbo.alat.sekolah_id
JOIN ref.jenis_sarana ON ref.jenis_sarana.jenis_sarana_id = dbo.alat.jenis_sarana_id
WHERE dbo.alat."Soft_delete" = 0
GROUP BY 1
ORDER BY 2 DESC
LIMIT 10
```


---

## 7. Checklist verifikasi

- [ ] 46 kartu ada, tidak ada yang `status = failed` saat dijalankan tanpa filter.
- [ ] Setiap kartu mengembalikan angka/baris sesuai kolom **Ekspektasi** pada §6.
- [ ] Total peserta didik = **68.952** (NISN unik) dan Q-PD-05 menunjukkan 68.952 + 1.487.
- [ ] Total PTK = **4.981** dan Q-SEK-06 tetap 16 sekolah (bukan bertambah karena filter induk).
- [ ] Q-ROM-06 mengembalikan **448** baris dengan kolom `standar_maks` dan `kelebihan`.
- [ ] Filter Kecamatan mengubah angka pada **semua** tab (uji: Kec. Kubu).
- [ ] Filter Desa mengubah angka pada semua tab (uji: Tianyar Tengah).
- [ ] Filter Jenjang hanya memengaruhi tab yang memetakannya; tab ATS memakai filter terpisah.
- [ ] Filter Status Negeri/Swasta mengubah angka di tab EXE, SEK, ROM, PD, SAR.
- [ ] Filter Jenis PTK & Jabatan PTK hanya mengubah **Q-EXE-04**.
- [ ] Kartu tabel menampilkan **nama sekolah**, bukan nama murid/PTK/ATS.
- [ ] Embed publik (`/dashboard`) merender semua tab, footer "Didukung oleh Metabase" tidak tertutup.
- [ ] Uji viewport 1470×900, 1470×662, dan 500×844: tidak ada scroll ganda di wrapper.
- [ ] `vendor/bin/sail artisan metabase:check-embedding` mengembalikan HTTP 200.
- [ ] Dashboard admin (id 4) sudah tidak ada.
- [ ] `docs/metabase-ids.md` diperbarui dengan 46 card ID dan 6 tab ID.

---

## 8. Catatan kualitas data (penting untuk narasi dashboard)

| Temuan | Dampak pada kartu | Perlakuan |
|---|---|---|
| Skema `datamart` kosong (0 baris) | Tidak ada kartu yang memakainya | Semua native SQL ke `dbo`/`ref`/`qc` |
| `dbo.peserta_didik` tanpa gender/agama | Tidak ada kartu demografi murid | Dihilangkan dari desain |
| 1.487 registrasi aktif tanpa NISN sah | 2,11% murid tidak masuk kartu | Dikeluarkan; ditampilkan transparan di Q-PD-05 |
| 24 murid berbagi NISN dengan murid lain | Bisa dobel hitung | Kunci `nisn` membuatnya dihitung satu |
| `qc.vld_peserta_didik.is_nisn_valid` berkode **1/2/4** (bukan boolean), 11.067 murid tanpa baris QC, snapshot terakhir 11 Sep 2026 | Flag QC tidak lengkap dan basi | **Tidak dipakai** sebagai dasar hitung; hanya dicatat di sini |
| NPSN seluruh sekolah 8 digit dan unik | Tidak ada sekolah tanpa identitas | Tidak perlu filter tambahan |
| 125 baris penugasan PTK non-induk (83 aktif), 15 di antaranya Kepala Sekolah/PLT | Bisa dobel antar sekolah | Kartu memakai `ptk_induk = 1`; Q-SEK-06 dikecualikan |
| `dbo.ats.status` menyimpan `'DO'` **dan** `'DO '` | Dulu terpecah 2 baris | Dinormalisasi `TRIM(status)` |
| 93 baris ATS berdomisili di luar Karangasem | Angka ATS membengkak | Difilter `kode_kabupaten = '220800'` |
| 1.566 ATS berdomisili Karangasem tetapi sekolahnya di luar/no school (1.475 tanpa `sekolah_id`) | Bisa dianggap anomali | **Tetap dihitung** karena tab ATS berbasis domisili |
| `ats.ingin_kembali_sekolah` berkode 0/1/2 tanpa referensi | Tidak bisa diartikan pasti | **Tidak dipakai** di dashboard publik |
| Kondisi ruang hanya 1.769 dari 11.449 ruang | Kartu kondisi bukan cakupan penuh | Judul kartu memakai "(Tercatat)" |
| `ruang_longitudinal.nilai_kerusakan` skala 0–100 | Bukan rupiah | Kartu memakai satuan persen, bukan nilai aset |
| `sanitasi.toilet_siswa_laki/perempuan` = 0 semua | Rasio toilet tidak bisa dihitung | Diganti kartu sumber air bersih |
| `dbo.buku` satu baris per judul | Bukan jumlah eksemplar | Kartu memakai label "Judul buku" |
| Akreditasi bisa ganda per sekolah | Bisa dobel hitung | `DISTINCT ON (sekolah_id)` SK terbaru |
| `status_sekolah` berupa kode 1/2 | Dropdown tidak informatif | Dipasang display values Negeri/Swasta |
| 17 rombel dengan wali kelas sudah tidak aktif | Risiko data | Ditampilkan sebagai kartu kualitas data |
| 1 rombel kelas tanpa anggota sama sekali | Rata-rata berbasis anggota menghitung 3.481 rombel | Selisih kecil; rombel tetap muncul di Q-ROM-02/03 |

---

## 9. Pertanyaan terbuka untuk reviewer

1. **Buku**: dihitung per judul, bukan eksemplar (basis data tidak menyimpan jumlah eksemplar).
2. **Akreditasi**: memakai SK terbaru. Sekolah tanpa SK akreditasi diberi label "Belum terdata".
3. **Jumlah kartu**: 46 sesuai draf. Bila terlalu berat untuk perangkat rendah, kandidat
   penyederhanaan: Q-SEK-08 (604 baris), Q-SAR-01 (5 union), Q-PD-07.

### 9.1 Keputusan yang sudah diambil (21 Oktober 2026)

| Isu | Keputusan |
|---|---|
| Kunci unik peserta didik | NISN 10 digit numerik; NISN tidak sah tidak dipakai |
| PTK aktif | Penugasan induk (`ptk_induk = 1`), kecuali Q-SEK-06 |
| Wilayah | Kecamatan/Desa = lokasi satuan pendidikan; ATS = domisili |
| Cakupan ATS | Domisili Kabupaten Karangasem, termasuk yang bersekolah di luar |
| Ambang rombel | Per jenjang (15/28/32) + Paket A/B/C (20/25/30) |
| Kartu distribusi rombel | Diganti "Kepatuhan Ukuran Rombel terhadap Standar per Jenjang" |
