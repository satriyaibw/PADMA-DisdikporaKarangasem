# Dashboard Publik Metabase — Card as Code

Direktori ini adalah **sumber kebenaran** dashboard publik Disdikpora Karangasem
(6 tab, 46 kartu analitik, 6 kartu bantu dropdown, 9 parameter). Instance
Metabase adalah *target* yang diturunkan dari sini, bukan sebaliknya.

Tujuannya: siapa pun — termasuk agent/junior dev — bisa membangun ulang persis
dashboard yang sama di Metabase mana pun tanpa menulis SQL manual, tanpa
menebak id field, dan tanpa menyentuh kode PHP.

## Kapan direktori ini dipakai

| Situasi | Perintah |
|---|---|
| Deploy ke mesin/instance Metabase baru | `apply.py` lalu `verify.py` |
| Mengubah kartu/filter/tata letak | edit YAML, `validate.py`, `apply.py`, `verify.py` |
| Curiga instance berubah tanpa lewat repo | `export.py --check` |
| Mengambil kondisi instance terkini jadi YAML | `export.py` |
| Review perubahan sebelum menyentuh instance | `apply.py --dry-run` |

## Isi direktori

```text
scripts/metabase/iac/
├── dashboard.public.yaml     # dashboard, 6 tab, 9 parameter (sumber nilai dropdown)
├── cards/
│   ├── filters.yaml          # 6 kartu bantu pemasok opsi dropdown
│   ├── eksekutif.yaml        # 8 kartu
│   ├── sekolah.yaml          # 8 kartu
│   ├── rombel.yaml           # 7 kartu
│   ├── peserta-didik.yaml    # 8 kartu
│   ├── ats.yaml              # 7 kartu
│   └── sarana-prasarana.yaml # 8 kartu
├── requirements.txt
└── tools/
    ├── mb_client.py          # klien HTTP Metabase (stdlib)
    ├── spec.py               # model + loader + validasi grid
    ├── resolver.py           # nama -> id (database, collection, field, card)
    ├── resolve_cards.py      # identitas kartu (slot tab, nama, SQL)
    ├── desired.py            # konversi spec -> payload API + pembanding
    ├── validate.py           # lint offline (tanpa Metabase)
    ├── export.py             # instance -> YAML (round-trip)
    ├── apply.py              # YAML -> instance (idempoten)
    └── verify.py             # struktur + eksekusi + angka ekspektasi
```

## Prasyarat di mesin target

1. **Metabase** versi `0.55.x` (repo memakai `v0.55.4`) dan sudah bisa dibuka.
2. **Database `backbone_client`** sudah terdaftar di Metabase dengan role
   **read-only** (`analis`). Tool ini tidak membuat koneksi database; ia hanya
   memakai koneksi yang sudah ada.
3. **Akun admin Metabase** (email + password) — dipakai untuk menulis kartu
   dan dashboard.
4. **Python 3.10+** dan `pip`:

   ```bash
   cd scripts/metabase/iac
   python3 -m venv .venv && . .venv/bin/activate
   pip install -r requirements.txt
   ```

5. **Kredensial**. Pilih salah satu:

   ```bash
   export MB_URL=http://localhost:3000
   export MB_EMAIL=admin@contoh.go.id
   export MB_PASSWORD='...'
   ```

   atau tunjuk file `.env` yang berisi `METABASE_ADMIN_EMAIL` &
   `METABASE_ADMIN_PASSWORD` (nama yang sudah dipakai repo ini):

   ```bash
   python3 tools/apply.py --env-file ../../../dashboard-karangasem/.env
   ```

   Tanpa `--env-file`, tool mencoba `.env` di direktori kerja lalu
   `dashboard-karangasem/.env` (bila dijalankan dari dalam repo).

## Cara pakai (5 langkah)

Semua perintah dijalankan dari `scripts/metabase/iac/`.

```bash
# 1. Lint spec — tidak butuh Metabase, aman dijalankan di CI
python3 tools/validate.py

# 2. Lihat rencana perubahan (tidak menulis apa pun)
python3 tools/apply.py --dry-run

# 3. Terapkan (membuat collection/kartu/dashboard bila belum ada)
python3 tools/apply.py

# 4. Verifikasi: struktur, tata letak, 46 dashcard dijalankan, angka dicek
python3 tools/verify.py
```

Keluaran langkah 3 berakhir dengan potongan `.env`:

```text
Tempelkan ke dashboard-karangasem/.env (atau secret manager):
  METABASE_PUBLIC_DASHBOARD_ID=2
  METABASE_PUBLIC_DASHBOARD_UUID=98edc066-3403-4974-8425-16e68c850cb8
```

5. Salin dua nilai itu ke `.env` aplikasi Laravel, lalu:

   ```bash
   cd ../../../dashboard-karangasem
   vendor/bin/sail artisan config:clear
   ```

   `..._UUID` dipakai iframe publik (Metabase hanya menerima UUID, bukan id
   numerik). `..._ID` dipakai `artisan security:scan-pii`.

Keluaran langkah 4 yang benar:

```text
Kueri dijalankan: 46 dashcard + 6 kartu bantu
Struktur: 6 tab, 46 kartu, 9 parameter, public_uuid=<uuid>
OK: instance sesuai spec, semua kartu berjalan dan angka cocok.
```

## Alur kerja saat mengubah dashboard

**Aturan: ubah YAML dulu, bukan Metabase.** Kalau dashboard diubah lewat UI,
perubahan itu akan hilang pada `apply` berikutnya.

```text
edit YAML  ->  validate.py  ->  apply.py --dry-run  ->  apply.py  ->  verify.py
```

Bila terlanjur mengubah lewat UI dan ingin mempertahankannya:

