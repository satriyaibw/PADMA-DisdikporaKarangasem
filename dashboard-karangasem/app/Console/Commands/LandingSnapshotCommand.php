<?php

namespace App\Console\Commands;

use App\Services\LandingSnapshotService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

class LandingSnapshotCommand extends Command
{
    protected $signature = 'landing:snapshot
        {action=review : review, apply, atau verify}
        {--input= : Path JSON hasil query (default: storage/app/landing/review.json)}
        {--approve= : Hash dari aksi review; wajib untuk apply}
        {--strict : Gagalkan verify bila snapshot melewati batas umur}
        {--max-age= : Batas umur snapshot dalam hari (override config)}';

    protected $description = 'Review, terapkan, atau verifikasi snapshot statistik landing';

    public function handle(LandingSnapshotService $snapshots): int
    {
        if ($this->option('max-age') !== null) {
            config(['landing_snapshot.max_age_days' => (int) $this->option('max-age')]);
        }

        return match ((string) $this->argument('action')) {
            'review' => $this->review($snapshots),
            'apply' => $this->apply($snapshots),
            'verify' => $this->verify($snapshots),
            default => $this->invalidAction(),
        };
    }

    protected function review(LandingSnapshotService $snapshots): int
    {
        try {
            $snapshot = $this->readInput($snapshots);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $errors = $snapshots->validate($snapshot);

        if ($errors !== []) {
            $this->error('Snapshot tidak valid:');
            foreach ($errors as $error) {
                $this->line('  - '.$error);
            }

            return self::FAILURE;
        }

        $summary = $snapshots->summary($snapshot);
        $this->renderSummary($summary);

        $artifact = $this->writeReviewArtifact($summary);

        $this->newLine();
        $this->info('Review artifact: '.$this->displayPath($artifact));
        $this->line('Query hash    : '.$summary['hash']);
        $this->line('Belum ada snapshot yang ditulis. Setelah review disetujui, jalankan:');
        $this->line('  landing:snapshot apply --input='.$this->displayPath($this->resolveInputPath($this->option('input'))).' --approve='.$summary['hash']);

        return self::SUCCESS;
    }

    protected function apply(LandingSnapshotService $snapshots): int
    {
        $approval = trim((string) $this->option('approve'));

        if ($approval === '') {
            $this->error('Opsi --approve=<hash> wajib diisi. Jalankan review terlebih dahulu.');

            return self::FAILURE;
        }

        try {
            $snapshot = $this->readInput($snapshots);
            $applied = $snapshots->apply($snapshot, $approval);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Snapshot landing ditulis ke '.$this->displayPath($snapshots->path()));
        $this->line('Hash review : '.$applied['review']['hash']);
        $this->line('Applied at  : '.$applied['review']['applied_at']);

        if ($snapshots->isStale($applied)) {
            $this->warn(
                'Snapshot sudah melewati batas umur '
                .config('landing_snapshot.max_age_days').' hari. '
                .'Jalankan ulang pipeline setelah sinkronisasi Pusdatin.'
            );
        }

        return self::SUCCESS;
    }

    protected function verify(LandingSnapshotService $snapshots): int
    {
        try {
            $snapshot = $snapshots->loadFile($snapshots->path());
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $errors = $snapshots->validate($snapshot);

        if ($errors !== []) {
            $this->error('Snapshot tidak valid:');
            foreach ($errors as $error) {
                $this->line('  - '.$error);
            }

            return self::FAILURE;
        }

        $recordedHash = (string) ($snapshot['review']['hash'] ?? '');
        $actualHash = $snapshots->hash($snapshot);

        if ($recordedHash === '' || ! hash_equals($recordedHash, $actualHash)) {
            $this->error('Hash review tidak cocok. Snapshot mungkin diubah setelah approval.');

            return self::FAILURE;
        }

        $summary = $snapshots->summary($snapshot);
        $this->renderSummary($summary);

        $this->newLine();
        $this->info('Snapshot valid dan hash review cocok.');
        $this->line('Path : '.$this->displayPath($snapshots->path()));

        if ($snapshots->isStale($snapshot)) {
            $message = 'Snapshot berumur '.$snapshots->staleDays($snapshot).' hari, '
                .'melebihi batas '.config('landing_snapshot.max_age_days').' hari.';

            if ($this->option('strict')) {
                $this->error($message);

                return self::FAILURE;
            }

            $this->warn($message);
        } else {
            $this->line('Umur snapshot masih dalam batas.');
        }

        return self::SUCCESS;
    }

    protected function invalidAction(): int
    {
        $this->error('Aksi harus review, apply, atau verify.');

        return self::FAILURE;
    }

    /**
     * @return array<string, mixed>
     */
    protected function readInput(LandingSnapshotService $snapshots): array
    {
        return $snapshots->loadFile($this->resolveInputPath($this->option('input')));
    }

    protected function resolveInputPath(?string $path): string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return $this->laravel->storagePath('app/landing/review.json');
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        return base_path($path);
    }

    /**
     * Tampilkan path relatif terhadap root aplikasi agar output command tidak
     * membocorkan path container kepada operator yang menjalankannya dari host.
     */
    protected function displayPath(string $path): string
    {
        $base = rtrim(base_path(), '/').'/';

        return str_starts_with($path, $base)
            ? substr($path, strlen($base))
            : $path;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    protected function renderSummary(array $summary): void
    {
        $cards = $summary['cards'];
        $scope = $summary['scope'];

        $this->line('Periode : '.($summary['period'] ?? '-'));
        $this->line('Sumber  : '.($summary['updated_at'] ?? '-'));
        $this->line('Wilayah : '.($scope['region'] ?? '-').' ('.($scope['region_prefix'] ?? '-').')');
        $this->line('Sekolah : '.(($scope['school_active_only'] ?? false) ? 'aktif saja' : 'semua'));
        $this->line('Rombel  : '.(($scope['rombel_active_only'] ?? false) ? 'aktif saja' : 'semua')
            .' (jenis '.($scope['rombel_type'] ?? '-').')');
        $this->line('PD      : '.($scope['student_identity'] ?? '-').' pada '.($scope['student_scope'] ?? '-'));
        $this->line('PTK     : '.($scope['ptk_assignment'] ?? '-').', '.($scope['ptk_active_rule'] ?? '-')
            .' (TMT '.($scope['ptk_tmt_from'] ?? '-').' s.d. '.($scope['ptk_tmt_to'] ?? '-').')');
        $this->line('Bentuk  : '.collect($scope['forms'] ?? [])->pluck('name')->implode(', '));
        $this->newLine();

        $this->table(
            ['Kartu', 'Nilai'],
            [
                ['Satuan pendidikan aktif', number_format((int) $cards['active_schools'], 0, ',', '.')],
                ['Peserta didik unik (NISN, Kelas)', number_format((int) $cards['students'], 0, ',', '.')],
                ['Rombongan belajar (Kelas)', number_format((int) $cards['rombels'], 0, ',', '.')],
                ['PTK aktif (induk)', number_format((int) $cards['ptk'], 0, ',', '.')],
            ]
        );

        $this->table(
            ['Kecamatan', 'Sekolah', 'Rombel', 'Peserta didik', 'PTK'],
            collect($summary['districts'])->map(fn (array $district): array => [
                $district['name'],
                number_format((int) $district['schools'], 0, ',', '.'),
                number_format((int) $district['rombel'], 0, ',', '.'),
                number_format((int) $district['students'], 0, ',', '.'),
                number_format((int) ($district['ptk'] ?? 0), 0, ',', '.'),
            ])->all()
        );

        $comparisons = $summary['comparisons'] ?? [];

        if ($comparisons !== []) {
            $this->table(['Pembanding', 'Nilai'], $this->comparisonRows($comparisons));
        }
    }

    /**
     * @param  array<string, mixed>  $comparisons
     * @return array<int, array{0: string, 1: string}>
     */
    protected function comparisonRows(array $comparisons): array
    {
        $rows = [
            'Semua sekolah' => $comparisons['all_schools'] ?? null,
            'Sekolah aktif' => $comparisons['active_schools'] ?? null,
            'Sekolah dengan rombel' => $comparisons['schools_with_rombels'] ?? null,
            'Semua jenis rombel' => $comparisons['all_rombels_latest'] ?? null,
            'Rombel Kelas' => $comparisons['class_rombels_latest'] ?? null,
            'Anggota rombel Kelas' => $comparisons['rombel_members'] ?? null,
            'Peserta didik berdasarkan ID' => $comparisons['students_by_id'] ?? null,
            'Peserta didik unik NISN' => $comparisons['unique_students'] ?? null,
            'Jumlah peserta didik per kecamatan' => $comparisons['district_student_sum'] ?? null,
            'NISN kosong' => $comparisons['nisn_blank'] ?? null,
            'NISN tidak valid' => $comparisons['nisn_invalid'] ?? null,
            'NISN ganda (nilai)' => $comparisons['nisn_duplicate_values'] ?? null,
            'PTK induk' => $comparisons['ptk_induk_total'] ?? null,
            'PTK aktif semester' => $comparisons['ptk_active_semester'] ?? null,
            'PTK aktif semester + TMT' => $comparisons['ptk_active_semester_or_tmt'] ?? null,
            'PTK aktif + TMT sejak awal bulan semester' => $comparisons['ptk_active_with_tmt_month'] ?? null,
            'PTK non-induk' => $comparisons['ptk_no_induk_total'] ?? null,
            'PTK non-induk aktif' => $comparisons['ptk_no_induk_active'] ?? null,
        ];

        return collect($rows)
            ->filter(fn ($value): bool => $value !== null)
            ->map(fn ($value, string $key): array => [$key, number_format((int) $value, 0, ',', '.')])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    protected function writeReviewArtifact(array $summary): string
    {
        $directory = storage_path('app/landing');
        File::ensureDirectoryExists($directory, 0775, true);

        $path = $directory.'/review-'.now()->format('Ymd-His').'.md';
        $scope = $summary['scope'];

        $lines = [
            '# Review Snapshot Landing PADMA',
            '',
            '- **Query hash:** `'.$summary['hash'].'`',
            '- **Snapshot dibuat:** '.($summary['generated_at'] ?? '-'),
            '- **Sumber terakhir sinkron:** '.($summary['updated_at'] ?? '-'),
            '- **Periode:** '.($summary['period'] ?? '-'),
            '- **Wilayah:** '.($scope['region'] ?? '-').' (`'.($scope['region_prefix'] ?? '-').'`)',
            '- **Sekolah:** '.(($scope['school_active_only'] ?? false) ? 'aktif saja' : 'semua'),
            '- **Rombel:** '.(($scope['rombel_active_only'] ?? false) ? 'aktif saja' : 'semua')
                .' (jenis '.($scope['rombel_type'] ?? '-').')',
            '- **Peserta didik:** '.($scope['student_identity'] ?? '-')
                .' pada '.($scope['student_scope'] ?? '-'),
            '- **PTK:** '.($scope['ptk_assignment'] ?? '-')
                .', '.($scope['ptk_active_rule'] ?? '-')
                .' (TMT '.($scope['ptk_tmt_from'] ?? '-').' s.d. '.($scope['ptk_tmt_to'] ?? '-').')',
            '- **Bentuk pendidikan:** '.collect($scope['forms'] ?? [])->pluck('name')->implode(', '),
            '',
            '## Kartu',
            '',
            '| Kartu | Nilai |',
            '|---|---:|',
            '| Satuan pendidikan aktif | '.number_format((int) $summary['cards']['active_schools'], 0, ',', '.').' |',
            '| Peserta didik unik (NISN, Kelas) | '.number_format((int) $summary['cards']['students'], 0, ',', '.').' |',
            '| Rombongan belajar (Kelas) | '.number_format((int) $summary['cards']['rombels'], 0, ',', '.').' |',
            '| PTK aktif (induk) | '.number_format((int) $summary['cards']['ptk'], 0, ',', '.').' |',
            '',
            '## Pembanding',
            '',
            '| Pembanding | Nilai |',
            '|---|---:|',
        ];

        foreach ($this->comparisonRows($summary['comparisons'] ?? []) as [$label, $value]) {
            $lines[] = '| '.$label.' | '.$value.' |';
        }

        $lines[] = '';
        $lines[] = '## Kecamatan';
        $lines[] = '';
        $lines[] = '| Kecamatan | Sekolah | Rombel | Peserta didik | PTK |';
        $lines[] = '|---|---:|---:|---:|---:|';

        foreach ($summary['districts'] as $district) {
            $lines[] = '| '.$district['name'].' | '
                .number_format((int) $district['schools'], 0, ',', '.').' | '
                .number_format((int) $district['rombel'], 0, ',', '.').' | '
                .number_format((int) $district['students'], 0, ',', '.').' | '
                .number_format((int) ($district['ptk'] ?? 0), 0, ',', '.').' |';
        }

        $lines[] = '';
        $lines[] = '## Checklist review';
        $lines[] = '';
        $lines[] = '- [ ] Definisi sekolah aktif sudah sesuai kebijakan Dinas.';
        $lines[] = '- [ ] Rombel hanya jenis Kelas (`jenis_rombel=1`) pada semester terpilih.';
        $lines[] = '- [ ] Peserta didik memakai NISN valid 10 digit; NISN kosong, tidak valid, dan ganda sudah diperiksa.';
        $lines[] = '- [ ] PTK memakai penugasan induk, status keluar, dan aturan aktif semester/TMT sejak awal bulan semester.';
        $lines[] = '- [ ] PTK non-induk pada pembanding sudah dipahami dan tidak termasuk kartu.';
        $lines[] = '- [ ] Cakupan bentuk pendidikan (TK/PAUD/SD/SMP/nonformal) sudah disetujui.';
        $lines[] = '- [ ] Jumlah kecamatan tepat 8 dan tidak ada wilayah yang hilang.';
        $lines[] = '- [ ] Angka dibandingkan dengan dashboard/sumber resmi.';
        $lines[] = '';
        $lines[] = '> Snapshot belum ditulis. Setelah checklist disetujui, jalankan `landing:snapshot apply --approve='.$summary['hash'].'`.';

        File::put($path, implode(PHP_EOL, $lines).PHP_EOL);

        return $path;
    }
}
