<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Models\DataSource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DataSourcesController extends Controller
{
    public function index(): View
    {
        $dataSources = DataSource::withCount('samples')
            ->orderBy('name')
            ->get();

        return view('admin.settings.data-sources.index', compact('dataSources'));
    }

    public function create(): View
    {
        return view('admin.settings.data-sources.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name'                   => 'required|string|max:50|unique:data_sources,name',
            'full_name'              => 'nullable|string|max:200',
            'base_url'               => 'nullable|url|max:300',
            'description'            => 'nullable|string',
            'total_slides_available' => 'nullable|integer|min:0',
        ]);

        DataSource::create([
            'name'                   => $request->name,
            'full_name'              => $request->full_name,
            'base_url'               => $request->base_url,
            'description'            => $request->description,
            'total_slides_available' => $request->total_slides_available,
            'is_active'              => $request->boolean('is_active', true),
        ]);

        return redirect()->route('admin.settings.data-sources.index')
            ->with('success', 'Data source "' . $request->name . '" created successfully.');
    }

    public function edit(DataSource $dataSource): View
    {
        return view('admin.settings.data-sources.edit', compact('dataSource'));
    }

    public function update(Request $request, DataSource $dataSource): RedirectResponse
    {
        $request->validate([
            'name'                   => 'required|string|max:50|unique:data_sources,name,' . $dataSource->id,
            'full_name'              => 'nullable|string|max:200',
            'base_url'               => 'nullable|url|max:300',
            'description'            => 'nullable|string',
            'total_slides_available' => 'nullable|integer|min:0',
        ]);

        $dataSource->update([
            'name'                   => $request->name,
            'full_name'              => $request->full_name,
            'base_url'               => $request->base_url,
            'description'            => $request->description,
            'total_slides_available' => $request->total_slides_available,
            'is_active'              => $request->boolean('is_active'),
        ]);

        return redirect()->route('admin.settings.data-sources.index')
            ->with('success', 'Data source "' . $dataSource->name . '" updated successfully.');
    }

    public function destroy(DataSource $dataSource): RedirectResponse
    {
        if ($dataSource->samples()->exists()) {
            return back()->with('error', 'Cannot delete: this data source has samples attached to it.');
        }

        $dataSource->delete();

        return redirect()->route('admin.settings.data-sources.index')
            ->with('success', 'Data source deleted successfully.');
    }
}
