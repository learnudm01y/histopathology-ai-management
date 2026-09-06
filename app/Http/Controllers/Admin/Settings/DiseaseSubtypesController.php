<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\DiseaseSubtype;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Diseases — the classes a fine-grained training run learns.
 *
 * A disease may nest under another disease ("Malignant" → "Infiltrating ductal
 * carcinoma"), so this controller handles every level of that chain; the only
 * difference between a top-level disease and a refinement of one is `parent_id`.
 *
 * Uniqueness is enforced per ORGAN, not per clinical group and not per parent:
 * two rows with the same disease name under one organ are the same entity at any
 * depth, and letting both exist would split one disease into two training classes
 * that compete for the same slides.
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
                // under a sibling group, or under a sibling disease, of the same organ.
                Rule::unique('disease_subtypes', 'name')->where('organ_id', $category->organ_id),
            ],
            'parent_id' => [
                'nullable', 'integer',
                // A disease can only refine another disease of the same group.
                Rule::exists('disease_subtypes', 'id')->where('category_id', $category->id),
            ],
        ], [
            'name.unique' => 'This disease already exists under ' . ($category->organ?->name ?? 'this organ')
                . '. A disease name must be unique within its organ.',
            'parent_id.exists' => 'The parent disease does not belong to this clinical group.',
        ]);

        $parent = $request->filled('parent_id')
            ? DiseaseSubtype::find($request->integer('parent_id'))
            : null;

        if ($parent && $parent->depth >= DiseaseSubtype::MAX_DEPTH) {
            throw ValidationException::withMessages([
                'name' => 'The disease tree is limited to ' . DiseaseSubtype::MAX_DEPTH
                    . ' levels. "' . $parent->name . '" is already at the deepest level.',
            ]);
        }

        // organ_id is set by DiseaseSubtype::booted() from the parent group.
        DiseaseSubtype::create([
            'category_id' => $category->id,
            'parent_id'   => $parent?->id,
            'name'        => $request->name,
            'is_active'   => true,
        ]);

        $under = $parent
            ? $parent->qualified_name
            : $category->qualified_name;

        return redirect()->route('admin.settings.categories.index', ['organ_id' => $category->organ_id])
            ->with('success', 'Disease "' . $request->name . '" added to ' . $under . '.')
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

        // Refinements underneath would be silently orphaned into top-level
        // diseases by the SET NULL on the foreign key, so refuse instead.
        $children = $subtype->children()->count();
        if ($children > 0) {
            return back()->with('error',
                "Cannot delete \"{$subtype->name}\": {$children} finer disease(s) are nested under it. "
                . 'Delete those first.');
        }

        $subtype->delete();

        return redirect()->route('admin.settings.categories.index', ['organ_id' => $category->organ_id])
            ->with('success', 'Disease deleted.')
            ->with('open_category', $category->id);
    }
}
