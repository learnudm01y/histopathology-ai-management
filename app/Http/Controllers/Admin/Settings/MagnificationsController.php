<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Models\Magnification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MagnificationsController extends Controller
{
    public function index(): View
    {
        $magnifications = Magnification::withCount('samples')
            ->orderBy('value')
            ->get();

        return view('admin.settings.magnifications.index', compact('magnifications'));
    }

    public function create(): View
    {
        return view('admin.settings.magnifications.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateData($request);

        Magnification::create([
            ...$validated,
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->route('admin.settings.magnifications.index')
            ->with('success', 'Magnification "' . $validated['label'] . '" added successfully.');
    }

    public function edit(Magnification $magnification): View
    {
        return view('admin.settings.magnifications.edit', compact('magnification'));
    }

    public function update(Request $request, Magnification $magnification): RedirectResponse
    {
        $validated = $this->validateData($request, $magnification->id);

        $magnification->update([
            ...$validated,
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->route('admin.settings.magnifications.index')
            ->with('success', 'Magnification "' . $magnification->label . '" updated successfully.');
    }

    public function destroy(Magnification $magnification): RedirectResponse
    {
        if ($magnification->samples()->exists()) {
            return redirect()->route('admin.settings.magnifications.index')
                ->with('error', 'Cannot delete "' . $magnification->label . '" — samples are linked to it.');
        }

        $label = $magnification->label;
        $magnification->delete();

        return redirect()->route('admin.settings.magnifications.index')
            ->with('success', 'Magnification "' . $label . '" deleted.');
    }

    // ── Validation ────────────────────────────────────────────────────────────

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'label'       => ['required', 'string', 'max:20',
                              \Illuminate\Validation\Rule::unique('magnifications', 'label')->ignore($ignoreId)],
            'value'       => ['required', 'integer', 'min:1', 'max:10000'],
            'folder_name' => ['required', 'string', 'max:30', 'alpha_dash',
                              \Illuminate\Validation\Rule::unique('magnifications', 'folder_name')->ignore($ignoreId)],
            'notes'       => ['nullable', 'string', 'max:255'],
            'is_active'   => ['nullable'],
        ]);
    }
}
