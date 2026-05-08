<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Models\ServerName;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ServersController extends Controller
{
    public function index(): View
    {
        $servers = ServerName::withCount('samples')
            ->orderBy('type')
            ->orderBy('name')
            ->get();

        return view('admin.settings.servers.index', compact('servers'));
    }

    public function create(): View
    {
        return view('admin.settings.servers.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateData($request);

        ServerName::create([
            ...$validated,
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->route('admin.settings.servers.index')
            ->with('success', 'Server "' . $validated['name'] . '" added successfully.');
    }

    public function edit(ServerName $server): View
    {
        return view('admin.settings.servers.edit', compact('server'));
    }

    public function update(Request $request, ServerName $server): RedirectResponse
    {
        $validated = $this->validateData($request, $server->id);

        $server->update([
            ...$validated,
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->route('admin.settings.servers.index')
            ->with('success', 'Server "' . $server->name . '" updated successfully.');
    }

    public function destroy(ServerName $server): RedirectResponse
    {
        if ($server->samples()->exists()) {
            return redirect()->route('admin.settings.servers.index')
                ->with('error', 'Cannot delete "' . $server->name . '" — it is linked to one or more samples.');
        }

        $name = $server->name;
        $server->delete();

        return redirect()->route('admin.settings.servers.index')
            ->with('success', 'Server "' . $name . '" deleted.');
    }

    // ── Private ──────────────────────────────────────────────────────────────

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name'        => 'required|string|max:150|unique:servers_names,name' . ($ignoreId ? ",{$ignoreId}" : ''),
            'type'        => 'required|in:local,external',
            'api_url'     => 'nullable|url|max:500',
            'api_key'     => 'nullable|string|max:500',
            'host'        => 'nullable|string|max:255',
            'description' => 'nullable|string|max:500',
        ]);
    }
}
