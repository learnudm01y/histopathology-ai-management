<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CategoriesController extends Controller
{
    public function index(): View
    {
        $categories = Category::withCount(['diseaseSubtypes', 'samples'])
            ->with(['diseaseSubtypes' => fn($q) => $q->orderBy('name')])
            ->orderBy('id')
            ->get();
        return view('admin.settings.categories.index', compact('categories'));
    }

    public function create(): View
    {
        return view('admin.settings.categories.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'label_en'    => 'required|string|max:100',
            'notes'       => 'nullable|string',
        ]);

        Category::create([
            'label_en'    => $request->label_en,
            'is_active'   => $request->boolean('is_active'),
            'notes'       => $request->notes,
        ]);

        return redirect()->route('admin.settings.categories.index')
            ->with('success', 'Category created successfully.');
    }

    public function edit(Category $category): View
    {
        return view('admin.settings.categories.edit', compact('category'));
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $request->validate([
            'label_en'    => 'required|string|max:100',
            'notes'       => 'nullable|string',
        ]);

        $category->update([
            'label_en'    => $request->label_en,
            'is_active'   => $request->boolean('is_active'),
            'notes'       => $request->notes,
        ]);

        return redirect()->route('admin.settings.categories.index')
            ->with('success', 'Category updated successfully.');
    }

    public function destroy(Category $category): RedirectResponse
    {
        if ($category->samples()->exists()) {
            return back()->with('error', 'Cannot delete: this category has samples attached to it.');
        }

        $category->delete();

        return redirect()->route('admin.settings.categories.index')
            ->with('success', 'Category deleted successfully.');
    }
}
