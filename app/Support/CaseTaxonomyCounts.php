<?php

namespace App\Support;

use App\Models\DiseaseSubtype;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * How many *patient cases* sit under each node of the disease taxonomy.
 *
 *      Organ  →  Clinical Group  →  Disease  →  finer Disease …
 *
 * A case carries no diagnosis of its own — the diagnosis lives on its slides —
 * so a case belongs to a node when at least one of its slides is filed there.
 * That makes every number here a DISTINCT-case question rather than a sum: a
 * case with three IDC slides is one breast IDC case, and a case holding both an
 * IDC and a lobular slide is counted once under each without inflating the
 * parent that contains them both.
 *
 * Adding child counts up the tree would therefore double-count, so each roll-up
 * unions case-id sets instead. The whole map is built from ONE query over the
 * distinct (case, organ, group, disease) paths that actually exist, so putting
 * the tree on a page costs a single round trip however deep the taxonomy grows.
 */
class CaseTaxonomyCounts
{
    /** @var array<int, array<int, true>> organ_id => set of case ids */
    private array $organs = [];

    /** @var array<int, array<int, true>> category_id => set of case ids */
    private array $categories = [];

    /** @var array<int, array<int, true>> disease_subtype_id => set of case ids */
    private array $diseases = [];

    /** @var array<int, array<int, true>> category_id => cases whose slides in that group carry no disease */
    private array $unfiled = [];

    /** @var array<int, true> every case the taxonomy can name a disease for */
    private array $classified = [];

    /**
     * @param Builder|null $scope  A PatientCase query to restrict the counts to
     *                             — the list's non-taxonomy filters, so the tree
     *                             describes the same population as the table.
     */
    public static function build(?Builder $scope = null): self
    {
        $counts = new self();

        $rows = DB::table('samples')
            ->select('case_id', 'organ_id', 'category_id', 'disease_subtype_id')
            ->whereNotNull('case_id')
            ->when($scope, fn ($q) => $q->whereIn('case_id', (clone $scope)->select('cases.id')))
            ->distinct()
            ->get();

        foreach ($rows as $row) {
            $counts->record($row);
        }

        return $counts;
    }

    /** Cases with at least one slide in this organ. */
    public function organ(?int $organId): int
    {
        return count($this->organs[$organId] ?? []);
    }

    /** Cases with at least one slide in this clinical group. */
    public function category(?int $categoryId): int
    {
        return count($this->categories[$categoryId] ?? []);
    }

    /** Cases whose slides stop at this exact disease, ignoring finer ones below. */
    public function diseaseOwn(?int $diseaseId): int
    {
        return count($this->diseases[$diseaseId] ?? []);
    }

    /**
     * Cases anywhere in this disease's branch — itself plus every refinement
     * under it. A case labelled only "Infiltrating ductal carcinoma" is still a
     * "Malignant" case, so a coarse node has to answer for its subtree.
     */
    public function diseaseBranch(DiseaseSubtype $disease): int
    {
        return count($this->branchSet($disease));
    }

    /**
     * Cases carrying this clinical group on a slide that was never given a
     * disease. They are the gap between the group's total and its diseases, and
     * the ones that still need labelling before they can be trained on.
     */
    public function unfiled(?int $categoryId): int
    {
        return count($this->unfiled[$categoryId] ?? []);
    }

    /** Cases the taxonomy can name at least one disease for. */
    public function classifiedTotal(): int
    {
        return count($this->classified);
    }

    private function record(object $row): void
    {
        $case = (int) $row->case_id;

        if ($row->organ_id !== null) {
            $this->organs[(int) $row->organ_id][$case] = true;
        }

        if ($row->category_id !== null) {
            $this->categories[(int) $row->category_id][$case] = true;

            if ($row->disease_subtype_id === null) {
                $this->unfiled[(int) $row->category_id][$case] = true;
            }
        }

        if ($row->disease_subtype_id !== null) {
            $this->diseases[(int) $row->disease_subtype_id][$case] = true;
            $this->classified[$case] = true;
        }
    }

    /**
     * The case-id set for a whole branch. Union (`+` on case-id keys), never a
     * sum — the same case may appear under several of the diseases below.
     *
     * @return array<int, true>
     */
    private function branchSet(DiseaseSubtype $disease): array
    {
        $set = $this->diseases[$disease->id] ?? [];

        foreach ($disease->loadedChildren() as $child) {
            $set += $this->branchSet($child);
        }

        return $set;
    }
}
