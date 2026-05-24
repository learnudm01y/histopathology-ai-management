<?php

namespace App\Console\Commands;

use App\Models\AiModel;
use App\Models\ServerName;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * php artisan info:runpod-api
 *
 * Prints a complete, filled-in integration reference for the RunPod
 * developer — every placeholder replaced with the real value from the
 * live database / .env file.
 */
class ShowRunpodApiGuide extends Command
{
    protected $signature = 'info:runpod-api
                            {--server=2  : servers_names.id of the RunPod server (default 2)}
                            {--model=1   : ai_models.id to use in the example payload (default 1)}';

    protected $description = 'Print the complete RunPod ↔ Laravel API integration guide with real values';

    // ─────────────────────────────────────────────────────────────────────────

    public function handle(): int
    {
        // ── Load records ─────────────────────────────────────────────────────
        $server = ServerName::find((int) $this->option('server'));
        $model  = AiModel::find((int) $this->option('model'));

        if (! $server) {
            $this->error("Server with ID {$this->option('server')} not found in servers_names.");
            return self::FAILURE;
        }

        if (! $model) {
            $this->error("AI model with ID {$this->option('model')} not found in ai_models.");
            return self::FAILURE;
        }

        $appUrl     = rtrim((string) config('app.url'), '/');
        $gdriveRoot = rtrim((string) config('gdrive.root_folder', 'samples'), '/');
        $modelSlug  = Str::slug($model->name);

        // ── Example path segments (realistic placeholders) ───────────────────
        $exSampleId    = 42;
        $exPx          = 256;
        $exMag         = '20x';
        $exSource      = 'tcga';
        $exCategory    = 'tumor';
        $exCase        = 'TCGA-A1-A0SK';
        $exSlideId     = 'TCGA-A1-A0SK-01Z-00-DX1.svs';
        $exFolder      = "sample_{$exSampleId}_{$exPx}px";

        $inputPath  = "{$gdriveRoot}/sliced_slides/{$exMag}/{$exSource}/{$exCategory}/{$exCase}/{$exFolder}";
        $outputPath = "{$gdriveRoot}/features/{$model->name}/{$exMag}/{$exSource}/{$exCategory}/{$exCase}/{$exFolder}";
        $reportUrl  = "{$appUrl}/api/v1/feature-extraction/report";
        $statusUrl  = "{$appUrl}/api/v1/feature-extraction/jobs/{$exSampleId}";
        $gatewayUrl = rtrim($server->api_url ?? 'NOT_SET', '/') . '/jobs/start';

        // ─────────────────────────────────────────────────────────────────────
        $this->printHeader('RunPod ↔ Laravel API Integration Guide');
        $this->line('  Generated : ' . now()->toDateTimeString());
        $this->line('  Server    : ' . $server->name . ' (ID ' . $server->id . ')');
        $this->line('  AI Model  : ' . $model->name . ' (ID ' . $model->id . ')');
        $this->printDivider();

        // ══ SECTION 0 — All System Keys ══════════════════════════════════════
        $this->printSection('0 — All System Keys & Configuration');

        // Laravel
        $this->line('  <fg=white;options=bold>▶ Laravel</>');
        $this->table(['Variable', 'Value'], [
            ['APP_URL',              env('APP_URL')],
            ['APP_KEY',              env('APP_KEY')],
            ['APP_ENV',              env('APP_ENV')],
        ]);

        // Database
        $this->line('  <fg=white;options=bold>▶ Database</>');
        $this->table(['Variable', 'Value'], [
            ['DB_CONNECTION', env('DB_CONNECTION')],
            ['DB_HOST',       env('DB_HOST')],
            ['DB_PORT',       env('DB_PORT')],
            ['DB_DATABASE',   env('DB_DATABASE')],
            ['DB_USERNAME',   env('DB_USERNAME')],
            ['DB_PASSWORD',   env('DB_PASSWORD') ?: '(empty)'],
        ]);

        // Queue / Cache
        $this->line('  <fg=white;options=bold>▶ Queue & Cache</>');
        $this->table(['Variable', 'Value'], [
            ['QUEUE_CONNECTION', env('QUEUE_CONNECTION')],
            ['CACHE_STORE',      env('CACHE_STORE')],
            ['SESSION_DRIVER',   env('SESSION_DRIVER')],
        ]);

        // Google Drive / rclone
        $this->line('  <fg=white;options=bold>▶ Google Drive / rclone</>');
        $this->table(['Variable', 'Value'], [
            ['GDRIVE_RCLONE_PATH',   env('GDRIVE_RCLONE_PATH')],
            ['GDRIVE_RCLONE_CONFIG', env('GDRIVE_RCLONE_CONFIG')],
            ['GDRIVE_REMOTE_NAME',   env('GDRIVE_REMOTE_NAME')],
            ['GDRIVE_ROOT_FOLDER',   env('GDRIVE_ROOT_FOLDER')],
        ]);

        // Servers from DB
        $this->line('  <fg=white;options=bold>▶ Servers (servers_names table — all)</>');
        $allServers = ServerName::withoutGlobalScopes()->orderBy('id')->get();
        $this->table(
            ['ID', 'Name', 'Type', 'Active', 'API URL', 'API Key', 'RunPod API Key', 'RunPod Volume ID', 'RunPod Template ID'],
            $allServers->map(fn ($s) => [
                $s->id,
                $s->name,
                $s->type,
                $s->is_active ? '<fg=green>yes</>' : '<fg=red>no</>',
                $s->api_url              ?? '—',
                $s->api_key              ?? '—',
                $s->runpod_api_key       ?? '—',
                $s->runpod_network_volume_id ?? '—',
                $s->runpod_template_id   ?? '—',
            ])->toArray()
        );

        // Python / Workers
        $this->line('  <fg=white;options=bold>▶ Processing</>');
        $this->table(['Variable', 'Value'], [
            ['PYTHON_PATH',    env('PYTHON_PATH', 'python')],
            ['PATCH_WORKERS',  env('PATCH_WORKERS', 2)],
        ]);

        // ══ SECTION 1 — Credentials ══════════════════════════════════════════
        $this->printSection('1 — API Credentials (RunPod Integration)');

        $this->table(['Key', 'Value'], [
            ['Laravel Base URL',  $appUrl],
            ['RunPod API URL',    $server->api_url ?? 'NOT SET'],
            ['Bearer Token',      $server->api_key  ?? 'NOT SET'],
            ['Server ID',         $server->id],
        ]);

        $this->line('  <fg=yellow>All API requests in BOTH directions use the same Bearer Token.</>');

        // ══ SECTION 2 — AI Models available ══════════════════════════════════
        $this->printSection('2 — Available AI Models');

        $models = AiModel::where('is_active', true)->orderBy('id')->get();
        $this->table(
            ['ID', 'name', 'provider', 'type', 'huggingface_url', 'version', 'embedding_dim', 'input_resolution', 'default'],
            $models->map(fn ($m) => [
                $m->id,
                $m->name,
                $m->provider       ?? '—',
                $m->model_type     ?? '—',
                $m->huggingface_url ?? '—',
                $m->version        ?? '—',
                $m->embedding_dim  ?? '—',
                $m->input_resolution ?? '—',
                $m->is_default ? '<fg=yellow>YES</>' : '',
            ])->toArray()
        );

        // ══ SECTION 3 — Laravel → RunPod (dispatch) ══════════════════════════
        $this->printSection('3 — Laravel → RunPod  |  Start a job');

        $this->line("  <fg=cyan>POST {$gatewayUrl}</>");
        $this->newLine();
        $this->line('  <fg=white>Headers:</>');
        $this->line("    Authorization : Bearer <fg=green>{$server->api_key}</>  (servers_names.api_key)");
        $this->line('    Content-Type  : application/json');
        $this->line('    Accept        : application/json');
        $this->newLine();
        $this->line('  <fg=white>Payload (JSON):</>');

        $payload = [
            'sample_id'             => $exSampleId,
            'slide_id'              => $exSlideId,
            'patch_size_px'         => $exPx,
            'magnification'         => $exMag,
            'magnification_folder'  => $exMag,
            'gdrive_input_path'     => $inputPath,
            'gdrive_input_archive'  => 'patches.tar.gz',
            'gdrive_output_path'    => $outputPath,
            'ai_model' => [
                'id'               => $model->id,
                'name'             => $model->name,
                'slug'             => $modelSlug,
                'huggingface'      => $model->huggingface_url,
                'version'          => $model->version,
                'embedding_dim'    => $model->embedding_dim,
                'input_resolution' => $model->input_resolution,
            ],
            'callback' => [
                'url'    => $reportUrl,
                'token'  => $server->api_key,
                'method' => 'POST',
            ],
            'dispatched_at' => now()->toIso8601String(),
        ];

        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->newLine();
        $this->line('  <fg=white>Expected 200 response from RunPod:</>');
        $this->line('  { "status": "accepted", "job_id": "runpod-job-abc123" }');
        $this->line('  <fg=red>Any non-2xx → sample.feature_extraction_status = failed</>');

        // ══ SECTION 4 — RunPod → Laravel (callback report) ═══════════════════
        $this->printSection('4 — RunPod → Laravel  |  Status callback');

        $this->line("  <fg=cyan>POST {$reportUrl}</>");
        $this->newLine();
        $this->line('  <fg=white>Headers:</>');
        $this->line("    Authorization : Bearer <fg=green>{$server->api_key}</>");
        $this->line('    Content-Type  : application/json');
        $this->newLine();
        $this->line('  <fg=white>Payload — "processing" ping:</>');
        $this->line(json_encode([
            'sample_id'  => $exSampleId,
            'slide_id'   => $exSlideId,
            'status'     => 'processing',
            'model_name' => $model->name,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->newLine();
        $this->line('  <fg=white>Payload — "completed":</>');
        $this->line(json_encode([
            'sample_id'                 => $exSampleId,
            'slide_id'                  => $exSlideId,
            'status'                    => 'completed',
            'model_name'                => $model->name,
            'runpod_output_path'        => "/workspace/output/features/{$exFolder}",
            'features_gdrive_path'      => $outputPath,
            'features_gdrive_folder_id' => '1aBcDeFgHiJkLmNoPqRsTuVwXyZ',
            'patch_count'               => 1024,
            'failed_patch_count'        => 0,
            'model_version'             => $model->name . '-' . ($model->version ?? 'v1'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->newLine();
        $this->line('  <fg=white>Payload — "failed":</>');
        $this->line(json_encode([
            'sample_id'     => $exSampleId,
            'slide_id'      => $exSlideId,
            'status'        => 'failed',
            'model_name'    => $model->name,
            'error_message' => 'Describe what went wrong here',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->newLine();
        $this->table(['status value', 'Effect on samples table'], [
            ['processing', 'feature_extraction_status = processing'],
            ['completed',  'feature_extraction_status = completed  +  writes all features_* columns'],
            ['failed',     'feature_extraction_status = failed  +  writes feature_extraction_error'],
        ]);

        // ══ SECTION 5 — RunPod → Laravel (read-back status) ══════════════════
        $this->printSection('5 — RunPod → Laravel  |  Query job status (read-back)');

        $this->line("  <fg=cyan>GET {$statusUrl}</>");
        $this->line("    Authorization : Bearer <fg=green>{$server->api_key}</>");
        $this->newLine();
        $this->line('  <fg=white>Example 200 response:</>');
        $this->line(json_encode([
            'success' => true,
            'sample'  => [
                'id'                              => $exSampleId,
                'slide_id'                        => $exSlideId,
                'feature_extraction_status'       => 'completed',
                'feature_extraction_completed_at' => now()->toIso8601String(),
                'features_gdrive_path'            => $outputPath,
                'features_gdrive_folder_id'       => '1aBcDeFgHiJkLmNoPqRsTuVwXyZ',
                'features_runpod_path'            => "/workspace/output/features/{$exFolder}",
                'features_patch_count'            => 1024,
                'features_failed_patch_count'     => 0,
                'features_model_version'          => $model->name . '-' . ($model->version ?? 'v1'),
                'feature_extraction_error'        => null,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // ══ SECTION 6 — rclone ═══════════════════════════════════════════════
        $this->printSection('6 — rclone on RunPod');

        $this->line('  <fg=white>Remote name inside RunPod container:</>  <fg=green>gdrive</>');
        $this->line('  (set via env var: RCLONE_REMOTE=gdrive)');
        $this->newLine();

        // ── Build the rclone.conf for RunPod (remote renamed to [gdrive]) ─────
        $rcloneConfigPath = config('gdrive.rclone_config');
        if ($rcloneConfigPath && file_exists($rcloneConfigPath)) {
            // Read local config and rename remote section to [gdrive] for RunPod
            $localRemote  = config('gdrive.remote_name', 'alhayah');
            $rcloneConf   = file_get_contents($rcloneConfigPath);
            $runpodConf   = preg_replace('/^\[' . preg_quote($localRemote, '/') . '\]/m', '[gdrive]', $rcloneConf);
            $base64Conf   = base64_encode($runpodConf);

            $this->line('  <fg=white;options=bold>── rclone.conf content (remote renamed to [gdrive] for RunPod) ──</>');
            $this->newLine();
            $this->line($runpodConf);
            $this->newLine();
            $this->line('  <fg=white;options=bold>── RCLONE_CONFIG_BASE64  (set this as RunPod env var) ──</>');
            $this->newLine();
            $this->line('<fg=green>' . $base64Conf . '</>');
            $this->newLine();
            $this->line('  <fg=white>Usage — set on RunPod pod / template:</>');
            $this->line('  <fg=yellow>RCLONE_CONFIG_BASE64=' . $base64Conf . '</>');
            $this->newLine();
        } else {
            $this->line('  <fg=red>rclone.conf not found at: ' . ($rcloneConfigPath ?? 'GDRIVE_RCLONE_CONFIG not set') . '</>');
            $this->line('  Run this command on the machine that has the rclone.conf file.');
            $this->newLine();
        }

        $this->line('  <fg=white>Decode rclone config on container start:</>');
        $this->line('  <fg=yellow>echo "$RCLONE_CONFIG_BASE64" | base64 -d > /workspace/rclone.conf</>');
        $this->line('  <fg=yellow>chmod 600 /workspace/rclone.conf</>');
        $this->newLine();
        $this->line('  <fg=white>Step 1 — Pull patches from Google Drive:</>');
        $this->line("  <fg=yellow>rclone copy \\");
        $this->line("    gdrive:{$inputPath}/patches.tar.gz \\");
        $this->line("    /workspace/input/{$exFolder}/ \\");
        $this->line('    --config /workspace/rclone.conf --progress</>');
        $this->newLine();
        $this->line('  <fg=white>Step 2 — Feature extraction runs locally:</>');
        $this->line("  <fg=yellow>/workspace/output/features/{$exFolder}/features.h5</>");
        $this->newLine();
        $this->line('  <fg=white>Step 3 — Push HDF5 back to Google Drive:</>');
        $this->line("  <fg=yellow>rclone sync \\");
        $this->line("    /workspace/output/features/{$exFolder}/ \\");
        $this->line("    gdrive:{$outputPath}/ \\");
        $this->line('    --config /workspace/rclone.conf --progress</>');
        $this->newLine();
        $this->line('  <fg=white>Google Drive output path convention:</>');
        $this->line("  <fg=green>{$gdriveRoot}/features/{model_name}/{magnification}/{data_source}/{category}/{case_id}/sample_{id}_{px}px/</>");

        // ══ SECTION 7 — End-to-end flow ═══════════════════════════════════════
        $this->printSection('7 — End-to-End Flow Summary');

        $this->line("  [Laravel — FeatureExtractionJob]");
        $this->line("    └─► POST <fg=cyan>{$gatewayUrl}</>");
        $this->line("         Bearer <fg=green>{$server->api_key}</>");
        $this->newLine();
        $this->line("  [RunPod Container]");
        $this->line("    ├─ rclone copy  ← gdrive:{$inputPath}/patches.tar.gz");
        $this->line("    ├─ {$model->name}.extract(patches) → /workspace/output/features/{$exFolder}/features.h5");
        $this->line("    ├─ rclone sync  → gdrive:{$outputPath}/");
        $this->line("    └─► POST <fg=cyan>{$reportUrl}</>");
        $this->line("         Bearer <fg=green>{$server->api_key}</>");
        $this->newLine();
        $this->line("  [Laravel — callback received]");
        $this->line("    └─► samples.feature_extraction_status = completed");
        $this->line("         writes features_gdrive_path, features_gdrive_folder_id, features_patch_count …");

        $this->printDivider();
        $this->info('  Tip: php artisan info:runpod-api --server=2 --model=2   ← switch model/server');
        $this->newLine();

        return self::SUCCESS;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function printHeader(string $title): void
    {
        $line = str_repeat('═', 70);
        $this->newLine();
        $this->line("<fg=white;options=bold>{$line}</>");
        $this->line("<fg=white;options=bold>  {$title}</>");
        $this->line("<fg=white;options=bold>{$line}</>");
    }

    private function printSection(string $title): void
    {
        $this->newLine();
        $this->line("<fg=yellow;options=bold>── {$title} " . str_repeat('─', max(0, 65 - strlen($title))) . "</>");
        $this->newLine();
    }

    private function printDivider(): void
    {
        $this->newLine();
        $this->line('<fg=white>' . str_repeat('═', 70) . '</>');
    }
}
