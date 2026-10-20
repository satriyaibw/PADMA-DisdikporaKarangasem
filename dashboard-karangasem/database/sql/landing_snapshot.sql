-- ============================================================================
-- Snapshot metrik landing page PADMA
--
-- Query ini HANYA membaca (READ ONLY) dan menghasilkan satu objek JSON yang
-- dipakai oleh `php artisan landing:snapshot review|apply`.
--
-- Definisi default:
--   * Wilayah          : Kabupaten Karangasem (prefix kode 2208)
--   * Sekolah          : Soft_delete = 0 AND keaktifan = 1
--   * Rombel           : Soft_delete = 0, jenis_rombel = 1 (Kelas), semester
--                        terbaru (atau :semester)
--   * Peserta didik    : NISN unik valid 10 digit pada rombel Kelas terpilih
--   * PTK              : penugasan induk (ptk_induk = 1), tidak keluar, aktif
--                        minimal satu bulan semester atau TMT sejak awal
--                        bulan semester sampai tanggal snapshot
--   * Kecamatan        : 8 kecamatan di ref.mst_wilayah (level 3)
--
-- NISN hanya dibaca untuk deduplikasi. Output query tetap berupa agregat dan
-- tidak memuat NISN, nama, NIK, atau data pribadi lain.
--
-- Jalankan dengan psql:
--   psql -v semester= -At -f database/sql/landing_snapshot.sql
--
-- `:semester` boleh kosong; bila kosong, query memakai semester terbaru.
-- ============================================================================

\set ON_ERROR_STOP on

BEGIN READ ONLY;
SET LOCAL statement_timeout = '120s';
-- Statistik planner pada dump hasil restore belum tentu lengkap. Paksa hash
-- join agar tabel kecil (sekolah/rombel) tidak memicu nested loop ke seluruh
-- anggota rombel.
SET LOCAL enable_nestloop = off;

