<?php

namespace App\Support;

use App\Models\DiseaseSubtype;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The single source of truth for the Samples list filters.
 *
 * Shared by the on-screen table (DashboardController::samples) and the
 * rejection-reasons export (SampleRejectionsController::export) so a filtered
 * list and the file exported from it always describe the same set of slides.
 */
class SampleListFilters
{
    /**
     * Query-string keys this class understands. Handed to the Blade view so an
     * export link carries exactly the filters the list is showing.
     */
    public const KEYS = ['organ_id', 'category_id', 'disease_subtype_id', 'subtree', 'storage_status', 'search'];

    public static function apply(Builder $query, Request $request): void
    {
        if ($request->filled('organ_id')) {
            $query->where('organ_id', $request->organ_id);
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        // Disease filter — reached from the slide counts on the taxonomy page.
        // 'none' isolates the slides that are filed under a clinical group but
        // were never given a disease, which is exactly the gap that makes the
        // group total and the sum of its diseases disagree.
        if ($request->filled('disease_subtype_id')) {
            if ($request->disease_subtype_id === 'none') {
                $query->whereNull('disease_subtype_id');
            } elseif ($request->boolean('subtree')) {
                // A coarse disease is trained through its refinements, so its
                // count has to include every finer disease below it.
                $query->whereIn(
                    'disease_subtype_id',
                    DiseaseSubtype::subtreeIds($request->integer('disease_subtype_id'))
                );
            } else {
                $query->where('disease_subtype_id', $request->integer('disease_subtype_id'));
            }
        }

        if ($request->filled('storage_status')) {
            $query->where('storage_status', $request->storage_status);
        }

        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('file_name', 'like', '%' . $term . '%')
                  ->orWhere('file_id', 'like', '%' . $term . '%')
                  ->orWhere('entity_submitter_id', 'like', '%' . $term . '%');
            });
        }
    }
}
