# Metabase Configuration — Dashboard IDs & Settings

> **Fase 2 — Metabase** (Issue #3)
> Terakhir update: 2026-10-21 (rebuild dashboard publik: 6 tab, 46 kartu)

## Site URL

```
METABASE_SITE_URL=https://<quick-tunnel>.trycloudflare.com   # dari .env, berubah tiap tunnel start
METABASE_INTERNAL_URL=http://metabase:3000                   # dipakai health check & artisan
```

> Quick Tunnel bersifat sementara: hostname berubah setiap `scripts/tunnel/tunnel-up.sh`
> dijalankan. Jangan menulis URL tunnel ke dokumentasi ini — ambil dari `.env`.

## Dashboard IDs

| Dashboard | ID | UUID (public) | Akses | Keterangan |
|---|---|---|---|---|
| **Publik** | `2` | `a5a63c98-29d1-4f69-b430-062b23f58888` | Public link (tanpa login) | 6 tab, 46 kartu, agregat tanpa PII |
| **VIP** | `3` | — | Signed embed (login VIP) | 3 kartu lama — **belum dibangun ulang** |
| **Admin** | `4` | — | — | **Dihapus 21 Okt 2026** (tidak dipakai, mengurangi beban render) |

> **Penting — public embed memakai UUID, bukan ID numerik.**
> Endpoint `/public/dashboard/{id}` milik Metabase hanya menerima `public_uuid`.
> Memakai ID numerik membuat iframe menampilkan "Unauthenticated" alih-alih
> dashboard. Aplikasi membacanya dari `METABASE_PUBLIC_DASHBOARD_UUID`.
>
> ID numerik dipakai untuk hal lain (mis. `artisan security:scan-pii` yang memanggil
> API sebagai admin terotorisasi) lewat `METABASE_PUBLIC_DASHBOARD_ID`.
>
> UUID diambil ulang dengan:
> `SELECT public_uuid FROM report_dashboard WHERE id = <METABASE_PUBLIC_DASHBOARD_ID>;`

Dashboard publik berisi **46 kartu native SQL** ke database `backbone_client` (id `2`).
Spesifikasi lengkap tiap kartu (SQL, definisi, angka ekspektasi) ada di
[`metabase_questions.md`](../metabase_questions.md).

## Struktur Dashboard Publik (id 2)

| Tab ID | Nama | Jumlah kartu |
|---|---|---|
| `4` | Eksekutif | 8 |
| `5` | Sekolah | 8 |
| `6` | Rombel | 7 |
| `7` | Peserta Didik | 8 |
| `8` | ATS | 7 |
| `9` | Sarana Prasarana | 8 |

Tata letak memakai `row` **kumulatif** (`row = row sebelumnya + size_y`) dan **tidak boleh
bertumpuk**. Dua kartu yang menempati sel grid yang sama akan digeser Metabase ke bawah saat
render, tetapi tinggi dashboard (posisi footer) tetap dihitung dari `row` deklaratif — konten
jadi lebih panjang dari wadah dan kartu terakhir menembus footer "Didukung oleh Metabase".

Contoh yang pernah terjadi di tab Sarana Prasarana: SAR-07 dipasang `row 12 col 12` padahal
SAR-04 sudah menempati `row 8–16 col 12`. SAR-07 terdorong ke `row 16`, SAR-08 ke `row 24`,
sementara footer tetap di `row 30` → tertimpa 162 px. Setelah diperbaiki (SAR-05 `12×12`,
SAR-07 `row 16`, SAR-08 `row 24`), tinggi deklaratif = 34 = tinggi nyata.

Verifikasi cepat (read-only, butuh kredensial di `.env`):

```bash
python3 scripts/metabase/check-layout.py          # dashboard publik (id 2)
python3 scripts/metabase/check-layout.py --dashboard 3
```

Skrip melaporkan tabrakan sel, kartu yang melewati 24 kolom, dan tinggi deklaratif per tab.
Exit code 0 = aman.

## Parameter Dashboard Publik

| Parameter ID | Nama | Tipe | Field sekolah | Field ATS | Sumber nilai |
|---|---|---|---|---|---|
| `p-kecamatan` | Kecamatan | `string/=` | `ref.mst_wilayah.nama` (2546) | `dbo.ats.kecamatan` (1385) | Card `97` (8) |
| `p-desa` | Desa/Kelurahan | `string/=` | `dbo.sekolah.desa_kelurahan` (1790) | `dbo.ats.desa_kelurahan` (1379) | Card `98` (88) |
| `p-jenjang` | Jenjang Satuan Pendidikan | `string/=` | `ref.bentuk_pendidikan.nama` (1993) | — | Card `99` (7) |
| `p-status` | Status Sekolah | `number/=` | `dbo.sekolah.status_sekolah` (1811) | — | Static list (2) |
| `p-jenis-ptk` | Jenis PTK | `string/=` | `ref.jenis_ptk.jenis_ptk` (2264) | — | Card `100` (3) |
| `p-jabatan-ptk` | Jabatan PTK | `string/=` | `ref.jabatan_ptk.jabatan_ptk` (2072) | — | Card `101` (26) |
| `p-jenjang-ats` | Jenjang (ATS) | `string/=` | — | `dbo.ats.jenjang_pendidikan` (1387) | Card `102` (6) |

`p-jenjang-ats` sengaja terpisah karena domain nilainya berbeda (`SD / sederajat`,
`Paket A/B/C`, dst.). `p-kecamatan` dan `p-desa` dipakai bersama oleh tab sekolah dan tab ATS;
satu parameter dapat dipetakan ke dua field berbeda per kartu.

> **Sumber nilai wajib ditetapkan.** Tanpa `values_source_type`, dropdown menarik nilai dari
> seluruh isi field: `ref.mst_wilayah` berisi ribuan wilayah Indonesia dan `dbo.ats.kecamatan`
> masih memuat nama dari luar Karangasem. Akibatnya dropdown menampilkan nama seperti
> "Lembudud" atau "Pa Butal". Nilai kini diambil dari 6 kartu bantu (lihat di bawah), dan
> Status memakai static list `Negeri = 1`, `Swasta = 2`.

## Kartu Bantu Filter (6)

Collection: **`Filter — Daftar Nilai`** (id `6`). Bukan kartu analitik — hanya pemasok opsi dropdown.

| Card ID | Nama | Opsi | Dipakai parameter |
|---|---|---|---|
| `97` | Daftar Kecamatan | 8 | `p-kecamatan` |
| `98` | Daftar Desa/Kelurahan | 88 | `p-desa` |
| `99` | Daftar Jenjang Satuan Pendidikan | 7 | `p-jenjang` |
| `100` | Daftar Jenis PTK | 3 | `p-jenis-ptk` |
| `101` | Daftar Jabatan PTK | 26 | `p-jabatan-ptk` |
| `102` | Daftar Jenjang ATS | 6 | `p-jenjang-ats` |

## Inventaris Kartu (46)

Collection: **`Dashboard Publik — Kartu`** (id `5`).

| Katalog | Card ID | Tab | Tampilan | Judul |
|---|---|---|---|---|
| Q-EXE-01 | `51` | Eksekutif | scalar | Total Sekolah Aktif |
| Q-EXE-02 | `52` | Eksekutif | scalar | Total Rombel Aktif (Kelas) |
| Q-EXE-03 | `53` | Eksekutif | scalar | Total Peserta Didik Aktif |
| Q-EXE-04 | `54` | Eksekutif | scalar | Total PTK Aktif |
| Q-EXE-05 | `55` | Eksekutif | scalar | Rasio Peserta Didik per Guru |
| Q-EXE-06 | `56` | Eksekutif | scalar | Rata-rata Murid per Rombel |
| Q-EXE-07 | `57` | Eksekutif | scalar | Persentase Registrasi Aktif dengan NISN Sah |
| Q-EXE-08 | `58` | Eksekutif | bar | Sebaran Sekolah per Jenjang |
| Q-SEK-01 | `59` | Sekolah | row | Jumlah Sekolah per Kecamatan |
| Q-SEK-02 | `60` | Sekolah | bar | Sekolah per Jenjang dan Status |
| Q-SEK-03 | `61` | Sekolah | pie | Sebaran Akreditasi Sekolah |
| Q-SEK-04 | `62` | Sekolah | row | Sekolah per Status Kepemilikan |
| Q-SEK-05 | `63` | Sekolah | bar | Rata-rata Rombel per Sekolah |
| Q-SEK-06 | `64` | Sekolah | table | Sekolah Tanpa Kepala Sekolah |
| Q-SEK-07 | `65` | Sekolah | table | Sekolah Tanpa Rombel |
| Q-SEK-08 | `66` | Sekolah | table | Daftar Sekolah dan Jumlah Rombel, PD, PTK |
| Q-ROM-01 | `67` | Rombel | scalar | Total Rombel Aktif (Kelas) |
| Q-ROM-02 | `68` | Rombel | bar | Rombel per Jenjang |
| Q-ROM-03 | `69` | Rombel | row | Rombel per Kecamatan |
| Q-ROM-04 | `70` | Rombel | bar | Rata-rata Murid per Rombel per Jenjang |
| Q-ROM-05 | `71` | Rombel | bar | Kepatuhan Ukuran Rombel terhadap Standar per Jenjang |
| Q-ROM-06 | `72` | Rombel | table | Rombel Melebihi Standar per Jenjang |
| Q-ROM-07 | `73` | Rombel | table | Rombel dengan Wali Kelas Tidak Aktif |
| Q-PD-01 | `74` | Peserta Didik | scalar | Total Peserta Didik Aktif |
| Q-PD-02 | `75` | Peserta Didik | bar | Peserta Didik per Jenjang |
| Q-PD-03 | `76` | Peserta Didik | row | Peserta Didik per Kecamatan |
| Q-PD-04 | `77` | Peserta Didik | bar | Peserta Didik per Tingkat Kelas |
| Q-PD-05 | `78` | Peserta Didik | row | Status NISN Peserta Didik |
| Q-PD-06 | `79` | Peserta Didik | bar | Peserta Didik Keluar per Jenis Keluar |
| Q-PD-07 | `80` | Peserta Didik | table | Rata-rata Jarak dan Waktu Tempuh ke Sekolah |
| Q-PD-08 | `81` | Peserta Didik | table | 20 Sekolah dengan Peserta Didik Terbanyak |
| Q-ATS-01 | `82` | ATS | scalar | Total ATS Usia Sekolah (7–18 Tahun) |
| Q-ATS-02 | `83` | ATS | pie | Komposisi Status ATS |
| Q-ATS-03 | `84` | ATS | row | ATS per Kecamatan |
| Q-ATS-04 | `85` | ATS | bar | ATS per Jenjang Pendidikan Terakhir |
| Q-ATS-05 | `86` | ATS | bar | ATS per Kelompok Usia |
| Q-ATS-06 | `87` | ATS | bar | ATS per Tahun Pendataan |
| Q-ATS-07 | `88` | ATS | table | 15 Desa dengan ATS Terbanyak |
| Q-SAR-01 | `89` | Sarana Prasarana | table | Jumlah Sarana per Kategori |
| Q-SAR-02 | `90` | Sarana Prasarana | bar | Kondisi Ruang (Tercatat) |
| Q-SAR-03 | `91` | Sarana Prasarana | scalar | Rata-rata Tingkat Kerusakan Ruang |
| Q-SAR-04 | `92` | Sarana Prasarana | row | Ruang Rusak Berat per Kecamatan |
| Q-SAR-05 | `93` | Sarana Prasarana | bar | Kecukupan Ruang Kelas terhadap Rombel |
| Q-SAR-06 | `94` | Sarana Prasarana | scalar | Persentase Sekolah Memiliki Perpustakaan |
| Q-SAR-07 | `95` | Sarana Prasarana | bar | Sumber Air Bersih Sekolah |
| Q-SAR-08 | `96` | Sarana Prasarana | row | 10 Jenis Alat Terbanyak |

## Database Connections

| Koneksi | ID | User | Schema | Query Native | Dipakai Untuk |
|---|---|---|---|---|---|
| `backbone_client` | `2` | `metabase_user` (anggota role `analis`, **SELECT saja**) | `dbo`, `ref`, `qc` | Ya | Dashboard Publik & VIP |

**Catatan:** hanya ada satu koneksi. `metabase_user` tidak bisa menulis ke basis data
pendidikan; skema `datamart` kosong (0 baris) sehingga tidak dipakai. Laravel **tidak pernah**
mengakses `backbone_client` — hanya Metabase.

## Kontrol Akses

| Role | Akses | Keterangan |
|---|---|---|
| **Public** | Hanya dashboard Publik | Tanpa login; agregat tanpa PII |
| **VIP** | Hanya dashboard VIP | Login; signed embed, boleh memuat detail |
| **Admin** | Full | Kelola Metabase, bukan akses data mentah |

**Kontrol akses dilakukan melalui permission per dashboard/collection, bukan per koneksi database.**

## Caching

- Dashboard publik saat ini **tanpa cache TTL** (`cache_ttl = null`); Metabase menjalankan
  kartu setiap kali dashboard dibuka.
- Bila perlu: Admin → Performance → Caching, lalu set TTL pada dashboard/kartu berat
  (Q-SEK-08 604 baris, Q-ROM-06 448 baris, Q-PD-04).
- Jangan set TTL lebih panjang dari jadwal sinkronisasi backbone, agar angka tidak basi.

## Embedding

- **Public link/embed:** Dashboard Publik, memakai `public_uuid`.
- **Static/Guest Embedding (signed JWT):** diaktifkan untuk Dashboard VIP.
- **Embedding Secret Key:** `METABASE_EMBEDDING_SECRET` (Laravel) harus **identik** dengan
  `MB_EMBEDDING_SECRET_KEY` (Metabase).

## Prosedur Rotasi Secret

1. Generate secret baru: `openssl rand -hex 32`
2. Update `MB_EMBEDDING_SECRET_KEY` di `deploy/metabase/.env`
3. Update `METABASE_EMBEDDING_SECRET` di `.env` Laravel
4. Restart Metabase: `docker compose -f deploy/metabase/docker-compose.yml restart`
5. **Verifikasi rotasi berhasil:**
   ```bash
   cd dashboard-karangasem
   vendor/bin/sail artisan metabase:check-embedding
   ```
   Perintah ini memvalidasi konfigurasi lewat service yang sama dengan yang dipakai
   produksi, memuat satu URL bertanda tangan, dan melaporkan status HTTP. Exit code `1`
   bila konfigurasi tidak valid **atau** Metabase menjawab 4xx/5xx; `0` hanya bila 2xx/3xx.
   Respons 401/403 biasanya berarti embedding belum aktif atau secret tidak identik.
   Secret dan token tidak pernah tercetak di output maupun log.
6. Uji manual: buka halaman publik dan halaman VIP, pastikan iframe dashboard tetap tampil.

### Jangan rotasi `MB_ENCRYPTION_SECRET_KEY` tanpa kebutuhan

`MB_ENCRYPTION_SECRET_KEY` mengenkripsi **kredensial koneksi database** yang tersimpan di
metadata Metabase. Merotasi kunci enkripsi membuat seluruh kredensial koneksi harus di-set
ulang lewat Admin Panel (sudah diperingatkan di `deploy/metabase/docker-compose.yml`).
Rotasi secret **embedding** tidak membutuhkan hal ini — keduanya berbeda.

### Catatan soal token

JWT dipakai untuk **penandatanganan (signed)**, bukan enkripsi: siapa pun dapat membaca isi
`resource`, `params`, dan `exp`. Karena itu TTL sengaja pendek (`METABASE_EMBED_TTL`,
default 600 detik) dan halaman VIP mengirim `Cache-Control: no-store, private`.

## Troubleshooting

| Gejala | Kemungkinan penyebab | Aksi |
|---|---|---|
| Iframe publik menampilkan "Unauthenticated" | `METABASE_PUBLIC_DASHBOARD_UUID` kosong/salah, atau diisi ID numerik | Public embed wajib UUID. Ambil ulang: `SELECT public_uuid FROM report_dashboard WHERE id = <id>;` |
| Iframe putih setelah tunnel restart | `METABASE_SITE_URL` masih hostname tunnel lama | jalankan `scripts/tunnel/tunnel-up.sh`, lalu `vendor/bin/sail artisan config:clear` |
| Filter Kecamatan tidak mengubah angka | mapping parameter tidak terpasang pada dashcard | cek `parameter_mappings` dashcard → `["dimension", ["template-tag", "kecamatan"]]` |
| Kartu gagal "invalid reference to FROM-clause entry" | tabel yang difilter diberi alias di SQL | tulis `FROM dbo.sekolah` tanpa alias (field filter dikompilasi ke nama tabel fisik) |
| Dropdown Status berisi 1/2 | display values belum dipasang | Admin → Data Model → `dbo.sekolah.status_sekolah` → `{"1":"Negeri","2":"Swasta"}` |
| Angka PD berbeda antar tab | satu kartu memakai basis anggota rombel, yang lain NISN | lihat §2 `metabase_questions.md`; jangan mencampur basis dalam satu pembagian |
| Login VIP mendarat di `/admin` lalu 403 | Outdated `url.intended` di sesi | akses lagi dari `/login`; lihat `VipLoginController::intendedVipUrl` |
| Panel admin menampilkan halaman penyiapan 2FA | Admin belum memasang authenticator | "Mulai penyiapan" → pindai QR → masukkan kode 6 digit |
| Metabase tidak bisa konek DB backbone | host/port/firewall/SSL | `psql -h <host> -U metabase_user -d backbone_client` dari container |
| Dashboard kosong / "table not found" | GRANT belum ada untuk `metabase_user`/`analis` | jalankan ulang GRANT di `database/sql/admin_full_role.sql` |
| Token embed ditolak | secret tidak sama / exp salah / TTL lewat | samakan secret, cek `exp` epoch detik |
| Metadata hilang setelah restart | volume tidak persisten | cek `volumes: postgres_data` di compose |
