<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PatientCase;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CasesController extends Controller
{
    /**
     * GET /admin/cases
     * List clinical cases with their slide counts and status badges.
     */
    public function index(Request $request): View
    {
        $query = PatientCase::query()
            ->with(['clinicalInfo', 'dataSource'])
            ->withCount('samples')
            ->orderByDesc('id');

        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('case_id', 'like', "%{$term}%")
                  ->orWhere('submitter_id', 'like', "%{$term}%")
                  ->orWhere('project_id', 'like', "%{$term}%")
                  ->orWhere('disease_type', 'like', "%{$term}%")
                  ->orWhere('primary_site', 'like', "%{$term}%");
            });
        }

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        if ($request->filled('with_clinical')) {
            $query->whereHas('clinicalInfo');
        }

        if ($request->filled('without_clinical')) {
            $query->whereDoesntHave('clinicalInfo');
        }

        if ($request->filled('with_slides')) {
            $query->has('samples');
        }

        if ($request->filled('without_slides')) {
            $query->doesntHave('samples');
        }

        // Fully linked = has both slides AND clinical info
        if ($request->filled('fully_linked')) {
            $query->has('samples')->whereHas('clinicalInfo');
        }

        $cases = $query->paginate(20)->withQueryString();

        $stats = [
            'total'             => PatientCase::count(),
            'with_clinical'     => PatientCase::whereHas('clinicalInfo')->count(),
            'with_slides'       => PatientCase::has('samples')->count(),
            'fully_linked'      => PatientCase::has('samples')->whereHas('clinicalInfo')->count(),
        ];

        $projects = PatientCase::query()
            ->whereNotNull('project_id')
            ->distinct()
            ->orderBy('project_id')
            ->pluck('project_id');

        return view('admin.cases.index', compact('cases', 'stats', 'projects'));
    }

    /**
     * GET /admin/cases/{case}
     * Show a single case with all slides + full clinical record.
     */
    public function show(PatientCase $case): View
    {
        $case->load([
            'clinicalInfo',
            'samples' => fn ($q) => $q->with(['organ', 'category', 'stain'])->orderBy('entity_submitter_id'),
            'organ',
            'dataSource',
        ]);

        return view('admin.cases.show', compact('case'));
    }
}