```bash
python3 tools/export.py     # tulis ulang YAML dari instance (periksa diff-nya!)
python3 tools/validate.py
```

`export.py --check` berguna di CI/cron: exit 1 bila YAML dan instance berbeda.

## Anatomi satu kartu

```yaml
- key: Q-EXE-01                      # kunci stabil, dipakai dokumen & log
  name: Total Sekolah Aktif         # judul kartu di Metabase (boleh sama antar tab)
  tab: eksekutif                    # kunci tab di dashboard.public.yaml
  collection: Dashboard Publik — Kartu
  display: scalar                   # scalar | bar | row | pie | table
  description: Jumlah satuan pendidikan dengan ... (tooltip)
  layout: {row: 0, col: 0, w: 4, h: 4}   # grid 24 kolom, `row` kumulatif
  filters:
    - {tag: kecamatan, parameter: p-kecamatan, label: Kecamatan,
       field: {schema: ref, table: mst_wilayah, column: nama}}
  expect: 604                       # scalar: nilai; tabel/grafik: {rows: N}
  sql: |
    SELECT ...
```

- `filters[].tag` **wajib** sama dengan nama tag di SQL (`{{kecamatan}}`).
- `filters[].parameter` **wajib** ada di `dashboard.public.yaml`.
- `filters[].field` menyebut kolom tempat field filter menempel. Metabase
  mengompilasi field filter menjadi `"tabel"."kolom"`, jadi kolom itu harus ada
  di `FROM` kartu dan **tabel tidak boleh diberi alias**.
- `dimension` (id field) **tidak ditulis** di YAML. `apply.py` mencarinya lewat
  `(schema, table, column)` di metadata instance target, sehingga spec ini
  portabel antar instance.

## Aturan yang tidak boleh dilanggar

| Aturan | Akibat bila dilanggar |
|---|---|
| Tanpa id numerik Metabase di YAML | spec tidak portabel; apply di mesin lain salah sasaran |
| static list selalu `[nilai, label]` | dropdown menampilkan kode mentah dan filter gagal (`status_sekolah = 'Negeri'`) |
| `layout` memakai `row` kumulatif & tidak bertumpuk | kartu terakhir menembus footer "Didukung oleh Metabase" |
| Satu tag = satu filter = satu parameter (eksplisit) | mapping salah; `semester_ruang` bisa nyasar ke parameter ATS |
| Role database tetap read-only `analis` | risiko menulis ke data pendidikan |
| Jangan menyentuh `MB_ENCRYPTION_SECRET_KEY` | seluruh kredensial koneksi DB di Metabase rusak |
| Tanpa PII (nama murid/PTK/ATS, NIK, NISN, alamat) | dashboard publik melanggar kebijakan data |

`apply.py` menolak berjalan bila grid bertumpuk. `validate.py` menolak static
list yang urutannya terbalik dan tag yang tidak dideklarasikan.

## Perilaku apply (penting)

- **Idempoten.** Menjalankan dua kali berturut-turut menghasilkan
  `Total perubahan: 0`.
- **Konvergen.** Tab, slot, dan parameter yang ada di instance tetapi tidak ada
  di spec akan **dihapus** (dicetak lebih dulu sebagai `DIHAPUS`). Pakai
  `--keep-extra` bila ingin mempertahankannya.
- **Mencocokkan kartu lewat slot `(tab, nama)`** lebih dulu, lalu nama unik di
  collection, lalu SQL identik. Ini perlu karena dua pasang kartu memang
  bernama sama (Q-EXE-02/Q-ROM-01 dan Q-EXE-03/Q-PD-01).
- **Tidak menimpa `.env`.** Potongan `.env` hanya dicetak.
- **Public link** dibuat sekali (`POST /api/dashboard/:id/public_link`) bila
  `public_link: true` dan belum ada. Setting `enable-public-sharing` Metabase
  harus aktif (default aktif).

## Troubleshooting

| Gejala | Penyebab & tindakan |
|---|---|
| `Database 'backbone_client' belum terdaftar` | tambahkan koneksi DB di Metabase (Admin › Databases) dulu |
| `Kolom dbo.x.y tidak ditemukan` | metadata belum sync — Admin › Databases › Sync, atau perbaiki `field` di YAML |
| `Collection '...' tidak ada` saat `verify` | jalankan `apply.py`; `verify` tidak membuat apa pun |
| `BELUM SESUAI: nilai ..., ekspektasi ...` | data sumber berubah. Pastikan angka baru benar, lalu `export.py` untuk memperbarui `expect` |
| `status ... — Parameter yang diperlukan hilang` | tag dipakai sebagai variabel teks, bukan field filter `dimension` |
| Dropdown berisi nama wilayah luar Karangasem | parameter tidak punya `values.from_card`/`static_list` |
| `HTTP 401` | email/password salah, atau memakai akun non-admin |
| `HTTP 400 ... tab, makes sure every card has a tab` | dashcard tidak punya `dashboard_tab_id` (jangan edit payload manual) |
| `GAGAL: tata letak grid bermasalah` | perbaiki `row`/`col`/`h` di YAML; jangan pakai `--force` |
| Apply terasa lambat | metadata `backbone_client` (154 tabel) di-cache per proses; wajar ±1 menit di instance baru |

## Referensi

- Spesifikasi kartu & angka ekspektasi: [`metabase_questions.md`](../../../metabase_questions.md)
- Id dashboard, parameter, dan inventaris kartu: [`docs/metabase-ids.md`](../../../docs/metabase-ids.md)
- Prosedur operasional: [`docs/runbook.md`](../../../docs/runbook.md)
- Aturan arsitektur: [`AGENTS.md`](../../../AGENTS.md)
