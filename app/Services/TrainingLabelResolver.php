<?php

namespace App\Services;

use App\Models\Sample;
use Illuminate\Support\Collection;

/**
 * Single source of truth for turning a Sample into numeric training labels.
 *
 * Three label sources are supported:
 *
 *   category        — coarse organ-level class (Normal / Tumor / …)     [flat]
 *   disease_type    — free-text diagnosis carried on the patient case   [flat]
 *   disease_subtype — the exact disease name (leaf of the taxonomy)     [HIERARCHICAL]
 *
 * For `disease_subtype` the resolver also emits the coarse parent level
 * (the subtype's own Category), producing a two-level label inside one organ:
 *
 *      organ  = run scope, never a class  ("Breast")   ← filtered by the caller
 *      parent = index of its Category     ("Malignant")
 *      fine   = index of DiseaseSubtype   ("Invasive ductal carcinoma")
 *
 * The organ deliberately does not become a class: it arrives with the specimen,
 * so a run is pinned to one organ and the network spends its capacity on the
 * two levels it actually has to infer.
 *
 * Identity is always keyed on the primary key (never the display string), so
 * two subtypes that happen to share a name under different categories stay
 * distinct classes, and renaming a subtype never silently merges classes.
 */
class TrainingLabelResolver
{
    public const TYPE_CATEGORY        = 'category';
    public const TYPE_DISEASE_TYPE    = 'disease_type';
    public const TYPE_DISEASE_SUBTYPE = 'disease_subtype';

    public const TYPES = [
        self::TYPE_CATEGORY,
        self::TYPE_DISEASE_TYPE,
        self::TYPE_DISEASE_SUBTYPE,
    ];

    /** Label sources that carry a parent level. */
    public static function isHierarchicalType(string $labelType): bool
    {
        return $labelType === self::TYPE_DISEASE_SUBTYPE;
    }

    /** Eager-load hints required to resolve a given label type without N+1. */
    public static function relationsFor(string $labelType): array
    {
        return match ($labelType) {
            self::TYPE_DISEASE_SUBTYPE => ['organ', 'diseaseSubtype.category', 'diseaseSubtype.organ'],
            self::TYPE_DISEASE_TYPE    => ['organ', 'patientCase'],
            default                    => ['organ', 'category'],
        };
    }

    /**
     * Distinct organ ids spanned by a sample set.
     *
     * A fine-grained run must not straddle organs: the same clinical group name
     * ("Malignant") means a different morphology in every organ, so mixing them
     * collapses two entities into one class. The dispatcher uses this to pin the
     * run to a single organ.
     *
     * @param  Collection<int, Sample>  $samples
     * @return array<int, int>
     */
    public static function organIdsIn(Collection $samples): array
    {
        return $samples->pluck('organ_id')->filter()->unique()->values()->all();
    }

    /** Sample ids that carry no organ at all — they cannot be scoped to a run. */
    public static function samplesWithoutOrgan(Collection $samples): array
    {
        return $samples->whereNull('organ_id')->pluck('id')->all();
    }

    /**
     * Stable identity of one sample's fine class.
     *
     * @return array{0: ?string, 1: ?string} [fineKey, parentKey] — null when unlabelled.
     */
    public static function keysFor(Sample $sample, string $labelType): array
    {
        switch ($labelType) {
            case self::TYPE_DISEASE_SUBTYPE:
                $st = $sample->diseaseSubtype;
                if (! $st) {
                    return [null, null];
                }
                $parentKey = $st->category_id !== null ? 'cat:' . $st->category_id : null;
                return ['st:' . $st->id, $parentKey];

            case self::TYPE_DISEASE_TYPE:
                $dt = trim((string) ($sample->patientCase?->disease_type ?? ''));
                return [$dt === '' ? null : 'dt:' . mb_strtolower($dt), null];

            case self::TYPE_CATEGORY:
            default:
                return [$sample->category_id !== null ? 'cat:' . $sample->category_id : null, null];
        }
    }

