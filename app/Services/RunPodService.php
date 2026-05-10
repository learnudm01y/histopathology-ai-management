<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * RunPodService
 * -------------
 * Communicates with the RunPod GraphQL API to list, start, and stop pods.
 * Endpoint: https://api.runpod.io/graphql?api_key={API_KEY}
 */
class RunPodService
{
    private const GRAPHQL_URL = 'https://api.runpod.io/graphql';

    public function __construct(private readonly string $apiKey) {}

    // ─── Public API ───────────────────────────────────────────────────────────

    /**
     * List all pods in the account.
     * Returns an array of pod objects, each with at minimum:
     *   id, name, desiredStatus, runtime (null if stopped), costPerHr, machine.gpuDisplayName
     */
    public function listPods(): array
    {
        $query = <<<'GQL'
        query {
            myself {
                pods {
                    id
                    name
                    desiredStatus
                    imageName
                    costPerHr
                    gpuCount
                    runtime {
                        uptimeInSeconds
                        ports {
                            ip
                            isIpPublic
                            privatePort
                            publicPort
                            type
                        }
                    }
                    machine {
                        gpuDisplayName
                        location
                    }
                    networkVolume {
                        id
                        name
                    }
                }
            }
        }
        GQL;

        $data = $this->query($query);

        return $data['myself']['pods'] ?? [];
    }

    /**
     * Resume (start) a stopped pod.
     * $gpuCount defaults to 1.
     */
    public function startPod(string $podId, int $gpuCount = 1): array
    {
        $mutation = <<<GQL
        mutation {
            podResume(input: { podId: "{$podId}", gpuCount: {$gpuCount} }) {
                id
                desiredStatus
                lastStatusChange
            }
        }
        GQL;

        return $this->query($mutation)['podResume'] ?? [];
    }

    /**
     * Stop a running pod.
     */
    public function stopPod(string $podId): array
    {
        $mutation = <<<GQL
        mutation {
            podStop(input: { podId: "{$podId}" }) {
                id
                desiredStatus
            }
        }
        GQL;

        return $this->query($mutation)['podStop'] ?? [];
    }

    /**
     * Get a single pod by ID.
     */
    public function getPod(string $podId): ?array
    {
        $pods = $this->listPods();
        foreach ($pods as $pod) {
            if ($pod['id'] === $podId) {
                return $pod;
            }
        }
        return null;
    }

    // ─── Private ──────────────────────────────────────────────────────────────

    private function query(string $graphql): array
    {
        $response = Http::timeout(15)
            ->post(self::GRAPHQL_URL . '?api_key=' . $this->apiKey, [
                'query' => $graphql,
            ]);

        if (!$response->successful()) {
            Log::error('[RunPodService] HTTP error', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \RuntimeException('RunPod API HTTP error: ' . $response->status());
        }

        $json = $response->json();

        if (!empty($json['errors'])) {
            $msg = collect($json['errors'])->pluck('message')->implode('; ');
            Log::error('[RunPodService] GraphQL errors', ['errors' => $json['errors']]);
            throw new \RuntimeException('RunPod API error: ' . $msg);
        }

        return $json['data'] ?? [];
    }
}
