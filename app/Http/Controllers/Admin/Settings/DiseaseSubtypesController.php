<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\DiseaseSubtype;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DiseaseSubtypesController extends Controller
{
    public function store(Request $request, Category $category): RedirectResponse
    {
        $request->validate([
            'name' => [
                'required', 'string', 'max:150',
                Rule::unique('disease_subtypes', 'name')->where('category_id', $category->id),
            ],
        ]);

        DiseaseSubtype::create([
            'category_id' => $category->id,
            'name'        => $request->name,
            'is_active'   => true,
        ]);

        return redirect()->route('admin.settings.categories.index')
            ->with('success', 'Disease subtype "' . $request->name . '" added to ' . $category->label_en . '.')
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
                    ->where('category_id', $category->id)
                    ->ignore($subtype->id),
            ],
            'notes' => 'nullable|string',
        ]);

        $subtype->update([
            'name'      => $request->name,
            'is_active' => $request->boolean('is_active'),
            'notes'     => $request->notes,
        ]);

        return redirect()->route('admin.settings.categories.index')
            ->with('success', 'Subtype "' . $subtype->name . '" updated.')
            ->with('open_category', $category->id);
    }

    public function destroy(Category $category, DiseaseSubtype $subtype): RedirectResponse
    {
        $subtype->delete();

        return redirect()->route('admin.settings.categories.index')
            ->with('success', 'Subtype deleted.')
            ->with('open_category', $category->id);
    }
}
