-- ============================================================================
-- Snapshot metrik landing page PADMA
--
-- Query ini HANYA membaca (READ ONLY) dan menghasilkan satu objek JSON yang
-- dipakai oleh `php artisan landing:snapshot review|apply`.
--
-- Definis default:
--   * Wilayah          : Kabupaten Karangasem (prefix kode 2208)
--   * Sekolah          : Soft_delete = 0 AND keaktifan = 1
--   * Rombel           : Soft_delete = 0, semester terbaru (atau :semester)
--   * Peserta didik    : distinct anggota rombel pada rombel terpilih
--   * PTK              : distinct PTK pada tahun ajaran terbaru
--   * Kecamatan        : 8 kecamatan di ref.mst_wilayah (level 3)
--
-- Semua pilihan filter sengaja diletakkan di CTE `scoped_schools` dan
-- `scoped_rombels` agar seluruh kartu memakai definisi yang sama.
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
        COALESCE(
            NULLIF(:'semester', ''),
            (SELECT max(semester_id)::text
             FROM dbo.rombongan_belajar
             WHERE "Soft_delete" = 0)
        ) AS semester_id
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
scoped_rombels AS MATERIALIZED (
    SELECT
        r.rombongan_belajar_id,
        r.sekolah_id,
        ss.kode_wilayah,
        ss.kecamatan
    FROM dbo.rombongan_belajar r
    JOIN scoped_schools ss
      ON ss.sekolah_id = r.sekolah_id
    CROSS JOIN params p
    WHERE r."Soft_delete" = 0
      AND r.semester_id = p.semester_id
),
unscoped_rombels AS MATERIALIZED (
    SELECT count(*)::bigint AS total
    FROM dbo.rombongan_belajar r
    JOIN dbo.sekolah s
      ON s.sekolah_id = r.sekolah_id
    JOIN regions rg
      ON rg.kode_wilayah = btrim(substring(s.kode_wilayah FROM 1 FOR 6))
    CROSS JOIN params p
    WHERE r."Soft_delete" = 0
      AND r.semester_id = p.semester_id
),
scoped_students AS MATERIALIZED (
    SELECT DISTINCT
        a.peserta_didik_id,
        rb.kode_wilayah,
        rb.kecamatan
    FROM dbo.anggota_rombel a
    JOIN scoped_rombels rb
      ON rb.rombongan_belajar_id = a.rombongan_belajar_id
    WHERE a."Soft_delete" = 0
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
scoped_ptk AS MATERIALIZED (
    SELECT DISTINCT
        p.ptk_id,
        ss.kode_wilayah,
        ss.kecamatan
    FROM dbo.ptk_terdaftar p
    JOIN scoped_schools ss
      ON ss.sekolah_id = p.sekolah_id
    CROSS JOIN latest_ptk_year y
    WHERE p."Soft_delete" = 0
      AND p.tahun_ajaran_id = y.tahun_ajaran_id
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
        count(DISTINCT peserta_didik_id)::bigint AS students
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
        'students', (SELECT count(DISTINCT peserta_didik_id)::bigint FROM scoped_students),
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
        'all_rombels_latest', (SELECT total FROM unscoped_rombels),
        'scoped_rombels_latest', (SELECT count(*)::bigint FROM scoped_rombels),
        'rombel_members', (SELECT total FROM rombel_members),
        'student_members', (SELECT count(*)::bigint FROM scoped_students),
        'unique_students', (SELECT count(DISTINCT peserta_didik_id)::bigint FROM scoped_students),
        'district_student_sum', (SELECT coalesce(sum(students), 0)::bigint FROM district_counts)
    ),
    'sources', json_build_object(
        'last_sync', (SELECT last_sync FROM source_sync),
        'schools', 'dbo.sekolah',
        'rombels', 'dbo.rombongan_belajar',
        'students', 'dbo.anggota_rombel',
        'ptk', 'dbo.ptk_terdaftar'
    )
) AS snapshot;

COMMIT;
