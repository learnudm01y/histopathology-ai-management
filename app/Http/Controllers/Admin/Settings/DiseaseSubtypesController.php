<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\DiseaseSubtype;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Disease subtypes — the leaves of the taxonomy, and the classes a fine-grained
 * training run learns.
 *
 * Uniqueness is enforced per ORGAN, not per clinical group: two rows with the
 * same disease name under one organ are the same entity, and letting both exist
 * would split one disease into two training classes that compete for the same
 * slides.
 */
class DiseaseSubtypesController extends Controller
{
    public function store(Request $request, Category $category): RedirectResponse
    {
        if ($category->organ_id === null) {
            return back()->with('error',
                'This clinical group has no organ yet. Assign its organ before adding diseases under it.');
        }

        $request->validate([
            'name' => [
                'required', 'string', 'max:150',
                // Scoped to the ORGAN — the same disease may not be redefined
                // under a sibling group of the same organ.
                Rule::unique('disease_subtypes', 'name')->where('organ_id', $category->organ_id),
            ],
        ], [
            'name.unique' => 'This disease already exists under ' . ($category->organ?->name ?? 'this organ')
                . '. A disease name must be unique within its organ.',
        ]);

        // organ_id is set by DiseaseSubtype::booted() from the parent group.
        DiseaseSubtype::create([
            'category_id' => $category->id,
            'name'        => $request->name,
            'is_active'   => true,
        ]);

        return redirect()->route('admin.settings.categories.index', ['organ_id' => $category->organ_id])
            ->with('success', 'Disease "' . $request->name . '" added to ' . $category->qualified_name . '.')
            ->with('open_category', $category->id);
    }

    public function edit(Category $category, DiseaseSubtype $subtype): View
    {
        return view('admin.settings.categories.subtype-edit', compact('category', 'subtype'));
    }

    public function update(Request $request, Category $category, DiseaseSubtype $subtype): RedirectResponse
    {
        $request->validate([
            'name' => [
                'required', 'string', 'max:150',
                Rule::unique('disease_subtypes', 'name')
                    ->where('organ_id', $category->organ_id)
                    ->ignore($subtype->id),
            ],
            'notes' => ['nullable', 'string'],
        ], [
            'name.unique' => 'This disease already exists under ' . ($category->organ?->name ?? 'this organ')
                . '. A disease name must be unique within its organ.',
        ]);

        $renamed = $subtype->name !== $request->name;

        $subtype->update([
            'name'      => $request->name,
            'is_active' => $request->boolean('is_active'),
            'notes'     => $request->notes,
        ]);

        // The denormalised name on samples is display-only (training resolves by
        // primary key), but leaving it stale would make the slide list lie.
        if ($renamed) {
            $subtype->samples()->update(['disease_subtype' => $subtype->name]);
        }

        return redirect()->route('admin.settings.categories.index', ['organ_id' => $category->organ_id])
            ->with('success', 'Disease "' . $subtype->name . '" updated.')
            ->with('open_category', $category->id);
    }

    public function destroy(Category $category, DiseaseSubtype $subtype): RedirectResponse
    {
        // Deleting a leaf that slides still point at would strip their diagnosis.
        $inUse = $subtype->samples()->count();
        if ($inUse > 0) {
            return back()->with('error',
                "Cannot delete \"{$subtype->name}\": {$inUse} slide(s) are classified as this disease. "
                . 'Re-classify them first.');
        }

        $subtype->delete();

        return redirect()->route('admin.settings.categories.index', ['organ_id' => $category->organ_id])
            ->with('success', 'Disease deleted.')
            ->with('open_category', $category->id);
    }
}
