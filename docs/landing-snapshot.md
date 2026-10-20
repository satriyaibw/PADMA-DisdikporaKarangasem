# Snapshot statistik landing

Landing PADMA (`/`) sengaja **stateless**: tidak ada query database, session,
CSRF, atau cookie saat request. Angka kartu dibaca dari satu file snapshot JSON
yang dihasilkan oleh query read-only, direview, lalu diterapkan dengan approval
hash.

Dokumen ini adalah alur wajib saat pertama kali menyiapkan proyek dan setelah
data Pusdatin diperbarui.

## 1. Definisi yang dipakai query

Query ada di `dashboard-karangasem/database/sql/landing_snapshot.sql`. Wilayah dan
sekolah memakai scope bersama; rombel, peserta didik, dan PTK memakai filter
masing-masing yang didokumentasikan di bawah ini.

| Objek | Definisi default |
|---|---|
| Wilayah | `ref.mst_wilayah` level 3, prefix kode `2208` (Kabupaten Karangasem) |
| Sekolah | `dbo.sekolah.Soft_delete = 0 AND keaktifan = 1` |
| Rombel | `dbo.rombongan_belajar.Soft_delete = 0`, `jenis_rombel = 1` (Kelas), semester terbaru atau `:semester` |
| Peserta didik | `count(DISTINCT btrim(nisn))` dengan NISN valid 10 digit dari anggota rombel Kelas; ID tanpa NISN valid dikecualikan |
| PTK | `count(DISTINCT ptk_id)` dengan penugasan induk (`ptk_induk = 1`), tidak keluar, dan aktif minimal satu bulan semester atau TMT sejak awal bulan semester sampai tanggal snapshot |
| Kecamatan | 8 kecamatan; jumlah selain 8 membuat snapshot ditolak |

Query juga menampilkan pembanding agar definisi tidak tersembunyi:

- jumlah **semua** sekolah vs sekolah aktif;
- sekolah yang memiliki rombel Kelas pada semester terpilih;
- jumlah **semua jenis** rombel vs rombel Kelas;
- jumlah anggota rombel Kelas vs peserta didik unik;
- jumlah peserta didik per kecamatan (bisa lebih besar dari angka unik karena
  seorang peserta didik dapat tercatat di lebih dari satu kecamatan);
- kualitas NISN: NISN kosong, tidak valid, dan NISN ganda;
- PTK: total penugasan induk, aktif semester, aktif dengan TMT, dan PTK non-induk;
- bentuk pendidikan yang benar-benar masuk scope.

> Status data saat dokumen ini dibuat: dump menunjukkan **843 sekolah total**
> dan **604 sekolah aktif** (`Soft_delete=0 AND keaktifan=1`). Angka **634**
> sekolah aktif yang disebut sebagai angka resmi belum dapat direproduksi dari
> dump tersebut. **604 diterima sebagai scope snapshot saat ini**; jangan ubah
> tanpa bukti/data Pusdatin baru.
>
> Angka acuan snapshot: **604** satuan pendidikan, **64.086** peserta didik,
> **3.482** rombel Kelas, dan **4.044** PTK. Di balik angka tersebut: 64.495 ID
> peserta didik di rombel Kelas, 406 NISN kosong, 0 NISN tidak valid, dan 3
> nilai NISN ganda (6 ID). Rombel semua jenis berjumlah 5.077. PTK penugasan
> induk berjumlah 4.981; 4.036 aktif semester, 4.044 setelah ditambah TMT sejak
> awal bulan semester (8 PTK baru: 4 pada Juli dan 4 setelahnya), dan 124 PTK
> hanya non-induk (20 aktif) tidak masuk kartu.
>
> Catatan rekonsiliasi PTK: angka **4.044** dapat direproduksi dengan aturan TMT
> sejak awal bulan semester (1 Juli 2026) sampai tanggal snapshot. Jendela 30
> hari literal sebelum tanggal mulai semester menghasilkan **4.059**, sehingga
> bukan angka yang dipakai. Angka **4.044** adalah angka yang disetujui untuk
> kartu.

## 2. Prasyarat

- Container PostgreSQL backbone berjalan, default `backbone_client`.
- Aplikasi Laravel/Sail berjalan di `dashboard-karangasem`.
- `docker`, `bash`, dan akses ke container tersedia.
- File SQL dan `vendor/bin/sail` ada.

Variabel opsional (tidak disimpan di repository):

```bash
export BACKBONE_CONTAINER=backbone_client
export BACKBONE_DB_NAME=backbone_client
export BACKBONE_DB_USER=postgres          # untuk lokal; produksi pakai role read-only
export BACKBONE_DB_PASSWORD=              # hanya bila role memerlukan password
export LANDING_SNAPSHOT_SEMESTER=20261    # kosong = semester terbaru
```

Untuk produksi, buat role khusus yang hanya boleh `SELECT` pada schema `dbo`
dan `ref`, lalu isi `BACKBONE_DB_USER`/`BACKBONE_DB_PASSWORD` pada environment
operator. Jangan menulis password ke repository atau artifact review.

## 3. Alur pertama kali / setelah sinkronisasi Pusdatin

Dari root repository:

```bash
# 1. Query read-only + review. Snapshot belum diubah.
./scripts/landing-snapshot.sh review

# 2. Baca artifact review dan angka pembandingnya.
less dashboard-karangasem/storage/app/landing/review-*.md

# 3. Setelah angka dan definisi disetujui, terapkan dengan hash dari review.
./scripts/landing-snapshot.sh apply <query-hash>

# 4. Verifikasi struktur, hash, dan umur snapshot.
./scripts/landing-snapshot.sh verify --strict

# 5. Muat ulang config/cache aplikasi.
cd dashboard-karangasem
vendor/bin/sail artisan optimize:clear
vendor/bin/sail artisan optimize

# 6. Cek landing.
curl -s http://localhost:8000/ | grep -E 'Satuan Pendidikan|604|64\.086|3\.482|4\.044'
```

