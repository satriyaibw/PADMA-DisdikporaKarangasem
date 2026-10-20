# Snapshot statistik landing

Landing PADMA (`/`) sengaja **stateless**: tidak ada query database, session,
CSRF, atau cookie saat request. Angka kartu dibaca dari satu file snapshot JSON
yang dihasilkan oleh query read-only, direview, lalu diterapkan dengan approval
hash.

Dokumen ini adalah alur wajib saat pertama kali menyiapkan proyek dan setelah
data Pusdatin diperbarui.

## 1. Definisi yang dipakai query

Query ada di `dashboard-karangasem/database/sql/landing_snapshot.sql`. Semua
kartu memakai CTE scope yang sama:

| Objek | Definisi default |
|---|---|
| Wilayah | `ref.mst_wilayah` level 3, prefix kode `2208` (Kabupaten Karangasem) |
| Sekolah | `dbo.sekolah.Soft_delete = 0 AND keaktifan = 1` |
| Rombel | `dbo.rombongan_belajar.Soft_delete = 0`, semester terbaru atau `:semester` |
| Peserta didik | `count(DISTINCT peserta_didik_id)` dari anggota rombel terpilih |
| PTK | `count(DISTINCT ptk_id)` pada `tahun_ajaran_id` terbaru |
| Kecamatan | 8 kecamatan; jumlah selain 8 membuat snapshot ditolak |

Query juga menampilkan pembanding agar definisi tidak tersembunyi:

- jumlah **semua** sekolah vs sekolah aktif;
- sekolah yang memiliki rombel pada semester terpilih;
- jumlah anggota rombel vs peserta didik unik;
- jumlah peserta didik per kecamatan (bisa lebih besar dari angka unik karena
  seorang peserta didik dapat tercatat di lebih dari satu kecamatan);
- bentuk pendidikan yang benar-benar masuk scope.

> Status data saat dokumen ini dibuat: dump menunjukkan **843 sekolah total**
> dan **604 sekolah aktif** (`Soft_delete=0 AND keaktifan=1`). Angka **634**
> sekolah aktif yang disebut sebagai angka resmi belum dapat direproduksi dari
> dump tersebut. Jangan `apply` sebelum angka ini direkonsiliasi dengan data
> Pusdatin terbaru atau definisi resmi.

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
curl -s http://localhost:8000/ | grep -E 'Satuan Pendidikan|604|68\.307'
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
- [ ] Semester yang dipakai benar dan rombel yang tidak aktif sudah dikecualikan.
- [ ] Cakupan bentuk pendidikan disetujui: TK/PAUD, SD, SMP, nonformal
      (PKBM/SKB), atau hanya jenjang formal.
- [ ] Peserta didik memakai angka unik; selisih jumlah per kecamatan dijelaskan.
- [ ] PTK memakai definisi yang disepakati (unik vs penempatan).
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
memaksa hash join mencegah query lambat. Query tidak mengambil nama, NISN, NIK,
atau data pribadi.

## 7. Troubleshooting

| Gejala | Penyebab | Aksi |
|---|---|---|
| `Container 'backbone_client' tidak ditemukan` | Backbone belum dijalankan | Jalankan setup Metabase/backbone atau set `BACKBONE_CONTAINER` |
| Query timeout | Statistik planner kosong/bloat | Pastikan file SQL terbaru (ada `enable_nestloop=off`); jalankan `ANALYZE` di backbone bila perlu |
| `Snapshot tidak valid` | JSON terpotong, kecamatan bukan 8, scope berubah | Jalankan ulang `review`; jangan edit JSON manual |
| `Hash approval tidak cocok` | Query/data berubah setelah review | Review ulang dan pakai hash terbaru |
| Landing masih menampilkan angka lama | Snapshot belum di-apply, path salah, atau cache config | `verify`, cek `LANDING_SNAPSHOT_PATH`, lalu `optimize:clear && optimize` |
| Angka sekolah 604, bukan 634 | Dump belum sinkron atau definisi berbeda | Rekonsiliasi dengan Pusdatin; jangan apply sebelum disetujui |

## 8. Hubungan dengan dashboard

Saat ini dashboard Metabase dan landing memakai definisi yang berbeda
(`metrics.v_peserta_didik` dan `metrics.v_ptk` masih bermasalah). Snapshot ini
adalah sumber yang direview untuk landing. Penyelarasan kartu dashboard dan
generasi kartu massal dari query yang sama dikerjakan terpisah setelah definisi
data disetujui.
