<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Membaca, memvalidasi, dan menulis snapshot statistik landing.
 *
 * Landing sengaja tidak melakukan query database per request. Query agregat
 * dijalankan oleh pipeline setup/review (lihat database/sql/landing_snapshot.sql),
 * lalu hasilnya disimpan sebagai JSON dan dibaca service ini.
 */
class LandingSnapshotService
{
    /**
     * Key kartu yang wajib ada di snapshot.
     *
     * @var array<int, string>
     */
    private const CARD_KEYS = [
        'active_schools',
        'students',
        'rombels',
        'ptk',
    ];

    /**
     * Path snapshot yang sedang aktif.
     */
    public function path(): string
    {
        return (string) config('landing_snapshot.path', storage_path('app/landing/snapshot.json'));
    }

    /**
     * Path default hasil query untuk direview.
     */
    public function reviewPath(): string
    {
        return storage_path('app/landing/review.json');
    }

    /**
     * Muat snapshot aktif. File yang hilang atau tidak valid menghasilkan
     * null agar landing memakai fallback config lama, bukan error 500.
     */
    public function load(?string $path = null): ?array
    {
        $path ??= $this->path();

        if (! File::exists($path)) {
            return null;
        }

        try {
            $snapshot = $this->loadFile($path);
        } catch (Throwable $e) {
            Log::warning('Snapshot landing tidak dapat dibaca.', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $errors = $this->validate($snapshot);

        if ($errors !== []) {
            Log::warning('Snapshot landing tidak valid dan diabaikan.', [
                'path' => $path,
                'errors' => $errors,
            ]);

            return null;
        }

        return $snapshot;
    }

    /**
     * Baca file JSON tanpa fallback. Dipakai command review/apply agar error
     * query atau file rusak tidak diam-diam dianggap snapshot kosong.
     *
     * @return array<string, mixed>
     */
    public function loadFile(string $path): array
    {
        if (! File::exists($path)) {
            throw new InvalidArgumentException("File snapshot tidak ditemukan: {$path}");
        }

        try {
            $decoded = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new InvalidArgumentException(
                "File snapshot bukan JSON yang valid: {$e->getMessage()}",
                previous: $e
            );
        }

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Snapshot harus berupa objek JSON.');
        }

        return $decoded;
    }

    /**
     * Validasi struktur dan nilai minimum snapshot.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<int, string>
     */
    public function validate(array $snapshot): array
    {
        $errors = [];

        if (($snapshot['version'] ?? null) !== 1) {
            $errors[] = 'version harus 1.';
        }

        $cards = $snapshot['cards'] ?? null;

        if (! is_array($cards)) {
            $errors[] = 'cards wajib berupa objek.';
        } else {
            foreach (self::CARD_KEYS as $key) {
                if (! array_key_exists($key, $cards)) {
                    $errors[] = "cards.{$key} wajib ada.";
                } elseif (! is_numeric($cards[$key]) || (float) $cards[$key] < 0) {
                    $errors[] = "cards.{$key} harus berupa angka non-negatif.";
                }
            }
        }

        $districts = $snapshot['districts'] ?? null;

        if (! is_array($districts) || $districts === []) {
            $errors[] = 'districts wajib berisi daftar kecamatan.';
        } else {
            $required = (int) config('landing_snapshot.required_districts', 8);

            if (count($districts) !== $required) {
                $errors[] = "districts harus berisi {$required} kecamatan, ditemukan ".count($districts).'.';
            }

            $names = [];

            foreach ($districts as $index => $district) {
                if (! is_array($district)) {
                    $errors[] = "districts.{$index} harus berupa objek.";

                    continue;
                }

                $name = trim((string) ($district['name'] ?? ''));

                if ($name === '') {
                    $errors[] = "districts.{$index}.name wajib diisi.";
                } elseif (isset($names[$name])) {
                    $errors[] = "districts.{$index}.name duplikat: {$name}.";
                } else {
                    $names[$name] = true;
                }

                foreach (['schools', 'rombel', 'students'] as $key) {
                    if (! array_key_exists($key, $district)) {
                        $errors[] = "districts.{$index}.{$key} wajib ada.";
                    } elseif (! is_numeric($district[$key]) || (float) $district[$key] < 0) {
                        $errors[] = "districts.{$index}.{$key} harus berupa angka non-negatif.";
                    }
                }
            }
        }

        $period = $snapshot['period'] ?? null;

        if (! is_array($period) || ! preg_match('/^\d{5}$/', (string) ($period['semester_id'] ?? ''))) {
            $errors[] = 'period.semester_id harus berupa 5 digit, mis. 20261.';
        }

        $scope = $snapshot['scope'] ?? null;

        if (! is_array($scope)) {
            $errors[] = 'scope wajib berupa objek.';
        } elseif (($scope['school_active_only'] ?? null) !== true) {
            $errors[] = 'scope.school_active_only harus true.';
        } elseif (($scope['rombel_active_only'] ?? null) !== true) {
            $errors[] = 'scope.rombel_active_only harus true.';
        }

        $lastSync = $snapshot['sources']['last_sync'] ?? null;

        if ($lastSync !== null) {
            try {
                CarbonImmutable::parse((string) $lastSync);
            } catch (Throwable) {
                $errors[] = 'sources.last_sync bukan tanggal yang valid.';
            }
        }

        return $errors;
    }

    /**
     * Hash konten snapshot tanpa metadata review. Approval hanya berlaku
     * untuk konten yang benar-benar direview.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function hash(array $snapshot): string
    {
        unset($snapshot['review']);

        return hash('sha256', json_encode(
            $this->canonicalize($snapshot),
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION
            | JSON_THROW_ON_ERROR
        ));
    }

    /**
     * Tulis snapshot hanya jika hash approval cocok.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function apply(array $snapshot, string $approvalHash, ?string $path = null): array
    {
        $errors = $this->validate($snapshot);

        if ($errors !== []) {
            throw new InvalidArgumentException('Snapshot tidak valid: '.implode(' ', $errors));
        }

        $hash = $this->hash($snapshot);

        if (! hash_equals($hash, trim($approvalHash))) {
            throw new InvalidArgumentException(
                'Hash approval tidak cocok. Jalankan ulang review dan gunakan hash terbaru.'
            );
        }

        $snapshot['review'] = [
            'hash' => $hash,
            'reviewed_at' => $snapshot['generated_at'] ?? null,
            'applied_at' => CarbonImmutable::now()->toIso8601String(),
        ];

        $this->write($snapshot, $path);

        return $snapshot;
    }

    /**
     * Tulis file secara atomik agar landing tidak pernah membaca JSON setengah
     * jadi saat command berjalan.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function write(array $snapshot, ?string $path = null): void
    {
        $path ??= $this->path();
        $directory = dirname($path);

        File::ensureDirectoryExists($directory, 0775, true);

        $temporary = $path.'.tmp.'.bin2hex(random_bytes(4));

        File::put($temporary, json_encode(
            $snapshot,
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION
            | JSON_THROW_ON_ERROR
        ).PHP_EOL);

        if (! rename($temporary, $path)) {
            File::delete($temporary);

            throw new InvalidArgumentException("Gagal memindahkan snapshot ke {$path}.");
        }
    }

    /**
     * Kartu statistik untuk landing. Bila snapshot tidak tersedia, fallback
     * ke config lama agar halaman tetap bisa dibuka saat setup awal.
     *
     * @param  array<string, mixed>|null  $snapshot
     * @return array<int, array<string, mixed>>
     */
    public function stats(?array $snapshot = null): array
    {
        $stats = config('landing.stats');

        if ($snapshot === null) {
            return $stats;
        }

        $cards = $snapshot['cards'];

        $overrides = [
            0 => [
                'label' => 'Satuan Pendidikan Aktif',
                'value' => (int) $cards['active_schools'],
                'note' => 'Satuan pendidikan aktif di Kabupaten Karangasem',
            ],
            1 => [
                'label' => 'Peserta Didik',
                'value' => (int) $cards['students'],
                'note' => 'peserta didik unik',
            ],
            2 => [
                'label' => 'Rombongan Belajar',
                'value' => (int) $cards['rombels'],
                'note' => 'rombel aktif pada semester terbaru',
            ],
            3 => [
                'label' => 'Pendidik & Tenaga Kependidikan',
                'value' => (int) $cards['ptk'],
                'note' => 'PTK unik terdaftar',
            ],
        ];

        foreach ($overrides as $index => $override) {
            if (! isset($stats[$index])) {
                continue;
            }

            $stats[$index] = array_merge($stats[$index], $override);
            $stats[$index]['display'] = number_format($override['value'], 0, ',', '.');
        }

        return $stats;
    }

    /**
     * Data kecamatan untuk kartu landing.
     *
     * @param  array<string, mixed>|null  $snapshot
     * @return array<int, array{name: string, schools: int, rombel: int, students: int}>
     */
    public function districts(?array $snapshot = null): array
    {
        if ($snapshot === null) {
            return config('landing.districts');
        }

        return array_map(
            fn (array $district): array => [
                'name' => (string) $district['name'],
                'schools' => (int) $district['schools'],
                'rombel' => (int) $district['rombel'],
                'students' => (int) $district['students'],
            ],
            $snapshot['districts']
        );
    }

    /**
     * Label semester dari snapshot, mis. 20261 -> Semester Ganjil 2026/2027.
     *
     * @param  array<string, mixed>|null  $snapshot
     */
    public function periodLabel(?array $snapshot = null): ?string
    {
        $semester = (string) ($snapshot['period']['semester_id'] ?? '');

        if (preg_match('/^(\d{4})([12])$/', $semester, $matches) !== 1) {
            return null;
        }

        $year = (int) $matches[1];

        return $matches[2] === '1'
            ? "Semester Ganjil {$year}/".($year + 1)
            : "Semester Genap {$year}/".($year + 1);
    }

    /**
     * Tanggal sinkronisasi sumber data atau waktu snapshot dibuat.
     *
     * @param  array<string, mixed>|null  $snapshot
     */
    public function updatedAtIso(?array $snapshot = null): ?string
    {
        $value = $snapshot['sources']['last_sync']
            ?? $snapshot['generated_at']
            ?? null;

        if ($value === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Tanggal yang ditampilkan di landing.
     *
     * @param  array<string, mixed>|null  $snapshot
     */
    public function updatedAtDisplay(?array $snapshot = null): ?string
    {
        $iso = $this->updatedAtIso($snapshot);

        if ($iso === null) {
            return null;
        }

        return CarbonImmutable::parse($iso)
            ->locale('id')
            ->translatedFormat('j F Y');
    }

    /**
     * Apakah snapshot sudah melewati batas umur.
     *
     * @param  array<string, mixed>|null  $snapshot
     */
    public function isStale(?array $snapshot = null): bool
    {
        $days = $this->staleDays($snapshot);
        $maxAge = (int) config('landing_snapshot.max_age_days', 30);

        return $days !== null && $days > $maxAge;
    }

    /**
     * Umur snapshot dalam hari, atau null bila tanggal tidak bisa dibaca.
     *
     * @param  array<string, mixed>|null  $snapshot
     */
    public function staleDays(?array $snapshot = null): ?int
    {
        $iso = $this->updatedAtIso($snapshot);

        if ($iso === null) {
            return null;
        }

        return (int) CarbonImmutable::parse($iso)->diffInDays(CarbonImmutable::now());
    }

    /**
     * Ringkasan untuk command review.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function summary(array $snapshot): array
    {
        return [
            'hash' => $this->hash($snapshot),
            'generated_at' => $snapshot['generated_at'] ?? null,
            'period' => $this->periodLabel($snapshot),
            'updated_at' => $this->updatedAtDisplay($snapshot),
            'cards' => $snapshot['cards'],
            'districts' => $snapshot['districts'],
            'scope' => $snapshot['scope'],
            'comparisons' => $snapshot['comparisons'] ?? [],
            'sources' => $snapshot['sources'] ?? [],
        ];
    }

    /**
     * Urutkan key secara rekursif agar hash tidak bergantung pada urutan JSON.
     */
    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
