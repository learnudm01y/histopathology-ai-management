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

    /**
     * The GPU catalogue with what each card actually costs.
     *
     * `uninterruptablePrice` is the on-demand rate; `minimumBidPrice` is the
     * interruptible (spot) rate. They are reported separately and never
     * averaged or "adjusted": when RunPod returns the same figure for both —
     * which it currently does on this account — the honest thing to show is
     * that there is no spot saving, not a discount that does not exist.
     *
     * @return array<int, array{
     *     id: string, name: string, memory_gb: int|null,
     *     on_demand: float|null, spot: float|null,
     *     secure: bool, community: bool
     * }>
     */
    public function listGpuTypes(): array
    {
        $query = <<<'GQL'
        query {
            gpuTypes {
                id
                displayName
                memoryInGb
                secureCloud
                communityCloud
                lowestPrice(input: { gpuCount: 1 }) {
                    minimumBidPrice
                    uninterruptablePrice
                }
            }
        }
        GQL;

        $types = $this->query($query)['gpuTypes'] ?? [];

        return collect($types)
            ->map(fn (array $t) => [
                'id'        => $t['id'] ?? '',
                'name'      => $t['displayName'] ?? ($t['id'] ?? 'Unknown GPU'),
                'memory_gb' => $t['memoryInGb'] ?? null,
                'on_demand' => $t['lowestPrice']['uninterruptablePrice'] ?? null,
                'spot'      => $t['lowestPrice']['minimumBidPrice'] ?? null,
                'secure'    => (bool) ($t['secureCloud'] ?? false),
                'community' => (bool) ($t['communityCloud'] ?? false),
            ])
            // A card with no price is one RunPod cannot currently place, so it
            // is not a choice worth offering.
            ->filter(fn (array $t) => $t['on_demand'] !== null || $t['spot'] !== null)
            ->sortBy(fn (array $t) => $t['on_demand'] ?? $t['spot'])
            ->values()
            ->all();
    }

    /**
     * The URL a pod answers on, or null if it is not answering yet.
     *
     * RunPod exposes a pod through a proxy host built from its id and the port
     * the service listens on. A pod that is not RUNNING has no endpoint, and
     * saying so is better than handing out a URL that will refuse every request.
     */
    public function proxyUrlFor(array $pod, int $port): ?string
    {
        if (($pod['desiredStatus'] ?? null) !== 'RUNNING' || empty($pod['id'])) {
            return null;
        }

        return 'https://' . $pod['id'] . '-' . $port . '.proxy.runpod.net';
    }

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
