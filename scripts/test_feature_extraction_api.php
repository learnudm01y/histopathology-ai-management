<?php

/**
 * test_feature_extraction_api.php
 * --------------------------------
 * End-to-end smoke test for the feature-extraction API surface.
 *
 * Runs the following scenarios:
 *   1. Public health check                           (no auth)
 *   2. Authenticated health check with VALID token   (200)
 *   3. Authenticated health check with INVALID token (403)
 *   4. Authenticated health check with NO  token     (401)
 *   5. POST /api/v1/feature-extraction/report
 *      → status = "processing"
 *   6. POST /api/v1/feature-extraction/report
 *      → status = "completed" with full payload
 *   7. GET  /api/v1/feature-extraction/jobs/{sample}
 *      and assert all fields persisted correctly in the DB
 *
 * Usage:
 *   php scripts/test_feature_extraction_api.php
 *
 * Reads BASE_URL and API_KEY from .env (or env vars):
 *   FE_TEST_BASE_URL  (default: http://127.0.0.1:8000)
 *   FE_TEST_API_KEY   (must match servers_names.api_key of an active server)
 *   FE_TEST_SAMPLE_ID (must exist in the samples table)
 */

declare(strict_types=1);

// ─── Bootstrap Laravel so we can read DB/config ──────────────────────────────
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// ─── Test configuration ──────────────────────────────────────────────────────
$baseUrl  = rtrim(getenv('FE_TEST_BASE_URL') ?: 'http://127.0.0.1:8000', '/');
$apiKey   = getenv('FE_TEST_API_KEY') ?: null;
$sampleId = (int) (getenv('FE_TEST_SAMPLE_ID') ?: 0);

// Auto-discover the first active external server with an api_key
if (!$apiKey) {
    $server = \App\Models\ServerName::where('is_active', true)
        ->whereNotNull('api_key')
        ->first();
    if ($server) {
        $apiKey = $server->api_key;
        echo "[setup] Using api_key from server '{$server->name}' (id={$server->id})\n";
    }
}

// Auto-discover an existing sample
if (!$sampleId) {
    $sample = \App\Models\Sample::orderBy('id')->first();
    if ($sample) {
        $sampleId = (int) $sample->id;
        echo "[setup] Using sample_id={$sampleId} ({$sample->file_name})\n";
    }
}

if (!$apiKey || !$sampleId) {
    echo "ERROR: Need FE_TEST_API_KEY (or an active server with api_key) and FE_TEST_SAMPLE_ID (or at least one sample).\n";
    exit(2);
}

// ─── Test runner helpers ─────────────────────────────────────────────────────
$pass = 0; $fail = 0;

function call(string $method, string $url, array $headers = [], ?array $body = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
        CURLOPT_TIMEOUT        => 15,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(
            ['Accept: application/json', 'Content-Type: application/json'],
            $headers,
        ));
    }
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => $resp, 'error' => $err];
}

function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ✓ {$name}\n";
    } else {
        $fail++;
        echo "  ✗ {$name}  — {$detail}\n";
    }
}

echo "================================================================\n";
echo "  Feature Extraction API – End-to-End Smoke Test\n";
echo "  Base URL : {$baseUrl}\n";
echo "  Sample # : {$sampleId}\n";
echo "================================================================\n";

// ── 1. Public health ────────────────────────────────────────────────────────
echo "\n[1] Public /api/health\n";
$r = call('GET', "{$baseUrl}/api/health");
check('returns 200', $r['code'] === 200, "got {$r['code']}; body={$r['body']}");
check('reports success', str_contains($r['body'] ?: '', '"success":true'));

// ── 2. Auth health (valid) ───────────────────────────────────────────────────
echo "\n[2] Authenticated /api/v1/health  (valid token)\n";
$r = call('GET', "{$baseUrl}/api/v1/health", ["Authorization: Bearer {$apiKey}"]);
check('returns 200', $r['code'] === 200, "got {$r['code']}; body={$r['body']}");

// ── 3. Auth health (invalid) ─────────────────────────────────────────────────
echo "\n[3] Authenticated /api/v1/health  (invalid token)\n";
$r = call('GET', "{$baseUrl}/api/v1/health", ['Authorization: Bearer invalid-key-xxx']);
check('returns 403', $r['code'] === 403, "got {$r['code']}");

// ── 4. Auth health (missing) ─────────────────────────────────────────────────
echo "\n[4] Authenticated /api/v1/health  (no token)\n";
$r = call('GET', "{$baseUrl}/api/v1/health");
check('returns 401', $r['code'] === 401, "got {$r['code']}");