WITH params AS MATERIALIZED (
    SELECT
        s.semester_id,
        s.semester,
        s.tanggal_mulai,
        (now() AT TIME ZONE 'Asia/Makassar')::date AS snapshot_date
    FROM ref.semester s
    WHERE s.semester_id = COALESCE(
        NULLIF(:'semester', ''),
        (SELECT max(semester_id)::text
         FROM dbo.rombongan_belajar
         WHERE "Soft_delete" = 0)
    )
    ORDER BY s.semester_id DESC
    LIMIT 1
),
regions AS MATERIALIZED (
    SELECT
        btrim(kode_wilayah) AS kode_wilayah,
        regexp_replace(nama, '^Kec\.\s*', '') AS nama
    FROM ref.mst_wilayah
    WHERE id_level_wilayah = 3
      AND kode_wilayah LIKE '2208%'
),
all_schools AS MATERIALIZED (
    SELECT count(*)::bigint AS total
    FROM dbo.sekolah s
    JOIN regions r
      ON r.kode_wilayah = btrim(substring(s.kode_wilayah FROM 1 FOR 6))
),
scoped_schools AS MATERIALIZED (
    SELECT
        s.sekolah_id,
        s.bentuk_pendidikan_id,
        b.nama AS bentuk,
        r.kode_wilayah,
        r.nama AS kecamatan
    FROM dbo.sekolah s
    JOIN regions r
      ON r.kode_wilayah = btrim(substring(s.kode_wilayah FROM 1 FOR 6))
    JOIN ref.bentuk_pendidikan b
      ON b.bentuk_pendidikan_id = s.bentuk_pendidikan_id
    WHERE s."Soft_delete" = 0
      AND s.keaktifan = 1
),
scoped_all_rombels AS MATERIALIZED (
    SELECT
        r.rombongan_belajar_id,
        r.sekolah_id,
        r.jenis_rombel,
        ss.kode_wilayah,
        ss.kecamatan
    FROM dbo.rombongan_belajar r
    JOIN scoped_schools ss
      ON ss.sekolah_id = r.sekolah_id
    CROSS JOIN params p
    WHERE r."Soft_delete" = 0
      AND r.semester_id = p.semester_id
),
scoped_rombels AS MATERIALIZED (
    SELECT *
    FROM scoped_all_rombels
    WHERE jenis_rombel = 1
),
scoped_student_ids AS MATERIALIZED (
    SELECT DISTINCT a.peserta_didik_id
    FROM dbo.anggota_rombel a
    JOIN scoped_rombels rb
      ON rb.rombongan_belajar_id = a.rombongan_belajar_id
    WHERE a."Soft_delete" = 0
),
student_identity AS MATERIALIZED (
    SELECT
        si.peserta_didik_id,
        btrim(pd.nisn) AS nisn
    FROM scoped_student_ids si
    LEFT JOIN dbo.peserta_didik pd
      ON pd.peserta_didik_id = si.peserta_didik_id
),
scoped_students AS MATERIALIZED (
    SELECT DISTINCT
        btrim(pd.nisn) AS nisn,
        rb.kode_wilayah,
        rb.kecamatan
    FROM dbo.anggota_rombel a
    JOIN scoped_rombels rb
      ON rb.rombongan_belajar_id = a.rombongan_belajar_id
    JOIN dbo.peserta_didik pd
      ON pd.peserta_didik_id = a.peserta_didik_id
    WHERE a."Soft_delete" = 0
      AND btrim(pd.nisn) ~ '^[0-9]{10}$'
),
nisn_quality AS MATERIALIZED (
    SELECT
        count(*) FILTER (WHERE nisn IS NULL OR nisn = '') AS blank,
        count(*) FILTER (WHERE nisn IS NOT NULL AND nisn <> '' AND nisn !~ '^[0-9]{10}$') AS invalid,
        count(*) FILTER (WHERE nisn ~ '^[0-9]{10}$') AS valid_ids
    FROM student_identity
),
valid_nisn AS MATERIALIZED (
    SELECT nisn, count(*) AS ids
    FROM student_identity
    WHERE nisn ~ '^[0-9]{10}$'
    GROUP BY nisn
),
nisn_duplicates AS MATERIALIZED (
    SELECT
        count(*) FILTER (WHERE ids > 1) AS duplicate_values,
        coalesce(sum(ids - 1) FILTER (WHERE ids > 1), 0) AS extra_ids
    FROM valid_nisn
),
rombel_members AS MATERIALIZED (
    SELECT count(*)::bigint AS total
    FROM dbo.anggota_rombel a
    JOIN scoped_rombels rb
      ON rb.rombongan_belajar_id = a.rombongan_belajar_id
    WHERE a."Soft_delete" = 0
),
latest_ptk_year AS MATERIALIZED (
    SELECT max(tahun_ajaran_id) AS tahun_ajaran_id
    FROM dbo.ptk_terdaftar
    WHERE "Soft_delete" = 0
),
ptk_all AS MATERIALIZED (
    SELECT
        pt.ptk_id,
        pt.ptk_induk,
        pt.sekolah_id,
        ss.kode_wilayah,
        ss.kecamatan,
        pt.jenis_keluar_id,
        pt.tgl_ptk_keluar,
        (
            CASE
                WHEN p.semester = 1 THEN greatest(
                    coalesce(pt.aktif_bulan_07, 0),
                    coalesce(pt.aktif_bulan_08, 0),
                    coalesce(pt.aktif_bulan_09, 0),
                    coalesce(pt.aktif_bulan_10, 0),
                    coalesce(pt.aktif_bulan_11, 0),
                    coalesce(pt.aktif_bulan_12, 0)
                )
                ELSE greatest(
                    coalesce(pt.aktif_bulan_01, 0),
                    coalesce(pt.aktif_bulan_02, 0),
                    coalesce(pt.aktif_bulan_03, 0),
                    coalesce(pt.aktif_bulan_04, 0),
                    coalesce(pt.aktif_bulan_05, 0),
                    coalesce(pt.aktif_bulan_06, 0)
                )
            END = 1
        ) AS active_semester,
        (
            coalesce(pt.tmt_tugas, pt.tanggal_surat_tugas)
                BETWEEN p.tanggal_mulai AND p.snapshot_date
        ) AS tmt_semester,
        -- TMT baru sejak awal bulan semester sampai snapshot ikut dihitung.
        (
            coalesce(pt.tmt_tugas, pt.tanggal_surat_tugas)
                BETWEEN date_trunc('month', p.tanggal_mulai)::date AND p.snapshot_date
        ) AS tmt_semester_month
    FROM dbo.ptk_terdaftar pt
    JOIN scoped_schools ss
      ON ss.sekolah_id = pt.sekolah_id
    CROSS JOIN params p
    CROSS JOIN latest_ptk_year y
    WHERE pt."Soft_delete" = 0
      AND pt.tahun_ajaran_id = y.tahun_ajaran_id
),
scoped_ptk_base AS MATERIALIZED (
    SELECT *
    FROM ptk_all
    WHERE ptk_induk = 1
),
scoped_ptk AS MATERIALIZED (
    SELECT DISTINCT
        ptk_id,
        kode_wilayah,
        kecamatan
    FROM scoped_ptk_base
    WHERE jenis_keluar_id IS NULL
      AND tgl_ptk_keluar IS NULL
      AND (active_semester OR tmt_semester_month)
),
ptk_quality AS MATERIALIZED (
    SELECT
        count(DISTINCT ptk_id) AS induk_total,
        count(DISTINCT ptk_id) FILTER (
            WHERE jenis_keluar_id IS NULL
              AND tgl_ptk_keluar IS NULL
              AND active_semester
        ) AS active_semester,
        count(DISTINCT ptk_id) FILTER (
            WHERE jenis_keluar_id IS NULL
              AND tgl_ptk_keluar IS NULL
              AND (active_semester OR tmt_semester)
        ) AS active_semester_or_tmt,
        count(DISTINCT ptk_id) FILTER (
            WHERE jenis_keluar_id IS NULL
              AND tgl_ptk_keluar IS NULL
              AND (active_semester OR tmt_semester_month)
        ) AS active_with_tmt_month
    FROM scoped_ptk_base
),
ptk_no_induk AS MATERIALIZED (
    SELECT
        p.ptk_id,
        bool_or(
            p.jenis_keluar_id IS NULL
            AND p.tgl_ptk_keluar IS NULL
            AND p.active_semester
        ) AS active_semester,
        bool_or(
            p.jenis_keluar_id IS NULL
            AND p.tgl_ptk_keluar IS NULL
            AND (p.active_semester OR p.tmt_semester_month)
        ) AS active_with_tmt_month
    FROM ptk_all p
    WHERE NOT EXISTS (
        SELECT 1
        FROM ptk_all i
        WHERE i.ptk_id = p.ptk_id
          AND i.ptk_induk = 1
    )
    GROUP BY p.ptk_id
),
ptk_no_induk_quality AS MATERIALIZED (
    SELECT
        count(*) AS total,
        count(*) FILTER (WHERE active_semester) AS active_semester,
        count(*) FILTER (WHERE active_with_tmt_month) AS active_with_tmt_month
    FROM ptk_no_induk
),
source_sync AS MATERIALIZED (
    SELECT greatest(
        (SELECT max(last_sync) FROM dbo.sekolah),
        (SELECT max(last_sync) FROM dbo.rombongan_belajar),
        (SELECT max(last_sync) FROM dbo.ptk_terdaftar)
    ) AS last_sync
),
district_schools AS MATERIALIZED (
    SELECT
        kode_wilayah,
        count(*)::bigint AS schools
    FROM scoped_schools
    GROUP BY kode_wilayah
),
district_rombels AS MATERIALIZED (
    SELECT
        kode_wilayah,
        count(*)::bigint AS rombels
    FROM scoped_rombels
    GROUP BY kode_wilayah
),
district_students AS MATERIALIZED (
    SELECT
        kode_wilayah,
        count(DISTINCT nisn)::bigint AS students
    FROM scoped_students
    GROUP BY kode_wilayah
),
district_ptk AS MATERIALIZED (
    SELECT
        kode_wilayah,
        count(DISTINCT ptk_id)::bigint AS ptk
    FROM scoped_ptk
    GROUP BY kode_wilayah
),
district_counts AS MATERIALIZED (
    SELECT
        r.kode_wilayah,
        r.nama,
        coalesce(ds.schools, 0)::bigint AS schools,
        coalesce(dr.rombels, 0)::bigint AS rombels,
        coalesce(dst.students, 0)::bigint AS students,
        coalesce(dp.ptk, 0)::bigint AS ptk
    FROM regions r
    LEFT JOIN district_schools ds
      ON ds.kode_wilayah = r.kode_wilayah
    LEFT JOIN district_rombels dr
      ON dr.kode_wilayah = r.kode_wilayah
    LEFT JOIN district_students dst
      ON dst.kode_wilayah = r.kode_wilayah
    LEFT JOIN district_ptk dp
      ON dp.kode_wilayah = r.kode_wilayah
)
SELECT json_build_object(
    'version', 1,
    'generated_at', to_char(
        now() AT TIME ZONE 'Asia/Makassar',
        'YYYY-MM-DD"T"HH24:MI:SS'
    ),
    'period', json_build_object(
        'semester_id', (SELECT semester_id FROM params),
        'tahun_ajaran_id', (SELECT tahun_ajaran_id FROM latest_ptk_year)
    ),
    'scope', json_build_object(
        'region', 'Kabupaten Karangasem',
        'region_prefix', '2208',
        'school_active_only', true,
        'rombel_active_only', true,
        'rombel_type', 'kelas',
        'rombel_types', json_build_array(1),
        'student_identity', 'nisn_valid_10',
        'student_scope', 'kelas',
        'ptk_assignment', 'induk',
        'ptk_active_rule', 'semester_or_tmt_since_month_start',
        'ptk_tmt_from', (
            SELECT to_char(date_trunc('month', tanggal_mulai)::date, 'YYYY-MM-DD')
            FROM params
        ),
        'ptk_tmt_to', (SELECT to_char(snapshot_date, 'YYYY-MM-DD') FROM params),
        'semester_id', (SELECT semester_id FROM params),
        'forms', (
            SELECT json_agg(
                json_build_object(
                    'id', f.bentuk_pendidikan_id,
                    'name', f.bentuk
                )
                ORDER BY f.bentuk
            )
            FROM (
                SELECT DISTINCT bentuk_pendidikan_id, bentuk
                FROM scoped_schools
            ) f
        )
    ),
    'cards', json_build_object(
        'active_schools', (SELECT count(*)::bigint FROM scoped_schools),
        'students', (SELECT count(DISTINCT nisn)::bigint FROM scoped_students),
        'rombels', (SELECT count(*)::bigint FROM scoped_rombels),
        'ptk', (SELECT count(DISTINCT ptk_id)::bigint FROM scoped_ptk)
    ),
    'districts', (
        SELECT json_agg(
            json_build_object(
                'code', d.kode_wilayah,
                'name', d.nama,
                'schools', d.schools,
                'rombel', d.rombels,
                'students', d.students,
                'ptk', d.ptk
            )
            ORDER BY d.students DESC, d.nama
        )
        FROM district_counts d
    ),
    'comparisons', json_build_object(
        'all_schools', (SELECT total FROM all_schools),
        'active_schools', (SELECT count(*)::bigint FROM scoped_schools),
        'schools_with_rombels', (SELECT count(DISTINCT sekolah_id)::bigint FROM scoped_rombels),
        'all_rombels_latest', (SELECT count(*)::bigint FROM scoped_all_rombels),
        'class_rombels_latest', (SELECT count(*)::bigint FROM scoped_rombels),
        'rombel_members', (SELECT total FROM rombel_members),
        'students_by_id', (SELECT count(*)::bigint FROM student_identity),
        'unique_students', (SELECT count(DISTINCT nisn)::bigint FROM scoped_students),
        'district_student_sum', (SELECT coalesce(sum(students), 0)::bigint FROM district_counts),
        'nisn_blank', (SELECT blank FROM nisn_quality),
        'nisn_invalid', (SELECT invalid FROM nisn_quality),
        'nisn_valid_ids', (SELECT valid_ids FROM nisn_quality),
        'nisn_duplicate_values', (SELECT duplicate_values FROM nisn_duplicates),
        'nisn_duplicate_extra_ids', (SELECT extra_ids FROM nisn_duplicates),
        'ptk_induk_total', (SELECT induk_total FROM ptk_quality),
        'ptk_active_semester', (SELECT active_semester FROM ptk_quality),
        'ptk_active_semester_or_tmt', (SELECT active_semester_or_tmt FROM ptk_quality),
        'ptk_active_with_tmt_month', (SELECT active_with_tmt_month FROM ptk_quality),
        'ptk_no_induk_total', (SELECT total FROM ptk_no_induk_quality),
        'ptk_no_induk_active', (SELECT active_with_tmt_month FROM ptk_no_induk_quality)
    ),
    'sources', json_build_object(
        'last_sync', (SELECT last_sync FROM source_sync),
        'schools', 'dbo.sekolah',
        'rombels', 'dbo.rombongan_belajar',
        'students', 'dbo.anggota_rombel + dbo.peserta_didik',
        'ptk', 'dbo.ptk_terdaftar'
    )
) AS snapshot;

COMMIT;
