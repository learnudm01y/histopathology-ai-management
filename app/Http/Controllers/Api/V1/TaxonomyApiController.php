<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DiseaseSubtype;
use App\Services\TaxonomyCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only access to the classification vocabulary, for systems that send
 * slides in from outside and need to label them the way the platform does.
 *
 * Endpoints (all under verify.server.api_key):
 *   GET /api/v1/taxonomy             – organ → category → disease tree
 *   GET /api/v1/taxonomy/organs      – flat organ list
 *   GET /api/v1/taxonomy/stains      – stain vocabulary
 *   GET /api/v1/taxonomy/reference   – magnifications, patch sizes, data sources
 *   GET /api/v1/taxonomy/resolve     – check a label and turn names into ids
 *
 * Nothing here writes. Documented at /docs/api.
 */
class TaxonomyApiController extends Controller
{
    public function __construct(private TaxonomyCatalog $catalog) {}

    public function tree(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organ'            => ['nullable', 'string', 'max:100'],
            'include_inactive' => ['nullable', 'boolean'],
        ]);

        $organId = null;
        if (! blank($data['organ'] ?? null)) {
            $organId = $this->catalog->resolve(['organ' => $data['organ']])['classification']['organ']['id'] ?? null;
            if ($organId === null) {
                return response()->json(['success' => false, 'message' => "No organ matches \"{$data['organ']}\"."], 404);
            }
        }

        $includeInactive = $request->boolean('include_inactive');

        return response()->json([
            'success'             => true,
            'levels'              => ['organ', 'category', 'disease'],
            'max_disease_depth'   => DiseaseSubtype::MAX_DEPTH,
            'organs'              => $this->catalog->tree($organId, $includeInactive),
            'unrooted_categories' => $organId === null ? $this->catalog->unrootedCategories() : [],
        ]);
    }

    public function organs(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'organs'  => $this->catalog->organs($request->boolean('include_inactive')),
        ]);
    }

    public function stains(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'stains'  => $this->catalog->stains($request->boolean('include_inactive')),
        ]);
    }

    public function reference(): JsonResponse
    {
        return response()->json(['success' => true] + $this->catalog->reference());
    }

    /**
     * 200 when the label can be filed as given, 422 when it cannot — the body
     * says why either way, so a caller can show the reason to a person.
     */
    public function resolve(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organ'    => ['nullable', 'max:100'],
            'category' => ['nullable', 'max:100'],
            'disease'  => ['nullable', 'max:255'],
            'stain'    => ['nullable', 'max:100'],
        ]);

        $result = $this->catalog->resolve($data);

        return response()->json(['success' => true] + $result, $result['valid'] ? 200 : 422);
    }
}
