<?php

namespace App\Console\Commands;

use App\Models\Sample;
use App\Services\GoogleDriveService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * php artisan wsi:enrich-metadata [--limit=100] [--dry-run] [--force]
 *
 * Backfills missing file metadata for samples that are already on Google Drive
 * but whose file_size_bytes, file_size_gb, or storage_link were never populated
 * (typically because they were uploaded before the metadata-enrichment code was added).
 *
 * For each qualifying sample it calls rclone lsjson once to get:
 *   • Size  → samples.file_size_bytes  +  samples.file_size_gb
 *   • ID    → samples.storage_link  (https://drive.google.com/file/d/{ID}/view)
 *
 * One rclone call per sample — no sharing-permission changes.
 */
class EnrichWsiMetadata extends Command
{
    protected $signature = 'wsi:enrich-metadata
                            {--limit=100 : Maximum number of samples to process per run}
                            {--dry-run   : Show what would be updated without writing to the DB}
                            {--force     : Re-fetch even for samples that already have size/link}';

    protected $description = 'Backfill file_size_bytes, file_size_gb, and storage_link from Google Drive metadata';

    public function handle(GoogleDriveService $drive): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit  = (int)  $this->option('limit');
        $force  = (bool) $this->option('force');

        $this->line('');
        $this->line('╔══════════════════════════════════════════════╗');
        $this->line('║     wsi:enrich-metadata — metadata backfill  ║');
        $this->line('╚══════════════════════════════════════════════╝');

        if ($dryRun) {
            $this->warn('  ⚠  DRY-RUN — no changes will be written.');
        }

        // ── Find candidates ────────────────────────────────────────────────────
        $query = Sample::whereNotNull('wsi_remote_path');

        if (!$force) {
            // Only samples missing at least one enrichable field
            $query->where(function ($q) {
                $q->whereNull('file_size_bytes')
                  ->orWhereNull('storage_link');
            });
        }

        $total = $query->count();
        $this->info("  Candidates : {$total}" . ($force ? ' (--force: re-fetching all)' : ' (missing size or link)'));
        $this->info("  Processing : up to {$limit} this run");
        $this->line('');

        if ($total === 0) {
            $this->info('  Nothing to enrich — all samples already have file size and Drive link.');
            return self::SUCCESS;
        }

        $samples   = $query->limit($limit)->get(['id', 'wsi_remote_path', 'file_name', 'file_size_bytes', 'storage_link']);
        $updated   = 0;
        $notFound  = 0;
        $errors    = 0;

        $bar = $this->output->createProgressBar($samples->count());
        $bar->start();

        foreach ($samples as $sample) {
            $bar->advance();

            try {
                $meta = $drive->fetchFileMeta($sample->wsi_remote_path);

                if (empty($meta['Name'])) {
                    // File not found on Drive — skip; wsi:scan-and-upload handles re-uploads
                    $notFound++;
                    Log::warning("[EnrichWsiMetadata] Sample #{$sample->id}: not found on Drive at {$sample->wsi_remote_path}");
                    continue;
                }

                $changes = [];

                if (!empty($meta['Size']) && ($force || $sample->file_size_bytes === null)) {
                    $bytes              = (int) $meta['Size'];
                    $changes['file_size_bytes'] = $bytes;
                    $changes['file_size_gb']    = round($bytes / (1024 ** 3), 3);
                }

                if (!empty($meta['ID']) && ($force || empty($sample->storage_link))) {
                    $changes['storage_link'] = 'https://drive.google.com/file/d/' . $meta['ID'] . '/view';
                }

                if (empty($changes)) {
                    continue;
                }

                if (!$dryRun) {
                    Sample::where('id', $sample->id)->update($changes);
                }

                $updated++;

                Log::info(sprintf(
                    '[EnrichWsiMetadata] Sample #%d: %s',
                    $sample->id,
                    implode(', ', array_map(
                        fn($k, $v) => "{$k}={$v}",
                        array_keys($changes),
                        $changes
                    )),
                ));

            } catch (\Throwable $e) {
                $errors++;
                Log::error("[EnrichWsiMetadata] Sample #{$sample->id}: error — {$e->getMessage()}");
            }
        }

        $bar->finish();
        $this->line('');
        $this->line('');

        // ── Summary ────────────────────────────────────────────────────────────
        $this->table(
            ['Metric', 'Count'],
            [
                ['Updated (size + link filled)'   , $dryRun ? "{$updated} (dry-run — not written)" : $updated],
                ['Not found on Drive (skipped)'   , $notFound],
                ['Errors'                          , $errors],
                ['Remaining after this run'        , max(0, $total - $samples->count())],
            ],
        );

        if ($total > $limit) {
            $this->warn("  {$total} candidates total — run again to process the next batch of {$limit}.");
        }

        return self::SUCCESS;
    }
}
