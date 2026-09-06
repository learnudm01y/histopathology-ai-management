<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Organ;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Taxonomy portal — three levels, organ-rooted:
 *
 *      Organ (chosen from `organs`)  →  Category (clinical group)  →  DiseaseSubtype
 *
 * The organ is never typed here. It is picked from the curated `organs` table,
 * which is what guarantees a disease can only ever live under the anatomical
 * site it actually belongs to.
 */
class CategoriesController extends Controller
{
    public function index(Request $request): View
    {
        $organs = Organ::orderBy('name')->get();

        // Optional organ filter — the tree is big once every organ is populated.
        $selectedOrganId = $request->integer('organ_id') ?: null;

        $categories = Category::withCount(['diseaseSubtypes', 'samples'])
            ->with([
                'organ',
                'rootDiseaseSubtypes' => fn($q) => $q->orderBy('name'),
                'rootDiseaseSubtypes.childrenRecursive',
            ])
            ->forOrgan($selectedOrganId)
            ->orderBy('organ_id')
            ->orderBy('label_en')
            ->get();

        // Legacy groups that predate the organ root and still need assigning.
        $unrootedCount = Category::unrooted()->count();

        $grouped = $categories->groupBy('organ_id');

        return view('admin.settings.categories.index', compact(
            'categories', 'grouped', 'organs', 'selectedOrganId', 'unrootedCount'
        ));
    }

    public function create(Request $request): View
    {
        $organs          = Organ::where('is_active', true)->orderBy('name')->get();
        $selectedOrganId = $request->integer('organ_id') ?: null;

        return view('admin.settings.categories.create', compact('organs', 'selectedOrganId'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'organ_id' => ['required', 'integer', 'exists:organs,id'],
            'label_en' => [
                'required', 'string', 'max:100',
                Rule::unique('categories', 'label_en')->where('organ_id', $request->integer('organ_id')),
            ],
            'notes'    => ['nullable', 'string'],
        ], [], [
            'organ_id' => 'organ',
            'label_en' => 'clinical group name',
        ]);

        $category = Category::create([
            'organ_id'  => $validated['organ_id'],
            'label_en'  => $validated['label_en'],
            'is_active' => $request->boolean('is_active'),
            'notes'     => $validated['notes'] ?? null,
        ]);

        return redirect()->route('admin.settings.categories.index', ['organ_id' => $category->organ_id])
            ->with('success', "Clinical group \"{$category->qualified_name}\" created.");
    }

    public function edit(Category $category): View
    {
        $organs = Organ::where('is_active', true)
            ->orWhere('id', $category->organ_id)   // keep an inactive organ selectable when already in use
            ->orderBy('name')
            ->get();

        return view('admin.settings.categories.edit', compact('category', 'organs'));
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $validated = $request->validate([
            'organ_id' => ['required', 'integer', 'exists:organs,id'],
            'label_en' => [
                'required', 'string', 'max:100',
                Rule::unique('categories', 'label_en')
                    ->where('organ_id', $request->integer('organ_id'))
                    ->ignore($category->id),
            ],
            'notes'    => ['nullable', 'string'],
        ], [], [
            'organ_id' => 'organ',
            'label_en' => 'clinical group name',
        ]);

        // Moving a populated group to another organ would relabel every slide
        // under it, so it is refused rather than done silently.
        if ((int) $validated['organ_id'] !== (int) $category->organ_id
            && $category->samples()->exists()) {
            return back()->withInput()->withErrors([
                'organ_id' => 'This group cannot be moved to another organ: '
                    . $category->samples()->count() . ' slide(s) are already classified under it. '
                    . 'Re-assign those slides first.',
            ]);
        }

        // Guard the denormalised organ on the diseases underneath: a move must
        // not create a duplicate disease name inside the destination organ.
        if ((int) $validated['organ_id'] !== (int) $category->organ_id) {
            $names = $category->diseaseSubtypes()->pluck('name');
            if ($names->isNotEmpty()) {
                $clash = \App\Models\DiseaseSubtype::forOrgan((int) $validated['organ_id'])
                    ->whereIn('name', $names)
                    ->whereNotIn('category_id', [$category->id])
                    ->pluck('name');
                if ($clash->isNotEmpty()) {
                    return back()->withInput()->withErrors([
                        'organ_id' => 'Cannot move: the destination organ already has these disease(s): '
                            . $clash->implode(', ') . '.',
                    ]);
                }
            }
        }

        // Category::booted() cascades organ_id onto the diseases underneath.
        $category->update([
            'organ_id'  => $validated['organ_id'],
            'label_en'  => $validated['label_en'],
            'is_active' => $request->boolean('is_active'),
            'notes'     => $validated['notes'] ?? null,
        ]);

        return redirect()->route('admin.settings.categories.index', ['organ_id' => $category->organ_id])
            ->with('success', "Clinical group \"{$category->qualified_name}\" updated.");
    }

    public function destroy(Category $category): RedirectResponse
    {
        if ($category->samples()->exists()) {
            return back()->with('error', 'Cannot delete: this clinical group has samples attached to it.');
        }

        if ($category->diseaseSubtypes()->exists()) {
            return back()->with('error', 'Cannot delete: remove its disease subtypes first.');
        }

        $organId = $category->organ_id;
        $category->delete();

        return redirect()->route('admin.settings.categories.index', ['organ_id' => $organId])
            ->with('success', 'Clinical group deleted.');
    }
}