// ── 5. Report processing ─────────────────────────────────────────────────────
echo "\n[5] Report status = processing\n";
$r = call('POST', "{$baseUrl}/api/v1/feature-extraction/report",
    ["Authorization: Bearer {$apiKey}"],
    [
        'sample_id' => $sampleId,
        'slide_id'  => 'test-slide',
        'status'    => 'processing',
    ],
);
check('returns 200', $r['code'] === 200, "got {$r['code']}; body={$r['body']}");

\App\Models\Sample::find($sampleId)->refresh();
$s = \App\Models\Sample::find($sampleId);
check('DB feature_extraction_status = processing', $s->feature_extraction_status === 'processing',
      "got '{$s->feature_extraction_status}'");

// ── 6. Report completed ──────────────────────────────────────────────────────
echo "\n[6] Report status = completed (full payload)\n";
$completedPayload = [
    'sample_id'                 => $sampleId,
    'slide_id'                  => 'test-slide',
    'status'                    => 'completed',
    'runpod_output_path'        => '/workspace/output/features/test-slide',
    'features_gdrive_path'      => 'samples/features/TITAN/20x/test-source/test-cat/CASE-001/sample_42_256px',
    'features_gdrive_folder_id' => 'fake-folder-id-1234567890',
    'patch_count'               => 1234,
    'failed_patch_count'        => 0,
    'model_name'                => 'TITAN',
    'model_version'             => 'TITAN-v1',
    'error_message'             => '',
];
$r = call('POST', "{$baseUrl}/api/v1/feature-extraction/report",
    ["Authorization: Bearer {$apiKey}"],
    $completedPayload,
);
check('returns 200', $r['code'] === 200, "got {$r['code']}; body={$r['body']}");

$s = \App\Models\Sample::find($sampleId);
check('DB status = completed', $s->feature_extraction_status === 'completed', "got '{$s->feature_extraction_status}'");
check('DB features_gdrive_path persisted',
      $s->features_gdrive_path === $completedPayload['features_gdrive_path'],
      "got '{$s->features_gdrive_path}'");
check('DB features_gdrive_folder_id persisted',
      $s->features_gdrive_folder_id === $completedPayload['features_gdrive_folder_id'],
      "got '{$s->features_gdrive_folder_id}'");
check('DB features_runpod_path persisted',
      $s->features_runpod_path === $completedPayload['runpod_output_path']);
check('DB features_patch_count = 1234', (int) $s->features_patch_count === 1234);
check('DB features_model_version = TITAN-v1', $s->features_model_version === 'TITAN-v1');
check('DB feature_extraction_completed_at is set', !empty($s->feature_extraction_completed_at));

// ── 7. GET sample status ────────────────────────────────────────────────────
echo "\n[7] GET /api/v1/feature-extraction/jobs/{sample}\n";
$r = call('GET', "{$baseUrl}/api/v1/feature-extraction/jobs/{$sampleId}",
    ["Authorization: Bearer {$apiKey}"]);
check('returns 200', $r['code'] === 200, "got {$r['code']}");
$body = json_decode($r['body'] ?: '', true);
check('payload.success = true', ($body['success'] ?? false) === true);
check('payload.sample.id matches', ($body['sample']['id'] ?? null) === $sampleId);
check('payload.sample.feature_extraction_status = completed',
      ($body['sample']['feature_extraction_status'] ?? '') === 'completed');

// ── Validation errors ────────────────────────────────────────────────────────
echo "\n[8] Invalid status field rejected (validation)\n";
$r = call('POST', "{$baseUrl}/api/v1/feature-extraction/report",
    ["Authorization: Bearer {$apiKey}"],
    ['sample_id' => $sampleId, 'status' => 'banana']);
check('returns 422', $r['code'] === 422, "got {$r['code']}");

echo "\n[9] Missing sample_id rejected\n";
$r = call('POST', "{$baseUrl}/api/v1/feature-extraction/report",
    ["Authorization: Bearer {$apiKey}"],
    ['status' => 'processing']);
check('returns 422', $r['code'] === 422, "got {$r['code']}");

echo "\n[10] Unknown sample_id rejected\n";
$r = call('POST', "{$baseUrl}/api/v1/feature-extraction/report",
    ["Authorization: Bearer {$apiKey}"],
    ['sample_id' => 999999999, 'status' => 'processing']);
check('returns 422', $r['code'] === 422, "got {$r['code']}");

// ── Summary ──────────────────────────────────────────────────────────────────
echo "\n================================================================\n";
echo "  Passed: {$pass}\n";
echo "  Failed: {$fail}\n";
echo "================================================================\n";
exit($fail === 0 ? 0 : 1);
