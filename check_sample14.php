<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$s = \App\Models\Sample::find(14);
if (!$s) { echo "Sample 14 not found\n"; exit(1); }

echo "file_id:         " . ($s->file_id ?? 'NULL') . "\n";
echo "wsi_remote_path: " . ($s->wsi_remote_path ?? 'NULL') . "\n";
echo "storage_path:    " . ($s->storage_path ?? 'NULL') . "\n";
echo "storage_status:  " . ($s->storage_status ?? 'NULL') . "\n";
echo "file_name:       " . ($s->file_name ?? 'NULL') . "\n";
echo "file_size_bytes: " . ($s->file_size_bytes ?? 'NULL') . "\n";

$has_drive_source = $s->file_id || $s->wsi_remote_path || $s->storage_path;
echo "has_drive_source: " . ($has_drive_source ? 'YES → Phase 2 WILL be queued' : 'NO → Phase 2 skipped') . "\n";

// Check pending jobs
$jobs = \Illuminate\Support\Facades\DB::table('jobs')->where('payload', 'like', '%sampleId%14%')->get();
echo "\nJobs in queue for sample 14: " . $jobs->count() . "\n";
foreach ($jobs as $job) {
    $p = json_decode($job->payload, true);
    echo "  Queue: {$job->queue}, Available at: " . date('Y-m-d H:i:s', $job->available_at) . "\n";
}

$failedJobs = \Illuminate\Support\Facades\DB::table('failed_jobs')->where('payload', 'like', '%14%')->get();
echo "\nFailed jobs: " . $failedJobs->count() . "\n";
foreach ($failedJobs as $fj) {
    echo "  Exception: " . substr($fj->exception, 0, 200) . "\n";
}
