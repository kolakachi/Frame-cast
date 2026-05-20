<?php

namespace App\Services;

class SfxLibraryService
{
    /**
     * Return the bundled SFX library manifest.
     * @return array<int, array{id:string, name:string, category:string, duration:float, url:string}>
     */
    public function all(): array
    {
        $path = resource_path('sfx/library.json');
        if (! is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data['sounds'] ?? null) ? $data['sounds'] : [];
    }

    public function find(string $id): ?array
    {
        foreach ($this->all() as $s) {
            if ($s['id'] === $id) return $s;
        }
        return null;
    }

    /**
     * Search the library by query and/or category.
     */
    public function search(?string $query = null, ?string $category = null): array
    {
        $q = $query ? mb_strtolower(trim($query)) : null;
        return array_values(array_filter($this->all(), function (array $s) use ($q, $category): bool {
            if ($category && $s['category'] !== $category) return false;
            if ($q && ! str_contains(mb_strtolower($s['name']), $q) && ! str_contains(mb_strtolower($s['category']), $q)) return false;
            return true;
        }));
    }
}
