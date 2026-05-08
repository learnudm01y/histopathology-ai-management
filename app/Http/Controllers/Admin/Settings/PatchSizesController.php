<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\PatchSize;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PatchSizesController extends Controller
{
    public function index(): View
    {
        $patchSizes = PatchSize::with('aiModel:id,name')
            ->withCount('samples')
            ->orderBy('size_px')
            ->orderBy('overlap_px')
            ->get();

        $aiModels = AiModel::where('is_active', true)->orderBy('name')->get(['id', 'name']);

        return view('admin.settings.patch-sizes.index', compact('patchSizes', 'aiModels'));
    }

    public function create(): View
    {
        $aiModels = AiModel::where('is_active', true)->orderBy('name')->get(['id', 'name']);

        return view('admin.settings.patch-sizes.create', compact('aiModels'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateData($request);

        PatchSize::create([
            ...$validated,
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->route('admin.settings.patch-sizes.index')
            ->with('success', 'Patch size "' . $validated['label'] . '" added successfully.');
    }

    public function edit(PatchSize $patchSize): View
    {
        $aiModels = AiModel::where('is_active', true)->orderBy('name')->get(['id', 'name']);

        return view('admin.settings.patch-sizes.edit', compact('patchSize', 'aiModels'));
    }

    public function update(Request $request, PatchSize $patchSize): RedirectResponse
    {
        $validated = $this->validateData($request, $patchSize->id);

        $patchSize->update([
            ...$validated,
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->route('admin.settings.patch-sizes.index')
            ->with('success', 'Patch size "' . $patchSize->label . '" updated successfully.');
    }

    public function destroy(PatchSize $patchSize): RedirectResponse
    {
        if ($patchSize->samples()->exists()) {
            return redirect()->route('admin.settings.patch-sizes.index')
                ->with('error', 'Cannot delete "' . $patchSize->label . '" — it is linked to one or more samples.');
        }

        $label = $patchSize->label;
        $patchSize->delete();

        return redirect()->route('admin.settings.patch-sizes.index')
            ->with('success', 'Patch size "' . $label . '" deleted.');
    }

    // ── Private ──────────────────────────────────────────────────────────────

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'size_px'     => 'required|integer|min:1|max:4096',
            'label'       => 'required|string|max:150|unique:patch_sizes,label' . ($ignoreId ? ",{$ignoreId}" : ''),
            'wsi_level'   => 'required|integer|min:0|max:20',
            'overlap_px'  => 'required|integer|min:0|max:2048',
            'ai_model_id' => 'nullable|exists:ai_models,id',
            'notes'       => 'nullable|string|max:1000',
        ]);
    }
}
