# Dashboard Data Pendidikan — Disdikpora Kabupaten Karangasem

Portal analytics pendidikan Kabupaten Karangasem: dashboard agregat untuk publik, dashboard analitik eksklusif untuk pengguna VIP, dan panel administrasi akun.

[![Laravel](https://img.shields.io/badge/Laravel-13.17-FF2D20?style=flat&logo=laravel&logoColor=white)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?style=flat&logo=php&logoColor=white)](https://php.net)
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-18-4169E1?style=flat&logo=postgresql&logoColor=white)](https://postgresql.org)
[![Metabase](https://img.shields.io/badge/Metabase-OSS%20v0.55.4-0052A4?style=flat)](https://www.metabase.com)
[![Filament](https://img.shields.io/badge/Filament-5-FDB022?style=flat&logo=filament&logoColor=black)](https://filamentphp.com)

Dokumen kunci: **[`MasterPlan.md`](./MasterPlan.md)** (rencana 7 fase) · **[`docs/runbook.md`](./docs/runbook.md)** (operasional harian).

## Tentang Proyek

Sistem ini menyajikan data pendidikan Kabupaten Karangasem — peserta didik, rombongan belajar, dan PTK — yang selama ini tersebar di database Pusdatin (`backbone_client`) sehingga sulit ditelusuri oleh publik.

Arsitekturnya sengaja memisahkan tanggung jawab: **Metabase** menjadi mesin analitik yang membaca data sumber secara read-only, sedangkan **Laravel** menjadi pembungkus web yang mengatur autentikasi, kontrol akses, dan penyajian. Laravel tidak pernah menyentuh `backbone_client`; satu-satunya basis data yang diaksesnya adalah `app_db` untuk data pengguna, sesi, dan audit.

| Peran | Akses | URL |
| --- | --- | --- |
| Publik | Landing page + agregat non-sensitif, tanpa login | `/` dan `/dashboard` |
| VIP | Agregat detail, login + masa aktif | `/vip/dashboard` |
| Admin | Manajemen pengguna VIP via Filament | `/admin` |

## Fitur Utama

**Landing Page Publik** — halaman `/` responsif dan SEO-ready: ringkasan data, profil singkat PADMA, kategori data, sebaran delapan kecamatan, metodologi, FAQ, dan tautan ke dashboard. Hanya diindeks saat `SEO_INDEXABLE=true`.

**Dashboard Publik** — agregat peserta didik, rombongan belajar, dan PTK per kecamatan serta jenjang, dilayani public embed tanpa token.

**Dashboard VIP** — analitik lebih mendalam dengan PII terbatas, dilayani signed embed (JWT HS256, TTL pendek) dan refresh token otomatis sebelum kedaluwarsa.

**Panel Admin** — CRUD pengguna VIP, pengaturan peran, pencatatan audit aktivitas, dan MFA TOTP wajib.

**Keamanan** — CSP per-rute, HSTS, rate limit per rute, kebijakan kata sandi, audit log whitelist, serta pemindaian PII terjadwal.

**Operasional** — scheduler terjadwal, backup/restore dengan restore drill, health check `/up`, dan log JSON terstruktur dengan request ID.

## Tech Stack

| Layer | Teknologi | Versi | Keterangan |
| --- | --- | --- | --- |
| Data sumber | PostgreSQL | 16.x | `backbone_client` milik Pusdatin, dibaca read-only oleh Metabase |
| Kontrak data | PostgreSQL | 16.x | Schema `metrics` — view agregat dengan bentuk *long* |
| Metadata DB | PostgreSQL | 16.x / 18 | `metabase_postgres` (Metabase) dan `app_db` (aplikasi, Postgres 18 di CI) |
| Analytics | Metabase OSS | v0.55.4 | Self-hosted via Docker Compose, 3 dashboard |
| Backend | Laravel | 13.x | Membungkus & mengontrol akses |
| Admin panel | Filament | 5.x | Panel `/admin`, Terbatas role `admin` |
| RBAC | Spatie Permission | 8.x | Peran `admin` & `vip` |
| Embedding | Metabase Static Embed | — | JWT HS256, TTL 600 detik |
| Runtime | PHP | 8.5 | Ekstensi: cURL, PDO, mbstring, openssl, xml |
| CI | GitHub Actions | — | `composer audit` + test + `pint` (`.github/workflows/security.yml`) |

## Struktur Direktori

```text
.
├── dashboard-karangasem/     # Aplikasi Laravel + Filament + Sail
├── database/
│   └── sql/                  # DDL view metrics, role DB, uji kesetaraan
├── deploy/
│   ├── backup/               # Skrip backup harian & restore drill
│   ├── cron/                 # Contoh crontab scheduler & backup
│   ├── metabase/             # Docker Compose Metabase
│   └── nginx/                # Contoh reverse proxy
├── docs/                     # Dokumentasi teknis (runbook, security, UAT)
├── scripts/
│   ├── landing-snapshot.sh   # Pipeline snapshot statistik landing
│   ├── metabase/             # check-layout.py — verifikasi grid dashboard
│   └── tunnel/               # Cloudflare Quick Tunnel (up/down)
├── metabase_questions.md     # Katalog 46 kartu & 6 tab dashboard publik
└── MasterPlan.md             # Rencana global 7 fase
```

## Quick Start (Development)

**Prasyarat:** Docker + Docker Compose, Git, dan akses read-only ke `backbone_client` (atau stub lokal untuk pengembangan). Seluruh perintah dijalankan dari direktori `dashboard-karangasem/`.

```bash
git clone https://github.com/satriyaibw/DashboardDataPendidikan-DisdikporaKarangasem.git
cd DashboardDataPendidikan-DisdikporaKarangasem/dashboard-karangasem
cp .env.example .env
php artisan key:generate
```

Isi `.env` minimal: `ADMIN_EMAIL` dan `ADMIN_PASSWORD` (min. 12 karakter, huruf besar/kecil, angka — `db:seed` gagal bila lemah), `METABASE_SITE_URL`, `METABASE_EMBEDDING_SECRET` (min. 32 karakter, **wajib identik** dengan `MB_EMBEDDING_SECRET_KEY` di Metabase), serta `METABASE_PUBLIC_DASHBOARD_ID` dan `METABASE_VIP_DASHBOARD_ID`.

```bash
./vendor/bin/sail up -d --build
./vendor/bin/sail artisan migrate --seed
./vendor/bin/sail npm install && ./vendor/bin/sail npm run build
```

- Landing page — <http://localhost:8000/>
- Dashboard publik — <http://localhost:8000/dashboard>
- Panel admin — <http://localhost:8000/admin> (login + TOTP)
- Metabase — <http://localhost:3000>

Tanpa Docker: `./vendor/bin/sail` dapat diganti `php artisan` dengan `DB_HOST=127.0.0.1` yang menunjuk ke Postgres lokal. Detail environment ada di `.env.example`.

> **Penting:** `APP_DEBUG` **wajib `false`** di produksi. Route `/up` meneruskan exception apa adanya saat debug aktif sehingga halaman debug membocorkan detail internal.

## Cara Penggunaan

### Sebagai Publik

Buka `/` untuk landing page, lalu pilih **Jelajahi Dashboard Publik** menuju `/dashboard`. Dashboard agregat tampil langsung tanpa login, dilayani public embed Metabase. Landing dan dashboard dibatasi 60 permintaan/menit.

### Sebagai VIP

1. Buka `/login` (atau `/vip/login`) dan masukkan kredensial.
2. Setelah login, Anda diarahkan ke `/vip/dashboard` — iframe signed embed.
3. Token embed di-refresh otomatis lewat `/vip/embed-url` sebelum kedaluwarsa; kegagalan refresh ditangani diam agar halaman tidak berkedip.
4. Masa aktif diatur admin. Saat `expires_at` lewat, job `vip:deactivate-expired` menonaktifkan akun dan middleware menolak akses.

### Sebagai Admin

1. Buka `/admin`, login dengan MFA TOTP.
2. Manajemen pengguna VIP: tambah, perpanjang 30 hari, aktif/nonaktifkan, atur peran.
3. Setiap aksi tercatat di tabel `admin_activity_logs` beserta aktor, perubahan, IP, dan user agent.
4. Jalankan `audit:prune --days=365` untuk memangkas log lama (terjadwal otomatis pukul 03:00 WITA).

## Dashboard Metabase

Dashboard publik di `/dashboard` adalah public embed Metabase (tanpa token) dengan **6 tab** dan **7 filter**:

| Tab | Isi |
| --- | --- |
| Eksekutif | KPI sekolah, rombel, peserta didik, PTK, rasio, dan sebaran sekolah per jenjang |
| Sekolah | Sebaran sekolah per jenjang/status/kecamatan, akreditasi, kepemilikan, serta sekolah tanpa kepala sekolah atau rombel |
| Rombel | Jumlah & ukuran rombel per jenjang/kecamatan, kepatuhan standar, rombel melebihi standar, dan wali kelas tidak aktif |
| Peserta Didik | KPI peserta didik, sebaran per kecamatan/jenjang/tingkat, kualitas NISN, dan peserta didik keluar |
| ATS | Anak usia sekolah 7–18 tahun yang dapat diintervensi (berbasis domisili): status, kecamatan, jenjang, kelompok usia, dan desa |
| Sarana Prasarana | Sarana per kategori, kondisi & tingkat kerusakan ruang, kecukupan ruang kelas, perpustakaan, sumber air, dan alat |

Filter yang tersedia: Kecamatan, Desa/Kelurahan, Jenjang Satuan Pendidikan, Status Sekolah, Jenis PTK, Jabatan PTK, dan Jenjang (ATS). Nilai dropdown diambil dari kartu helper di collection **Filter — Daftar Nilai** (ditambah daftar statis untuk Status Sekolah), sehingga pilihannya relevan dengan data dan tidak memunculkan wilayah yang salah.

**Sumber kebenaran** katalog kartu (46 kartu native SQL ke database `backbone_client`) adalah [`metabase_questions.md`](./metabase_questions.md); ID dashboard, tab, parameter, dan sumber nilai filter ada di [`docs/metabase-ids.md`](./docs/metabase-ids.md).

### Mengubah layout dashboard

1. Ubah definisi kartu SQL di `metabase_questions.md` beserta validasi query-nya.
2. Terapkan ke Metabase: buat/perbarui kartu, lalu kirim ulang dashcard per tab.
3. Jalankan guard grid dari root repo: `python3 scripts/metabase/check-layout.py` (tambahkan `--dashboard 3` untuk dashboard VIP). Exit 0 berarti tidak ada sel bertumpuk dan tidak ada kartu yang melewati 24 kolom. Sel grid **tidak boleh bertumpuk**: Metabase menggeser kartu yang bentrok ke bawah, tetapi tinggi dashboard (posisi footer "Didukung oleh Metabase") tetap dihitung dari `row` deklaratif — kartu terakhir bisa menimpa footer.
4. Muat ulang `/dashboard` penuh (Ctrl+Shift+R); tab yang dibiarkan terbuka menyimpan dokumen dashboard lama. Untuk memaksa semua pengunjung memuat versi baru, naikkan `ASSET_VERSION` di `.env` lalu `sail artisan config:clear && sail artisan config:cache`. URL iframe menjadi `?_v=<ASSET_VERSION>`; bila kosong, nilainya diambil dari mtime `public/build/manifest.json` (berubah tiap `npm run build`).

## Landing Page & SEO

Landing page di `/` memakai snapshot statistik yang direview dan disimpan sebagai JSON di `storage/app/landing/snapshot.json`, sedangkan dashboard di `/dashboard` menampilkan data live dari Metabase. Keduanya merujuk sumber Dapodik yang sama, tetapi hanya landing yang boleh masuk indeks mesin pencari. Pipeline `./scripts/landing-snapshot.sh review|apply|verify` wajib dijalankan saat setup dan setelah sinkronisasi Pusdatin; detailnya di [`docs/landing-snapshot.md`](./docs/landing-snapshot.md). Flag utamanya:

| Env | Default | Perilaku |
| --- | --- | --- |
| `LANDING_ENABLED` | `true` | `false` mengalihkan `/` ke `/dashboard` sebagai jalur rollback cepat |
| `SEO_INDEXABLE` | `false` | `true` mengizinkan indeks landing, `sitemap.xml`, dan `Allow: /` di `robots.txt` |
| `GOOGLE_SITE_VERIFICATION` | kosong | Nilai verifikasi Search Console, dirender sebagai meta tag |
| `BING_SITE_VERIFICATION` | kosong | Nilai verifikasi Bing Webmaster, dirender sebagai meta tag |

Saat `SEO_INDEXABLE=false` (wajib untuk pengembangan dan Quick Tunnel), landing mengirim `X-Robots-Tag: noindex, nofollow, noarchive`, `robots.txt` menulis `Disallow: /`, dan `/sitemap.xml` membalas 404. `/dashboard`, `/login`, `/admin`, dan `/vip/*` selalu noindex.

> **Catatan Quick Tunnel:** `trycloudflare.com` juga menambahkan `X-Robots-Tag: none` dari sisi Cloudflare. Tunnel tidak pernah menjadi bukti indexing — uji indeks hanya setelah domain final terpasang.

Saat go-live di domain final:

1. Set `APP_URL=https://domain-final` dan `SEO_INDEXABLE=true`.
2. Isi `GOOGLE_SITE_VERIFICATION`/`BING_SITE_VERIFICATION` bila memakai Search Console.
3. Jalankan `sail artisan optimize:clear && sail artisan optimize` lalu restart aplikasi.
4. Daftarkan domain, submit `https://domain-final/sitemap.xml`.
5. Biarkan HSTS tetap `false` sampai domain benar-benar stabil.

`robots.txt` dan `sitemap.xml` adalah route Laravel dinamis. Jangan menambahkan `location` statis untuk keduanya di Nginx — lihat `deploy/nginx/dashboard.conf`.

## Testing & Verifikasi

Jalankan dari `dashboard-karangasem/`. Test suite **wajib lewat Sail** — `php artisan test` langsung di host tidak merepresentasikan lingkungan/container yang benar.

| Perintah | Tujuan | Hasil yang diharapkan |
| --- | --- | --- |
| `sail artisan test --compact` | Unit + feature test | `PASS  Tests: 401, Assertions: 917` |
| `sail bin pint --test --format agent` | Gaya kode | Tanpa diff |
| `sail composer audit` | Advisory dependensi | Tidak ada advisory |
| `sail artisan security:scan-pii` | Pemindaian PII dashboard Publik/VIP | Exit 0, laporan di `storage/app/security/` |
| `sail artisan metabase:check-embedding` | Validasi konfigurasi & URL bertanda tangan | Exit 0 |
| `sail artisan schedule:list` | Verifikasi 4 job terjadwal | 4 baris jadwal tampil |
| `python3 scripts/metabase/check-layout.py` | Grid dashboard Metabase (dari root repo) | Exit 0, tidak ada sel bertumpuk |

`sail artisan metabase:check-embedding` memerlukan kredensial `METABASE_ADMIN_*` di `.env`, sedangkan `check-layout.py` dijalankan dari root repo (di luar `dashboard-karangasem/`). Ikuti `docs/uat.md` untuk skenario manual yang tidak dapat diotomatisasi (T9, T10).

> **Triage `security:scan-pii` (2026-10-11):** exit 1 dengan kartu P5 (publik)
> dan V2 (VIP) menandai kolom `nama`. Keduanya hasil `JOIN ref.tingkat_pendidikan`
> dan di-aggregate per tingkat — `nama` di sana berisi label jenjang
> ("Tingkatan 2, Kls 4", "TPA"), bukan nama orang. Scanner sengaja fail-closed,
> jadi **false positive ini diputuskan manusia, bukan diam-diam diabaikan**.
> Perlu keputusan: beri pengecualian per tabel referensi, atau ubah query agar
> tidak men-exposed kolom `nama`.

## Debugging & Pemeliharaan

| Gejala | Aksi pertama |
| --- | --- |
| Iframe putih / embed mati | `sail artisan metabase:check-embedding` |
| Kartu menimpa footer “Didukung oleh Metabase” | Sel grid bertumpuk — jalankan `python3 scripts/metabase/check-layout.py`, perbaiki `row`/`col` |
| Layout dashboard lama setelah kartu diubah | Reload penuh (Ctrl+Shift+R); untuk semua pengunjung naikkan `ASSET_VERSION` lalu `config:cache` (runbook §4.1) |
| VIP masih bisa login padahal lewat masa aktif | `sail artisan vip:deactivate-expired` lalu periksa cron |
| `/up` balas 500 | `grep 'Health check gagal' storage/logs/laravel.log` |
| Perubahan `.env` tidak terlihat | `sail artisan config:clear` |
| Landing `/` dialihkan ke `/dashboard` | `LANDING_ENABLED=false` — set `true` lalu `optimize:clear` |
| Canonical memakai localhost/tunnel | `APP_URL` belum domain final — perbaiki lalu `optimize` |
| Landing tidak terindeks | `SEO_INDEXABLE=false` atau `robots.txt` statis di Nginx |
| Scheduler tidak jalan | `crontab -l` — pastikan `schedule:run` tiap menit |
| Backup gagal | `tail -n 20 storage/logs/backup.log` |

Diagnostik lanjutan: **[`docs/runbook.md`](./docs/runbook.md)** §9 (masalah umum) dan §11 (kontak eskalasi) · **`docs/uat.md`** §9 (alur debugging) · **`docs/metabase-ids.md`** (troubleshooting embed).

## Keamanan

- CSP per-rute via `metabase.csp` dengan `frame-ancestors 'none'`; CSP global sengaja tidak dipasang agar panel Filament tetap berfungsi.
- HSTS hanya dikirim pada request HTTPS + `HSTS_ENABLED=true`.
- MFA TOTP wajib untuk panel admin; login VIP tidak terpengaruh.
- Audit log memakai **whitelist** kolom (deny-by-default), bukan blacklist.
- Kebijakan kata sandi: min. 12 karakter + huruf besar/kecil + angka, ditegakkan bersama oleh form Filament dan seeder.
- Pemindaian PII otomatis tiap Senin 05:00 WITA; pencocokan berbasis batas identifier agar `nama_kecamatan` tidak ikut ditandai.

Verifikasi lengkap: **[`docs/security-checklist.md`](./docs/security-checklist.md)**.

## Backup & Restore

- `deploy/backup/run-backup.sh` — backup harian 02:00 WITA untuk `app_db` + metadata Metabase, dengan verifikasi integritas dan retensi 14 harian + 4 mingguan.
- `deploy/backup/restore-drill.sh` — memulihkan ke database sementara berawalan `restore_drill_`, tidak pernah menyentuh database produksi.
- `backbone_client` **tidak** dibackup — itu milik Pusdatin.

Panduan lengkap: **`deploy/backup/README.md`** dan `docs/runbook.md` §7.

## Dokumentasi Terkait

| Dokumen | Tujuan |
| --- | --- |
| [`MasterPlan.md`](./MasterPlan.md) | Rencana global 7 fase & peta database nyata |
| [`metabase_questions.md`](./metabase_questions.md) | Katalog 46 kartu & 6 tab dashboard publik (SQL, filter, layout) |
| [`docs/runbook.md`](./docs/runbook.md) | Runbook operasional: arsitektur, restart, rotasi secret, backup, insiden |
| [`docs/landing-snapshot.md`](./docs/landing-snapshot.md) | Review, apply, dan verifikasi snapshot statistik landing |
| [`docs/security-checklist.md`](./docs/security-checklist.md) | Checklist keamanan Fase 5 & cara verifikasi tiap item |
| [`docs/metabase-ids.md`](./docs/metabase-ids.md) | ID dashboard, konfigurasi embedding, prosedur rotasi secret |
| [`docs/data-dictionary.md`](./docs/data-dictionary.md) | Arti tabel & kolom `backbone_client` |
| [`docs/migration-datamart.md`](./docs/migration-datamart.md) | prosedur cutover ke `datamart` saat terisi |
| [`docs/read-only-test.md`](./docs/read-only-test.md) | Hasil uji akses read-only `analis` |
| [`docs/uat.md`](./docs/uat.md) | Panduan QA/UAT: skenario otomatis & manual |
| [`deploy/backup/README.md`](./deploy/backup/README.md) | Cara pakai skrip backup & restore drill |

## Kontribusi & Lisensi

Kontribusi lewat pull request. Baca [`MasterPlan.md`](./MasterPlan.md) lebih dulu untuk memahami konteks fase dan aturan main yang berlaku (Laravel tidak boleh menyentuh `backbone_client`, tidak ada hardcode secret, setiap perubahan logika akses perlu test). Satu fase = satu commit besar dengan pesan jelas.

**Lisensi belum ditetapkan** — menunggu konfirmasi owner.

## Kontak & Eskalasi

Untuk insiden, permintaan akses VIP, atau rotasi secret, lihat **`docs/runbook.md`** §11 (kontak owner, sysadmin server, Pusdatin). Kolom kontak pada runbook harus dilengkapi owner sebelum UAT.
