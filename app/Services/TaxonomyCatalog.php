<?php

namespace App\Services;

use App\Models\Category;
use App\Models\DataSource;
use App\Models\DiseaseSubtype;
use App\Models\Magnification;
use App\Models\Organ;
use App\Models\PatchSize;
use App\Models\Stain;
use Illuminate\Support\Collection;

/**
 * The classification vocabulary, in the shape an outside caller needs it:
 *
 *      Organ  →  Category (clinical group)  →  DiseaseSubtype (nests via parent_id)
 *      + Stain, Magnification, PatchSize, DataSource alongside
 *
 * One place builds it so the API and the public documentation page can never
 * describe two different trees. Everything here is read-only.
 *
 * `resolve()` applies the same rules the admin forms and importers apply when a
 * slide is filed, so a label that passes here is a label the platform accepts.
 */
class TaxonomyCatalog
{
    /**
     * Organ → groups → diseases, nested to full depth.
     *
     * @return array<int, array<string, mixed>>
     */
    public function tree(?int $organId = null, bool $includeInactive = false): array
    {
        $organs = Organ::query()
            ->when(! $includeInactive, fn ($q) => $q->where('is_active', true))
            ->when($organId !== null, fn ($q) => $q->whereKey($organId))
            ->orderBy('name')
            ->get();

        $categories = Category::query()
            ->whereIn('organ_id', $organs->modelKeys())
            ->when(! $includeInactive, fn ($q) => $q->where('is_active', true))
            ->orderBy('label_en')
            ->get()
            ->groupBy('organ_id');

        $diseases = $this->diseasesByParent($organs->modelKeys());

        return $organs->map(fn (Organ $organ) => [
            'id'         => $organ->id,
            'name'       => $organ->name,
            'is_active'  => (bool) $organ->is_active,
            'categories' => ($categories[$organ->id] ?? collect())
                ->map(fn (Category $c) => $this->categoryNode($c, $diseases, $includeInactive))
                ->values()
                ->all(),
        ])->values()->all();
    }

