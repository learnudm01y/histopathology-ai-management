<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Models\Organ;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrgansController extends Controller
{
    public function index(): View
    {
        $organs = Organ::withCount('samples')->orderBy('name')->get();
        return view('admin.settings.organs.index', compact('organs'));
    }

    public function create(): View
    {
        return view('admin.settings.organs.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name'  => 'required|string|max:50|unique:organs,name',
            'notes' => 'nullable|string',
        ]);

        Organ::create([
            'name'      => $request->name,
            'is_active' => $request->boolean('is_active'),
            'notes'     => $request->notes,
        ]);

        return redirect()->route('admin.settings.organs.index')
            ->with('success', 'Organ added successfully.');
    }

    public function edit(Organ $organ): View
    {
        return view('admin.settings.organs.edit', compact('organ'));
    }

    public function update(Request $request, Organ $organ): RedirectResponse
    {
        $request->validate([
            'name'  => 'required|string|max:50|unique:organs,name,' . $organ->id,
            'notes' => 'nullable|string',
        ]);

        $organ->update([
            'name'      => $request->name,
            'is_active' => $request->boolean('is_active'),
            'notes'     => $request->notes,
        ]);

        return redirect()->route('admin.settings.organs.index')
            ->with('success', 'Organ updated successfully.');
    }

    public function destroy(Organ $organ): RedirectResponse
    {
        if ($organ->samples()->exists()) {
            return back()->with('error', 'Cannot delete: this organ has samples attached to it.');
        }

        $organ->delete();

        return redirect()->route('admin.settings.organs.index')
            ->with('success', 'Organ deleted successfully.');
    }
}
