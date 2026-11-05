<?php

namespace Tests\Feature;

use App\Services\LandingSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class LandingSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private string $temporaryDirectory;

    private string $snapshotPath;

    private string $inputPath;

    /** @var array<int, string> */
    private array $reviewArtifactsBefore = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = storage_path('app/testing/landing-snapshot-'.uniqid());
        $this->snapshotPath = $this->temporaryDirectory.'/snapshot.json';
        $this->inputPath = $this->temporaryDirectory.'/review.json';
        $this->reviewArtifactsBefore = glob(storage_path('app/landing/review-*.md')) ?: [];

        File::ensureDirectoryExists($this->temporaryDirectory, 0775, true);

        config([
            'landing_snapshot.path' => $this->snapshotPath,
            'landing_snapshot.max_age_days' => 30,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporaryDirectory);

        foreach (array_diff(glob(storage_path('app/landing/review-*.md')) ?: [], $this->reviewArtifactsBefore) as $artifact) {
            File::delete($artifact);
        }

        parent::tearDown();
    }

    public function test_snapshot_validation_and_hash_are_deterministic(): void
    {
        $service = app(LandingSnapshotService::class);
        $snapshot = $this->snapshotData();

        $this->assertSame([], $service->validate($snapshot));
        $this->assertSame($service->hash($snapshot), $service->hash($snapshot));

        $reordered = array_reverse($snapshot, true);
        $this->assertSame($service->hash($snapshot), $service->hash($reordered));

        $snapshot['cards']['students'] = 1;
        $this->assertNotSame($service->hash($this->snapshotData()), $service->hash($snapshot));
    }

    public function test_validation_rejects_missing_or_duplicate_districts(): void
    {
        $service = app(LandingSnapshotService::class);
        $snapshot = $this->snapshotData();

        array_pop($snapshot['districts']);
        $this->assertNotEmpty($service->validate($snapshot));

        $snapshot = $this->snapshotData();
        $snapshot['districts'][1]['name'] = $snapshot['districts'][0]['name'];
        $this->assertNotEmpty($service->validate($snapshot));
    }

    public function test_validation_requires_approved_scope_definitions(): void
    {
        $service = app(LandingSnapshotService::class);

        $mutations = [
            'rombel_type' => 'all',
            'rombel_types' => [2],
            'student_identity' => 'peserta_didik_id',
            'student_scope' => 'all',
            'ptk_assignment' => 'all',
            'ptk_active_rule' => 'semester_only',
        ];

        foreach ($mutations as $key => $value) {
            $snapshot = $this->snapshotData();
            $snapshot['scope'][$key] = $value;

            $this->assertNotEmpty(
                $service->validate($snapshot),
                "scope.{$key} tidak boleh lolos validasi."
            );
        }

        $snapshot = $this->snapshotData();
        $snapshot['sources']['pulled_at'] = 'not-a-date';
        $this->assertNotEmpty($service->validate($snapshot));
    }

    public function test_review_does_not_apply_and_apply_requires_matching_hash(): void
    {
        File::put($this->inputPath, json_encode($this->snapshotData(), JSON_THROW_ON_ERROR));
        $hash = app(LandingSnapshotService::class)->hash($this->snapshotData());

        $this->artisan('landing:snapshot', [
            'action' => 'review',
            '--input' => $this->inputPath,
        ])->assertExitCode(0);

        $this->assertFileDoesNotExist($this->snapshotPath);

        $this->artisan('landing:snapshot', [
            'action' => 'apply',
            '--input' => $this->inputPath,
            '--approve' => 'wrong-hash',
        ])->assertExitCode(1);

        $this->assertFileDoesNotExist($this->snapshotPath);

        $this->artisan('landing:snapshot', [
            'action' => 'apply',
            '--input' => $this->inputPath,
            '--approve' => $hash,
        ])->assertExitCode(0);

        $this->assertFileExists($this->snapshotPath);

        $stored = json_decode(File::get($this->snapshotPath), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($hash, $stored['review']['hash']);

        $this->artisan('landing:snapshot', ['action' => 'verify'])
            ->assertExitCode(0);

        $stored['cards']['students'] = 1;
        File::put($this->snapshotPath, json_encode($stored, JSON_THROW_ON_ERROR));

        $this->artisan('landing:snapshot', ['action' => 'verify'])
            ->assertExitCode(1);
    }

    public function test_landing_reads_the_approved_snapshot_instead_of_static_defaults(): void
    {
        $service = app(LandingSnapshotService::class);
        $snapshot = $this->snapshotData();
        $service->apply($snapshot, $service->hash($snapshot));

        $this->get('/')
            ->assertOk()
            ->assertSee('Satuan Pendidikan Aktif')
            ->assertSee('604')
            ->assertSee('68.983')
            ->assertSee('3.482')
            ->assertSee('4.977')
            ->assertSee('29 Oktober 2026')
            ->assertSee('Semester Ganjil 2026/2027')
            ->assertDontSee('belum tersedia atau belum diverifikasi')
            ->assertDontSee('>843<', false);
    }

    public function test_landing_falls_back_safely_when_snapshot_is_invalid(): void
    {
        File::put($this->snapshotPath, '{not-json');

        $this->get('/')
            ->assertOk()
            ->assertSee('belum tersedia atau belum diverifikasi');
    }

    public function test_period_label_and_staleness(): void
    {
        $service = app(LandingSnapshotService::class);
        $snapshot = $this->snapshotData();

        $this->assertSame('Semester Ganjil 2026/2027', $service->periodLabel($snapshot));

        $snapshot['period']['semester_id'] = '20252';
        $this->assertSame('Semester Genap 2025/2026', $service->periodLabel($snapshot));

        $snapshot = $this->snapshotData();
        $snapshot['review'] = ['applied_at' => now()->subDays(40)->toIso8601String()];
        $this->assertTrue($service->isStale($snapshot));

        $snapshot = $this->snapshotData();
        $snapshot['sources']['last_sync'] = '2020-01-01T00:00:00';
        $snapshot['review'] = ['applied_at' => now()->subDays(5)->toIso8601String()];
        $this->assertFalse($service->isStale($snapshot));
        $this->assertSame('29 Oktober 2026', $service->updatedAtDisplay($snapshot));

        $snapshot = $this->snapshotData();
        unset($snapshot['sources']['pulled_at']);
        $this->assertSame('27 Oktober 2026', $service->updatedAtDisplay($snapshot));
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotData(): array
    {
        return [
            'version' => 1,
            'generated_at' => '2026-10-20T14:34:00',
            'period' => [
                'semester_id' => '20261',
                'tahun_ajaran_id' => 2026,
            ],
            'scope' => [
                'region' => 'Kabupaten Karangasem',
                'region_prefix' => '2208',
                'school_active_only' => true,
                'rombel_active_only' => true,
                'rombel_type' => 'kelas',
                'rombel_types' => [1],
                'student_identity' => 'nisn_valid_10',
                'student_scope' => 'registrasi_aktif',
                'ptk_assignment' => 'induk',
                'ptk_active_rule' => 'qc_vld_ptk_aktif',
                'semester_id' => '20261',
                'forms' => [
                    ['id' => 1, 'name' => 'TK'],
                    ['id' => 5, 'name' => 'SD'],
                    ['id' => 6, 'name' => 'SMP'],
                ],
            ],
            'cards' => [
                'active_schools' => 604,
                'students' => 68983,
                'rombels' => 3482,
                'ptk' => 4977,
            ],
            'districts' => [
                ['code' => '220804', 'name' => 'Karangasem', 'schools' => 115, 'rombel' => 724, 'students' => 15272, 'ptk' => 1138],
                ['code' => '220808', 'name' => 'Kubu', 'schools' => 80, 'rombel' => 527, 'students' => 11450, 'ptk' => 762],
                ['code' => '220805', 'name' => 'Abang', 'schools' => 94, 'rombel' => 539, 'students' => 11041, 'ptk' => 738],
                ['code' => '220803', 'name' => 'Manggis', 'schools' => 77, 'rombel' => 412, 'students' => 7497, 'ptk' => 539],
                ['code' => '220806', 'name' => 'Bebandem', 'schools' => 74, 'rombel' => 377, 'students' => 7040, 'ptk' => 575],
                ['code' => '220801', 'name' => 'Rendang', 'schools' => 60, 'rombel' => 334, 'students' => 6124, 'ptk' => 478],
                ['code' => '220807', 'name' => 'Selat', 'schools' => 60, 'rombel' => 318, 'students' => 6144, 'ptk' => 425],
                ['code' => '220802', 'name' => 'Sidemen', 'schools' => 44, 'rombel' => 251, 'students' => 4444, 'ptk' => 322],
            ],
            'comparisons' => [
                'all_schools' => 843,
                'active_schools' => 604,
                'schools_with_rombels' => 599,
                'all_rombels_latest' => 5098,
                'class_rombels_latest' => 3482,
                'rombel_members' => 64621,
                'students_by_id' => 70381,
                'unique_students' => 68983,
                'district_student_sum' => 69012,
                'nisn_blank' => 1372,
                'nisn_invalid' => 2,
                'nisn_valid_ids' => 69007,
                'nisn_duplicate_values' => 23,
                'nisn_duplicate_extra_ids' => 24,
                'ptk_induk_total' => 4977,
                'ptk_active_semester' => 4033,
                'ptk_active_semester_or_tmt' => 4037,
                'ptk_active_with_tmt_month' => 4042,
                'ptk_qc_active' => 4977,
                'ptk_no_induk_total' => 129,
                'ptk_no_induk_active' => 20,
            ],
            'sources' => [
                'last_sync' => '2026-10-27T23:30:23.777',
                'pulled_at' => '2026-10-29T10:00:54.499081',
                'schools' => 'dbo.sekolah',
                'rombels' => 'dbo.rombongan_belajar',
                'students' => 'dbo.registrasi_peserta_didik + dbo.peserta_didik',
                'ptk' => 'dbo.ptk_terdaftar + qc.vld_ptk',
            ],
        ];
    }
}