    /** Human-readable display names for a sample's fine / parent class. */
    public static function displayNamesFor(Sample $sample, string $labelType): array
    {
        switch ($labelType) {
            case self::TYPE_DISEASE_SUBTYPE:
                $st = $sample->diseaseSubtype;
                return [
                    $st?->name,
                    $st?->category?->label_en,
                ];

            case self::TYPE_DISEASE_TYPE:
                $dt = trim((string) ($sample->patientCase?->disease_type ?? ''));
                return [$dt === '' ? null : $dt, null];

            case self::TYPE_CATEGORY:
            default:
                return [$sample->category?->label_en, null];
        }
    }

    /**
     * Derive the complete label specification from the samples that will actually
     * be trained on. Classes are ordered deterministically (by display name) so
     * the same sample set always yields the same class indices.
     *
     * @param  Collection<int, Sample>  $samples
     * @return array{
     *   label_map: array<int,string>,
     *   parent_label_map: array<int,string>,
     *   child_to_parent: array<int,int>,
     *   n_classes: int,
     *   n_parent_classes: int,
     *   sample_labels: array<int, array{label:int, parent_label:?int}>,
     *   unlabelled: array<int,int>,
     *   class_counts: array<int,int>
     * }
     */
    public static function buildSpec(Collection $samples, string $labelType): array
    {
        $fine   = [];   // fineKey   => display
        $parent = [];   // parentKey => display
        $link   = [];   // fineKey   => parentKey

        $unlabelled = [];

        foreach ($samples as $sample) {
            [$fineKey, $parentKey] = self::keysFor($sample, $labelType);
            [$fineName, $parentName] = self::displayNamesFor($sample, $labelType);

            if ($fineKey === null) {
                $unlabelled[] = $sample->id;
                continue;
            }

            $fine[$fineKey] = $fineName ?: $fineKey;

            if ($parentKey !== null) {
                $parent[$parentKey] = $parentName ?: $parentKey;
                $link[$fineKey]     = $parentKey;
            }
        }

        // Deterministic ordering — display name, then key as tie-breaker.
        uksort($fine, fn($a, $b) => [$fine[$a], $a] <=> [$fine[$b], $b]);
        uksort($parent, fn($a, $b) => [$parent[$a], $a] <=> [$parent[$b], $b]);

        $fineIndex   = array_flip(array_keys($fine));    // fineKey   => idx
        $parentIndex = array_flip(array_keys($parent));  // parentKey => idx

        $labelMap       = array_values($fine);
        $parentLabelMap = array_values($parent);

        $childToParent = [];
        foreach ($link as $fineKey => $parentKey) {
            if (isset($fineIndex[$fineKey], $parentIndex[$parentKey])) {
                $childToParent[$fineIndex[$fineKey]] = $parentIndex[$parentKey];
            }
        }
        ksort($childToParent);

        // Per-sample resolution + class histogram
        $sampleLabels = [];
        $classCounts  = array_fill(0, count($labelMap), 0);

        foreach ($samples as $sample) {
            [$fineKey, $parentKey] = self::keysFor($sample, $labelType);
            if ($fineKey === null || ! isset($fineIndex[$fineKey])) {
                continue;
            }
            $idx = $fineIndex[$fineKey];
            $sampleLabels[$sample->id] = [
                'label'        => $idx,
                'parent_label' => ($parentKey !== null && isset($parentIndex[$parentKey]))
                    ? $parentIndex[$parentKey]
                    : null,
            ];
            $classCounts[$idx]++;
        }

        return [
            'label_map'        => $labelMap,
            'parent_label_map' => $parentLabelMap,
            'child_to_parent'  => $childToParent,
            'n_classes'        => count($labelMap),
            'n_parent_classes' => count($parentLabelMap),
            'sample_labels'    => $sampleLabels,
            'unlabelled'       => $unlabelled,
            'class_counts'     => $classCounts,
        ];
    }
}
