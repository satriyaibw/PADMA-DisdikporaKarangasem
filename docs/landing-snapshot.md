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
| Peserta didik | `count(DISTINCT btrim(nisn))` dengan NISN valid 10 digit dari registrasi aktif (`dbo.registrasi_peserta_didik.Soft_delete = 0 AND jenis_keluar_id IS NULL`) di sekolah aktif; ID tanpa NISN valid dikecualikan |
| PTK | `count(DISTINCT ptk_id)` dengan penugasan induk (`ptk_induk = 1`), tidak keluar, dan `qc.vld_ptk.aktif = 1` di sekolah aktif (definisi kartu dashboard Q-EXE-04) |
| Kecamatan | 8 kecamatan; jumlah selain 8 membuat snapshot ditolak |

Query juga menampilkan pembanding agar definisi tidak tersembunyi:

- jumlah **semua** sekolah vs sekolah aktif;
- sekolah yang memiliki rombel Kelas pada semester terpilih;
- jumlah **semua jenis** rombel vs rombel Kelas;
- jumlah anggota rombel Kelas (diagnostik) vs peserta didik unik dari registrasi aktif;
- jumlah peserta didik per kecamatan (bisa lebih besar dari angka unik karena
  seorang peserta didik dapat tercatat di lebih dari satu kecamatan);
- kualitas NISN: NISN kosong, tidak valid, dan NISN ganda;
- PTK: total penugasan induk, PTK aktif `qc.vld_ptk`, serta diagnostik aktif semester/TMT dan PTK non-induk;
- bentuk pendidikan yang benar-benar masuk scope.

> Status data per **29 Oktober 2026** (pull terakhir; snapshot diterapkan
> **5 November 2026**): dump menunjukkan **843 sekolah total** dan **604 sekolah
> aktif** (`Soft_delete=0 AND keaktifan=1`). Angka **634** sekolah aktif yang
> disebut sebagai angka resmi belum dapat direproduksi dari dump tersebut.
> **604 diterima sebagai scope snapshot saat ini**; jangan ubah tanpa bukti/data
> Pusdatin baru.
>
> Angka acuan snapshot: **604** satuan pendidikan, **68.983** peserta didik,
> **3.482** rombel Kelas, dan **4.977** PTK. Definisi peserta didik dan PTK
> sengaja disamakan dengan kartu dashboard publik (Q-EXE-03/Q-EXE-04) agar
> landing dan dashboard tidak saling bertentangan.
>
> Di balik angka tersebut: 70.381 ID peserta didik dengan registrasi aktif,
> 1.372 NISN kosong, 2 NISN tidak valid, 69.007 ID dengan NISN valid, dan 23
> nilai NISN ganda (24 ID tambahan) sehingga menjadi 68.983 NISN unik. Jumlah
> per kecamatan 69.012 (lebih besar karena 29 NISN tercatat di lebih dari satu
> kecamatan). Anggota rombel Kelas berjumlah 64.621 dan semua jenis rombel
> 5.098; keduanya hanya pembanding diagnostik, bukan angka kartu.
>
> PTK penugasan induk dengan `qc.vld_ptk.aktif = 1` berjumlah 4.977. Pembanding
> diagnostik: 4.033 aktif semester, 4.037 aktif semester + TMT, 4.042 aktif
> dengan TMT sejak awal bulan semester, dan 129 PTK hanya non-induk (20 aktif)
> tidak masuk kartu. Angka 4.042 **bukan** angka kartu; kartu memakai definisi
> dashboard (4.977).
>
> Rincian pembanding terbaru selalu ada di artifact
> `storage/app/landing/review-*.md`; bandingkan dengan artifact terakhir
> sebelum menyalin angka dari dokumen ini.

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
- [ ] PTK memakai penugasan induk, bukan penempatan; status keluar dan
      `qc.vld_ptk.aktif = 1` (definisi dashboard) sudah diperiksa. Pembanding
      aktif semester/TMT hanya diagnostik dan bukan angka kartu.
- [ ] PTK non-induk pada pembanding sudah dipahami dan tidak termasuk kartu.
- [ ] Tepat 8 kecamatan dan tidak ada wilayah yang hilang.
- [ ] Angka kartu sudah sama dengan dashboard publik (Q-EXE-01..04). Jika
      berbeda, jangan apply sebelum penyebabnya dijelaskan.
- [ ] Tanggal **Data per** berasal dari `sync.pull_log` terakhir, bukan
      `last_sync` baris.
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

Landing memakai definisi yang sama dengan kartu headline dashboard publik:

- **Q-EXE-01 Total Sekolah Aktif**: 604.
- **Q-EXE-02 Total Rombel Aktif (Kelas)**: 3.482.
- **Q-EXE-03 Total Peserta Didik Aktif**: 68.983 (registrasi aktif, NISN valid
  10 digit).
- **Q-EXE-04 Total PTK Aktif**: 4.977 (penugasan induk, `qc.vld_ptk.aktif = 1`).

Perbedaan dengan `metrics.v_*` (bukan kartu dashboard):

- `metrics.v_rombongan_belajar` menghitung **5.098** semua jenis rombel;
  kartu/landing menghitung **3.482** rombel Kelas.
- `metrics.v_ptk` menghitung **5.172** penempatan PTK; kartu/landing
  menghitung **4.977** PTK induk aktif.
- `metrics.v_peserta_didik` masih bermasalah dan belum dipakai sebagai acuan.

Landing adalah snapshot, dashboard adalah live view. Keduanya membaca mirror
yang sama, sehingga angka kartu harus sama saat snapshot dibuat. Jika berbeda,
periksa `sync.pull_log`, cache Metabase, dan definisi kartu sebelum apply.
