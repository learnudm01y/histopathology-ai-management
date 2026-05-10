<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Models\ServerName;
use App\Services\RunPodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RunPodController extends Controller
{
    // ─── Pod listing ─────────────────────────────────────────────────────────

    /**
     * Show the RunPod pod management page for a given server.
     */
    public function index(ServerName $server): View|RedirectResponse
    {
        if (!$server->runpod_api_key) {
            return redirect()->route('admin.settings.servers.edit', $server)
                ->with('error', 'This server has no RunPod API key configured. Please add it first.');
        }

        try {
            $pods = $this->service($server)->listPods();
        } catch (\Throwable $e) {
            $pods = [];
            session()->flash('error', 'Could not fetch pods from RunPod: ' . $e->getMessage());
        }

        // Sort: running first, then by name
        usort($pods, fn($a, $b) =>
            ($b['desiredStatus'] === 'RUNNING' ? 1 : 0) <=>
            ($a['desiredStatus'] === 'RUNNING' ? 1 : 0)
            ?: strcmp($a['name'] ?? '', $b['name'] ?? '')
        );

        return view('admin.settings.servers.runpod', compact('server', 'pods'));
    }

    // ─── Pod actions (AJAX) ──────────────────────────────────────────────────

    /**
     * Start (resume) a pod.
     */
    public function start(Request $request, ServerName $server): JsonResponse
    {
        $request->validate(['pod_id' => 'required|string']);

        try {
            $result = $this->service($server)->startPod($request->pod_id);
            return response()->json([
                'success' => true,
                'status'  => $result['desiredStatus'] ?? 'RUNNING',
                'message' => 'Pod is starting…',
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Stop a pod.
     */
    public function stop(Request $request, ServerName $server): JsonResponse
    {
        $request->validate(['pod_id' => 'required|string']);

        try {
            $result = $this->service($server)->stopPod($request->pod_id);
            return response()->json([
                'success' => true,
                'status'  => $result['desiredStatus'] ?? 'EXITED',
                'message' => 'Pod is stopping…',
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Refresh pod list (AJAX).
     */
    public function refresh(ServerName $server): JsonResponse
    {
        try {
            $pods = $this->service($server)->listPods();
            return response()->json(['success' => true, 'pods' => $pods]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Select a pod as the active one for this server (updates api_url in DB).
     */
    public function select(Request $request, ServerName $server): JsonResponse
    {
        $request->validate(['pod_id' => 'required|string']);

        try {
            $pod = $this->service($server)->getPod($request->pod_id);

            if (!$pod) {
                return response()->json(['success' => false, 'message' => 'Pod not found.'], 404);
            }

            if ($pod['desiredStatus'] !== 'RUNNING') {
                return response()->json(['success' => false, 'message' => 'Pod is not running yet. Start it first.'], 422);
            }

            // Build the RunPod proxy URL from pod ID
            // Format: https://{podId}-8000.proxy.runpod.net
            $proxyUrl = 'https://' . $pod['id'] . '-8000.proxy.runpod.net';

            $server->update(['api_url' => $proxyUrl]);

            return response()->json([
                'success' => true,
                'api_url' => $proxyUrl,
                'message' => 'Server URL updated to ' . $proxyUrl,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ─── Private ─────────────────────────────────────────────────────────────

    private function service(ServerName $server): RunPodService
    {
        return new RunPodService($server->runpod_api_key);
    }
}
