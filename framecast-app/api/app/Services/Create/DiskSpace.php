<?php

namespace App\Services\Create;

use Illuminate\Support\Facades\{Log, Storage};

/** Point-in-time checks, not a reservation. Check again before each large allocation. */
class DiskSpace
{
    protected function capacity(string $path): array
    {
        return [@disk_free_space($path), @disk_total_space($path)];
    }

    public function inspect(string $path, int $additionalBytes = 0): array
    {
        while (! is_dir($path) && dirname($path) !== $path) $path = dirname($path);
        [$free, $total] = $this->capacity($path);
        if ((int) config('create.disk_min_free_bytes', 2147483648) < 0
            || (float) config('create.disk_min_free_ratio', .05) < 0 || (float) config('create.disk_min_free_ratio', .05) > 1) {
            throw new \RuntimeException('Invalid Create disk reserve configuration.');
        }
        $reserve = max((int) config('create.disk_min_free_bytes', 2147483648),
            (int) ceil(($total ?: 0) * (float) config('create.disk_min_free_ratio', .05)));
        $needed = $reserve + max(0, $additionalBytes);
        return ['ok' => $free !== false && $total !== false && $total > 0 && $free >= $needed,
            'free_bytes' => $free === false ? null : (int) $free, 'required_bytes' => $needed,
            'total_bytes' => $total === false ? null : (int) $total];
    }

    public function requireSpace(string $path, int $additionalBytes = 0): void
    {
        $status = $this->inspect($path, $additionalBytes);
        if ($status['ok']) return;
        Log::warning('create.disk_capacity', $status);
        throw new DiskCapacityException();
    }

    public function admission(): void
    {
        // Reference extraction and image preparation need scratch space as well as persisted files.
        $bytes = (int) config('create.disk_working_bytes', 1073741824);
        $this->requireSpace(Storage::disk('local')->path(''), $bytes);
        $this->requireSpace(sys_get_temp_dir(), $bytes);
    }
}