`review` menulis:

- `dashboard-karangasem/storage/app/landing/review.json` — hasil query mentah;
- `dashboard-karangasem/storage/app/landing/review-YYYYMMDD-HHMMSS.md` —
  ringkasan, pembanding, hash, dan checklist.

`apply` menulis snapshot ke path `landing_snapshot.path`
(default `storage/app/landing/snapshot.json`). `apply` **menolak** hash yang
tidak sama, sehingga hasil query yang berubah setelah review tidak bisa
diterapkan tanpa review ulang.

## 4. Checklist review

Setujui hanya setelah semua poin berikut jelas:

- [ ] Definisi sekolah aktif sesuai kebijakan Dinas. Bandingkan total, aktif,
      dan sekolah yang punya rombel.
- [ ] Semester yang dipakai benar dan hanya rombel Kelas (`jenis_rombel=1`)
      yang dihitung.
- [ ] Cakupan bentuk pendidikan disetujui: TK/PAUD, SD, SMP, nonformal
      (PKBM/SKB), atau hanya jenjang formal.
- [ ] Peserta didik memakai NISN valid 10 digit yang unik; NISN kosong, tidak
      valid, dan ganda sudah diperiksa; selisih jumlah per kecamatan dijelaskan.
- [ ] PTK memakai penugasan induk, bukan penempatan; status keluar dan aturan
      aktif semester/TMT sejak awal bulan semester sudah diperiksa.
- [ ] PTK non-induk pada pembanding sudah dipahami dan tidak termasuk kartu.
- [ ] Tepat 8 kecamatan dan tidak ada wilayah yang hilang.
- [ ] Angka dibandingkan dengan dashboard publik/sumber resmi. Jika berbeda,
      catat penyebabnya sebelum apply.
- [ ] Data sudah berasal dari sinkronisasi Pusdatin terbaru.

## 5. Setelah apply

Landing membaca snapshot pada request berikutnya. Tidak ada query database dari
landing. Jika snapshot hilang atau tidak valid, landing memakai nilai fallback
`config/landing.php` dan menulis warning ke log; halaman tetap dapat dibuka,
tetapi angka fallback **tidak boleh dianggap terverifikasi**.

Untuk rollback cepat:

```bash
# Opsi 1: kosongkan path snapshot di .env, lalu muat ulang config.
# LANDING_SNAPSHOT_PATH=/path/yang/tidak/ada
cd dashboard-karangasem
vendor/bin/sail artisan optimize:clear
vendor/bin/sail artisan optimize

# Opsi 2: pulihkan snapshot sebelumnya dari backup storage/app/landing/.
```

Landing tetap memakai `SEO_INDEXABLE=false` selama URL masih lokal/tunnel.
Snapshot tidak mengubah kebijakan indeks.

## 6. Menjalankan query secara manual

Untuk investigasi, query dapat dijalankan langsung:

```bash
docker exec -i backbone_client \
  psql -U postgres -d backbone_client \
  -v semester=20261 -A -t -q -f - \
  < dashboard-karangasem/database/sql/landing_snapshot.sql \
  > /tmp/landing-review.json
```

Query dimulai dengan `BEGIN READ ONLY`, `statement_timeout`, dan
`enable_nestloop=off`. Statistik planner pada dump hasil restore sering kosong;
memaksa hash join mencegah query lambat. Query membaca NISN hanya untuk
deduplikasi; output tetap berupa agregat dan tidak memuat NISN, nama, NIK, atau
data pribadi.

## 7. Troubleshooting

| Gejala | Penyebab | Aksi |
|---|---|---|
| `Container 'backbone_client' tidak ditemukan` | Backbone belum dijalankan | Jalankan setup Metabase/backbone atau set `BACKBONE_CONTAINER` |
| Query timeout | Statistik planner kosong/bloat | Pastikan file SQL terbaru (ada `enable_nestloop=off`); jalankan `ANALYZE` di backbone bila perlu |
| `Snapshot tidak valid` | JSON terpotong, kecamatan bukan 8, scope berubah | Jalankan ulang `review`; jangan edit JSON manual |
| `Hash approval tidak cocok` | Query/data berubah setelah review | Review ulang dan pakai hash terbaru |
| Landing masih menampilkan angka lama | Snapshot belum di-apply, path salah, atau cache config | `verify`, cek `LANDING_SNAPSHOT_PATH`, lalu `optimize:clear && optimize` |
| Angka sekolah 604, bukan 634 | Dump belum sinkron atau definisi berbeda | 604 adalah scope snapshot saat ini; bandingkan ulang bila ada data Pusdatin baru |

## 8. Hubungan dengan dashboard

Dashboard Metabase dan landing masih memakai definisi yang berbeda:

- `metrics.v_rombongan_belajar` menghitung **5.077** semua jenis rombel;
  landing menghitung **3.482** rombel Kelas.
- `metrics.v_ptk` menghitung **5.170** penempatan PTK; landing menghitung
  **4.044** PTK aktif dengan penugasan induk.
- `metrics.v_peserta_didik` masih bermasalah dan belum dipakai sebagai acuan.

Snapshot ini adalah sumber yang direview untuk landing. Penyelarasan kartu
dashboard dan generasi kartu massal dari query yang sama dikerjakan terpisah
setelah definisi data disetujui.
