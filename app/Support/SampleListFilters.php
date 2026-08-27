<?php

namespace App\Support;

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
    public const KEYS = ['organ_id', 'category_id', 'storage_status', 'search'];

    public static function apply(Builder $query, Request $request): void
    {
        if ($request->filled('organ_id')) {
            $query->where('organ_id', $request->organ_id);
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
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