    /**
     * Groups created before organs became mandatory. They cannot be used to
     * file a slide until an organ is assigned, so they are listed apart rather
     * than hidden — a caller seeing a label here needs to know why it fails.
     *
     * @return array<int, array<string, mixed>>
     */
    public function unrootedCategories(): array
    {
        return Category::unrooted()->orderBy('label_en')->get()
            ->map(fn (Category $c) => ['id' => $c->id, 'label' => $c->label_en, 'is_active' => (bool) $c->is_active])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function organs(bool $includeInactive = false): array
    {
        return Organ::query()
            ->when(! $includeInactive, fn ($q) => $q->where('is_active', true))
            ->withCount(['categories', 'diseaseSubtypes'])
            ->orderBy('name')
            ->get()
            ->map(fn (Organ $o) => [
                'id'               => $o->id,
                'name'             => $o->name,
                'is_active'        => (bool) $o->is_active,
                'categories_count' => $o->categories_count,
                'diseases_count'   => $o->disease_subtypes_count,
            ])->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function stains(bool $includeInactive = false): array
    {
        return Stain::query()
            ->when(! $includeInactive, fn ($q) => $q->where('is_active', true))
            ->orderBy('id')
            ->get()
            ->map(fn (Stain $s) => [
                'id'           => $s->id,
                'name'         => $s->name,
                'abbreviation' => $s->abbreviation,
                'type'         => $s->stain_type,
                'type_label'   => $s->getTypeLabel(),
                'marker'       => $s->marker,
                'description'  => $s->description,
                'is_active'    => (bool) $s->is_active,
            ])->all();
    }

    /**
     * The scan- and processing-level vocabularies that sit beside the tree.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function reference(): array
    {
        return [
            'magnifications' => Magnification::orderBy('value')->get()
                ->map(fn (Magnification $m) => [
                    'id' => $m->id, 'label' => $m->label, 'value' => $m->value,
                    'folder_name' => $m->folder_name, 'is_active' => (bool) $m->is_active,
                ])->all(),
            'patch_sizes' => PatchSize::orderBy('size_px')->orderBy('overlap_px')->get()
                ->map(fn (PatchSize $p) => [
                    'id' => $p->id, 'size_px' => $p->size_px, 'overlap_px' => (int) $p->overlap_px,
                    'wsi_level' => $p->wsi_level, 'label' => $p->getDisplayLabel(),
                    'ai_model_id' => $p->ai_model_id, 'is_active' => (bool) $p->is_active,
                ])->all(),
            'data_sources' => DataSource::orderBy('name')->get()
                ->map(fn (DataSource $d) => [
                    'id' => $d->id, 'name' => $d->name, 'full_name' => $d->full_name,
                    'base_url' => $d->base_url, 'is_active' => (bool) $d->is_active,
                ])->all(),
        ];
    }

    /**
     * Turn an outside label — names or ids — into the rows a slide would be
     * filed against, or say exactly why it cannot be filed.
     *
     * @param  array{organ?:mixed, category?:mixed, disease?:mixed, stain?:mixed}  $input
     * @return array{valid:bool, classification:array<string,mixed>, qualified_name:?string, errors:array<int,string>, warnings:array<int,string>}
     */
    public function resolve(array $input): array
    {
        $errors   = [];
        $warnings = [];
        $out      = ['organ' => null, 'category' => null, 'disease' => null, 'stain' => null];

        // ── Organ: required, the root everything else hangs from ──────────
        $organ = $this->find(Organ::query(), $input['organ'] ?? null, ['name']);
        if (blank($input['organ'] ?? null)) {
            $errors[] = 'organ is required.';
        } elseif (! $organ) {
            $errors[] = "No organ matches \"{$input['organ']}\".";
        } else {
            $out['organ'] = ['id' => $organ->id, 'name' => $organ->name];
            if (! $organ->is_active) {
                $warnings[] = "Organ \"{$organ->name}\" is inactive.";
            }
        }

        // ── Category: required, and only looked for inside that organ ─────
        $category = null;
        if (blank($input['category'] ?? null)) {
            $errors[] = 'category is required.';
        } elseif ($organ) {
            $category = $this->find(Category::where('organ_id', $organ->id), $input['category'], ['label_en']);
            if (! $category) {
                $offered = Category::where('organ_id', $organ->id)->orderBy('label_en')->pluck('label_en');
                $errors[] = "Organ \"{$organ->name}\" has no category \"{$input['category']}\""
                    . ($offered->isEmpty() ? ' (it has no categories yet).' : ' — choose one of: ' . $offered->implode(', ') . '.');
            } else {
                $out['category'] = ['id' => $category->id, 'label' => $category->label_en];
                if (! $category->is_active) {
                    $warnings[] = "Category \"{$category->label_en}\" is inactive.";
                }
            }
        }

        // ── Disease: optional, but when given it must be the most specific ─
        if (! blank($input['disease'] ?? null) && $organ) {
            // Names are UNIQUE per organ, so the organ alone identifies the row;
            // the category is then checked rather than used to search.
            $disease = $this->find(DiseaseSubtype::where('organ_id', $organ->id), $input['disease'], ['name']);

            if (! $disease) {
                $errors[] = "Organ \"{$organ->name}\" has no disease \"{$input['disease']}\".";
            } elseif ($category && (int) $disease->category_id !== (int) $category->id) {
                $errors[] = "Disease \"{$disease->name}\" belongs to category \""
                    . ($disease->category?->label_en ?? '#' . $disease->category_id)
                    . "\", not \"{$category->label_en}\".";
            } else {
                $children = $disease->children()->pluck('name');
                $out['disease'] = [
                    'id'        => $disease->id,
                    'name'      => $disease->name,
                    'parent_id' => $disease->parent_id,
                    'depth'     => $disease->depth,
                    'is_leaf'   => $children->isEmpty(),
                    'path'      => array_merge(
                        array_reverse(array_map(fn (DiseaseSubtype $a) => $a->name, $disease->ancestors())),
                        [$disease->name]
                    ),
                ];
                if ($children->isNotEmpty()) {
                    $errors[] = "\"{$disease->name}\" is refined further — pick one of: {$children->implode(', ')}.";
                }
                if (! $disease->is_active) {
                    $warnings[] = "Disease \"{$disease->name}\" is inactive.";
                }
            }
        } elseif ($category && DiseaseSubtype::where('category_id', $category->id)->exists()) {
            $warnings[] = "No disease given, but category \"{$category->label_en}\" defines diseases — the slide would be filed at group level only.";
        }

        // ── Stain: optional; matched by id, name or abbreviation ──────────
        if (! blank($input['stain'] ?? null)) {
            $stain = $this->find(Stain::query(), $input['stain'], ['abbreviation', 'name']);
            if (! $stain) {
                $errors[] = "No stain matches \"{$input['stain']}\".";
            } else {
                $out['stain'] = ['id' => $stain->id, 'name' => $stain->name, 'abbreviation' => $stain->abbreviation];
                if (! $stain->is_active) {
                    $warnings[] = "Stain \"{$stain->name}\" is inactive.";
                }
            }
        }

        $qualified = $out['organ'] ? implode(' › ', array_filter([
            $out['organ']['name'],
            $out['category']['label'] ?? null,
            ...($out['disease']['path'] ?? []),
        ])) : null;

        return [
            'valid'          => $errors === [],
            'classification' => $out,
            'qualified_name' => $qualified,
            'errors'         => $errors,
            'warnings'       => $warnings,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A numeric value is an id; anything else is matched case-insensitively
     * against the given columns, in order.
     */
    private function find($query, mixed $value, array $columns): mixed
    {
        if (blank($value)) {
            return null;
        }

        if (is_int($value) || ctype_digit((string) $value)) {
            return (clone $query)->whereKey((int) $value)->first();
        }

        $needle = mb_strtolower(trim((string) $value));
        foreach ($columns as $column) {
            $hit = (clone $query)->whereRaw("LOWER({$column}) = ?", [$needle])->first();
            if ($hit) {
                return $hit;
            }
        }

        return null;
    }

    /** @return Collection<int|string, Collection<int, DiseaseSubtype>> */
    private function diseasesByParent(array $organIds): Collection
    {
        // Inactive rows are loaded too: they are hidden at render time, but they
        // still count as children for is_leaf, exactly as the leaf rule counts them.
        return DiseaseSubtype::query()
            ->whereIn('organ_id', $organIds)
            ->orderBy('name')
            ->get()
            // Roots are keyed by their group, children by their parent disease,
            // so one query feeds the whole recursive build.
            ->groupBy(fn (DiseaseSubtype $d) => $d->parent_id === null ? 'c' . $d->category_id : 'd' . $d->parent_id);
    }

    private function categoryNode(Category $category, Collection $diseases, bool $includeInactive): array
    {
        return [
            'id'        => $category->id,
            'label'     => $category->label_en,
            'is_active' => (bool) $category->is_active,
            'diseases'  => $this->diseaseNodes($diseases['c' . $category->id] ?? collect(), $diseases, 1, $includeInactive),
        ];
    }

    private function diseaseNodes(Collection $level, Collection $diseases, int $depth, bool $includeInactive): array
    {
        return $level
            ->filter(fn (DiseaseSubtype $d) => $includeInactive || $d->is_active)
            ->map(function (DiseaseSubtype $d) use ($diseases, $depth, $includeInactive) {
                $below = $diseases['d' . $d->id] ?? collect();

                // MAX_DEPTH stops a corrupt parent chain from recursing forever.
                $children = $depth < DiseaseSubtype::MAX_DEPTH
                    ? $this->diseaseNodes($below, $diseases, $depth + 1, $includeInactive)
                    : [];

                return [
                    'id'        => $d->id,
                    'name'      => $d->name,
                    'depth'     => $depth,
                    'is_leaf'   => $below->isEmpty(),
                    'is_active' => (bool) $d->is_active,
                    'children'  => $children,
                ];
            })->values()->all();
    }
}
