<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Models\Stain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StainsController extends Controller
{
    private const STAIN_TYPES = [
        'routine'    => 'Routine',
        'special'    => 'Special',
        'IHC'        => 'Immunohistochemistry (IHC)',
        'ISH'        => 'In-Situ Hybridisation (ISH)',
        'fluorescent'=> 'Fluorescent',
        'cytology'   => 'Cytology',
        'other'      => 'Other',
    ];

    public function index(): View
    {
        $stains = Stain::withCount('samples')
            ->orderBy('stain_type')
            ->orderBy('name')
            ->get()
            ->groupBy('stain_type');

        return view('admin.settings.stains.index', [
            'stains'     => $stains,
            'stainTypes' => self::STAIN_TYPES,
        ]);
    }

    public function create(): View
    {
        return view('admin.settings.stains.create', [
            'stainTypes' => self::STAIN_TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'         => 'required|string|max:150|unique:stains,name',
            'abbreviation' => 'required|string|max:30|unique:stains,abbreviation',
            'stain_type'   => 'required|in:' . implode(',', array_keys(self::STAIN_TYPES)),
            'marker'       => 'nullable|string|max:100',
            'description'  => 'nullable|string',
            'notes'        => 'nullable|string',
        ]);

        Stain::create([
            ...$validated,
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->route('admin.settings.stains.index')
            ->with('success', 'Stain "' . $validated['abbreviation'] . '" added successfully.');
    }

    public function edit(Stain $stain): View
    {
        return view('admin.settings.stains.edit', [
            'stain'      => $stain,
            'stainTypes' => self::STAIN_TYPES,
        ]);
    }

    public function update(Request $request, Stain $stain): RedirectResponse
    {
        $validated = $request->validate([
            'name'         => 'required|string|max:150|unique:stains,name,' . $stain->id,
            'abbreviation' => 'required|string|max:30|unique:stains,abbreviation,' . $stain->id,
            'stain_type'   => 'required|in:' . implode(',', array_keys(self::STAIN_TYPES)),
            'marker'       => 'nullable|string|max:100',
            'description'  => 'nullable|string',
            'notes'        => 'nullable|string',
        ]);

        $stain->update([
            ...$validated,
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->route('admin.settings.stains.index')
            ->with('success', 'Stain "' . $stain->abbreviation . '" updated.');
    }

    public function destroy(Stain $stain): RedirectResponse
    {
        if ($stain->samples()->exists()) {
            return back()->with('error',
                'Cannot delete "' . $stain->abbreviation . '": ' . $stain->samples_count . ' sample(s) are linked to it.');
        }

        $stain->delete();

        return redirect()->route('admin.settings.stains.index')
            ->with('success', 'Stain deleted.');
    }
}
