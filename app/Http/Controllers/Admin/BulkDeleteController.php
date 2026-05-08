<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClinicalCaseInformation;
use App\Models\PatientCase;
use App\Models\Sample;
use App\Services\GoogleDriveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Handles bulk deletion of Samples and PatientCases.
 *
 * Samples delete flow:
 *   1. POST bulk/samples/preview → returns JSON count (used by modal AJAX)
 *   2. DELETE bulk/samples       → deletes from DB + Google Drive
 *
 * Cases delete flow:
 *   1. POST bulk/cases/preview → returns JSON count
 *   2. DELETE bulk/cases       → deletes cases + clinical info from DB
 *      (samples are NOT deleted, only unlinked from the case)
 */
class BulkDeleteController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    //  SAMPLES
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * POST /admin/bulk/samples/preview
     * Returns JSON with the count of samples matching the given filters.
     */
    public function previewSamples(Request $request): JsonResponse
    {
        $request->validate([
            'quality_status' => ['nullable', 'string'],
            'data_source_id' => ['nullable', 'integer'],
            'organ_id'       => ['nullable', 'integer'],
            'category_id'    => ['nullable', 'integer'],
            'storage_status' => ['nullable', 'string'],
        ]);

        $query = $this->buildSampleQuery($request);
        $count = $query->count();

        // Preview up to 10 file names so the user can see what will be deleted
        $preview = $query->orderByDesc('id')
            ->limit(10)
            ->pluck('file_name')
            ->toArray();

        return response()->json([
            'count'   => $count,
            'preview' => $preview,
        ]);
    }

    /**
     * DELETE /admin/bulk/samples
     * Deletes all samples matching filters from DB + Google Drive.
     */
    public function deleteSamples(Request $request): RedirectResponse
    {
        $request->validate([
            'quality_status' => ['nullable', 'string'],
            'data_source_id' => ['nullable', 'integer'],
            'organ_id'       => ['nullable', 'integer'],
            'category_id'    => ['nullable', 'integer'],
            'storage_status' => ['nullable', 'string'],
            'confirm'        => ['required', 'in:DELETE'],
        ]);

        if (!$this->hasAtLeastOneFilter($request, ['quality_status', 'data_source_id', 'organ_id', 'category_id', 'storage_status'])) {
            return back()->with('bulk_error', 'At least one filter must be selected to perform bulk delete.');
        }

        $drive    = app(GoogleDriveService::class);
        $samples  = $this->buildSampleQuery($request)->get(['id', 'wsi_remote_path', 'storage_path', 'file_name', 'storage_status']);
        $total    = $samples->count();
        $deleted  = 0;
        $gdFailed = 0;

        foreach ($samples as $sample) {
            // ── Delete from Google Drive ──────────────────────────────────────
            if ($sample->wsi_remote_path && $sample->storage_status === 'available') {
                $ok = $drive->deleteRemotePath($sample->wsi_remote_path, false);
                if (!$ok) {
                    $gdFailed++;
                    Log::warning("[BulkDelete] Could not delete Drive file for sample #{$sample->id}: {$sample->wsi_remote_path}");
                }
                // Also try to purge the parent folder if it looks like a per-sample folder
                // (folder path stored in storage_path)
                if ($sample->storage_path && str_contains($sample->storage_path, '/')) {
                    $drive->deleteRemotePath($sample->storage_path, true);
                }
            }

            // ── Delete from DB ────────────────────────────────────────────────
            Sample::destroy($sample->id);
            $deleted++;
        }

        $msg = "Bulk delete complete: {$deleted} of {$total} samples removed from DB.";
        if ($gdFailed > 0) {
            $msg .= " ({$gdFailed} Google Drive deletion(s) failed — check logs.)";
        }

        Log::info("[BulkDelete] {$msg}");

        return redirect()->route('admin.samples')->with('success', $msg);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  CASES
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * POST /admin/bulk/cases/preview
     * Returns JSON count of cases matching filters.
     */
    public function previewCases(Request $request): JsonResponse
    {
        $request->validate([
            'data_source_id'  => ['nullable', 'integer'],
            'no_slides_only'  => ['nullable', 'boolean'],
        ]);

        $query   = $this->buildCaseQuery($request);
        $count   = $query->count();
        $preview = $query->orderByDesc('id')->limit(10)->pluck('submitter_id')->toArray();

        return response()->json([
            'count'   => $count,
            'preview' => $preview,
        ]);
    }

    /**
     * DELETE /admin/bulk/cases
     * Deletes cases (+ clinical info) from DB.
     * Samples are NOT deleted — their case_id is set to NULL.
     */
    public function deleteCases(Request $request): RedirectResponse
    {
        $request->validate([
            'data_source_id' => ['nullable', 'integer'],
            'no_slides_only' => ['nullable', 'boolean'],
            'confirm'        => ['required', 'in:DELETE'],
        ]);

        if (!$this->hasAtLeastOneFilter($request, ['data_source_id', 'no_slides_only'])) {
            return back()->with('bulk_cases_error', 'At least one filter must be selected.');
        }

        $cases   = $this->buildCaseQuery($request)->get(['id', 'submitter_id']);
        $total   = $cases->count();
        $deleted = 0;

        foreach ($cases as $case) {
            DB::transaction(function () use ($case) {
                // Unlink samples (do NOT delete them)
                Sample::where('case_id', $case->id)->update(['case_id' => null]);

                // Delete clinical info
                ClinicalCaseInformation::where('case_id', $case->case_id ?? $case->submitter_id)->delete();

                // Delete the case
                PatientCase::destroy($case->id);
            });
            $deleted++;
        }

        $msg = "Bulk delete complete: {$deleted} of {$total} cases removed.";
        Log::info("[BulkDelete] {$msg}");

        return redirect()->route('admin.cases.index')->with('success', $msg);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function buildSampleQuery(Request $request)
    {
        $q = Sample::query();

        if ($request->filled('quality_status')) {
            $q->where('quality_status', $request->quality_status);
        }
        if ($request->filled('data_source_id')) {
            $q->where('data_source_id', $request->data_source_id);
        }
        if ($request->filled('organ_id')) {
            $q->where('organ_id', $request->organ_id);
        }
        if ($request->filled('category_id')) {
            $q->where('category_id', $request->category_id);
        }
        if ($request->filled('storage_status')) {
            $q->where('storage_status', $request->storage_status);
        }

        return $q;
    }

    private function buildCaseQuery(Request $request)
    {
        $q = PatientCase::query();

        if ($request->filled('data_source_id')) {
            $q->where('data_source_id', $request->data_source_id);
        }
        if ($request->boolean('no_slides_only')) {
            $q->doesntHave('samples');
        }

        return $q;
    }

    /**
     * Returns true if at least one of the given filter keys is present & non-empty.
     */
    private function hasAtLeastOneFilter(Request $request, array $keys): bool
    {
        foreach ($keys as $key) {
            if ($request->filled($key) || $request->boolean($key)) {
                return true;
            }
        }
        return false;
    }
}
