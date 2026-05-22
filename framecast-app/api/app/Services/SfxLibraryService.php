<?php

namespace App\Services;

use App\Models\SfxLibrarySound;
use Illuminate\Database\Eloquent\Collection;

class SfxLibraryService
{
    /**
     * Get the active library — sounds an end user can browse.
     * @return Collection<int, SfxLibrarySound>
     */
    public function all(): Collection
    {
        return SfxLibrarySound::query()
            ->where('status', 'active')
            ->orderBy('category')
            ->orderBy('name')
            ->get();
    }

    public function find(int $id): ?SfxLibrarySound
    {
        return SfxLibrarySound::query()->find($id);
    }

    public function search(?string $query = null, ?string $category = null): Collection
    {
        $q = $query ? mb_strtolower(trim($query)) : null;

        return SfxLibrarySound::query()
            ->where('status', 'active')
            ->when($category, fn ($b) => $b->where('category', $category))
            ->when($q, fn ($b) => $b->where(function ($w) use ($q) {
                $w->whereRaw('LOWER(name) LIKE ?', ['%'.$q.'%'])
                  ->orWhereRaw('LOWER(COALESCE(category, \'\')) LIKE ?', ['%'.$q.'%']);
            }))
            ->orderBy('category')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return list<array{key:string, label:string}>
     */
    public function categories(): array
    {
        return [
            ['key' => 'transition',   'label' => 'Transitions'],
            ['key' => 'ui',           'label' => 'UI / Clicks'],
            ['key' => 'notification', 'label' => 'Notifications'],
            ['key' => 'impact',       'label' => 'Impacts'],
            ['key' => 'ambient',      'label' => 'Ambient'],
            ['key' => 'fx',           'label' => 'FX'],
            ['key' => 'music',        'label' => 'Music / Stingers'],
        ];
    }

    public function serialize(SfxLibrarySound $sound): array
    {
        return [
            'id'               => $sound->getKey(),
            'name'             => $sound->name,
            'category'         => $sound->category,
            'storage_url'      => $sound->storage_url,
            'duration_seconds' => $sound->duration_seconds,
            'file_size_bytes'  => $sound->file_size_bytes,
            'source'           => $sound->source,
            'status'           => $sound->status,
            'created_at'       => $sound->created_at?->toIso8601String(),
        ];
    }
}
