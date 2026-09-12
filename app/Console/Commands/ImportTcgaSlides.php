<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\DataSource;
use App\Models\DiseaseSubtype;
use App\Models\Organ;
use App\Models\Sample;
use App\Models\Stain;
use App\Services\CaseLinker;
use App\Services\GoogleDriveService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Fetches slides named in a GDC manifest and files them: download from GDC,
 * verify the checksum, upload to Drive, create the sample row, link the case.
 *
 *   php artisan tcga:import-slides manifest.tsv --subtype=IDC --dry-run
 *   php artisan tcga:import-slides manifest.tsv --subtype=IDC --limit=20
 *
 * The manifest is the tab-separated form gdc-client expects — the same file the
 * GDC portal hands out:
 *
 *     id  filename  md5  size  state
 *
 * ── Why this is a command and not a one-off script ──────────────────────────
 *
 * A slide is not filed until four things agree: the bytes on Drive, the row in
 * `samples`, the classification on that row, and the link to its case. Doing
 * those by hand across a hundred slides is how half-filed slides appear — on
 * Drive with no row, or with a row whose Drive path points nowhere. Each slide
 * here either completes all four or is left untouched for the next run.
 *
 * ── Re-running is the normal case ───────────────────────────────────────────
 *
 * Every slide is keyed on `samples.file_id`, the GDC file UUID, so a slide
 * already filed is skipped. That makes the command the unit of retry: run it
 * with --limit to take the manifest in bites, and run it again after a failure
 * or a dropped connection. Nothing is duplicated and nothing is redone.
 *
 * A slide whose bytes reached Drive but whose row was never written — the shape
 * an interrupted run leaves behind — is detected by size and skips straight to
 * the row, without paying for the transfer twice.
 *
 * ── Disk ────────────────────────────────────────────────────────────────────
 *
 * The staging directory holds one slide at a time: each is deleted as soon as
 * Drive confirms it, so a 100 GB manifest never needs more than the largest
 * single slide. --keep-local turns that off for debugging, and will fill the
 * disk if you let it.
 */
class ImportTcgaSlides extends Command
{
    protected $signature = 'tcga:import-slides
        {manifest : Path to the GDC manifest TSV (id/filename/md5/size/state)}
        {--subtype=IDC : disease_subtypes.name to classify these slides under}
        {--organ=Breast : organs.name}
        {--category=tumor : categories.label_en}
        {--source=TCGA-BRCA : data_sources.name}
        {--limit=0 : Stop after this many newly filed slides (0 = the whole manifest)}
        {--staging=/var/www/HISTO_AI/tcga_staging : Local scratch directory}
        {--dry-run : Report what would happen; download nothing, write nothing}
        {--keep-local : Do not delete the local file after upload}';

    protected $description = 'Download slides from a GDC manifest, upload to Drive, and file them as samples';

    /** Slides smaller than this are treated as truncated downloads, not slides. */
    private const MIN_FILE_BYTES = 50 * 1024 * 1024;

    private array $tally = [
        'rows' => 0, 'filed' => 0, 'already' => 0,
        'recovered' => 0, 'failed' => 0, 'skipped' => 0,
    ];

    public function handle(GoogleDriveService $drive, CaseLinker $linker): int
    {
        $manifestPath = $this->argument('manifest');
        $dry          = (bool) $this->option('dry-run');
        $limit        = (int) $this->option('limit');
        $staging      = rtrim($this->option('staging'), '/');

        if (! is_readable($manifestPath)) {
            $this->error("Manifest not readable: {$manifestPath}");
            return self::FAILURE;
        }

        $rows = $this->parseManifest($manifestPath);
        if ($rows === []) {
            $this->error('Manifest parsed to zero usable rows — check the header line.');
            return self::FAILURE;
        }

        // Resolve the classification once. A missing reference row is a
        // configuration error, not something to guess around per slide.
        try {
            $refs = $this->resolveReferences();
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->line('');
        $this->info('  tcga:import-slides');
        $this->line('  ────────────────────────────────────────────────');
        $this->line(sprintf('  manifest     %s (%d rows)', basename($manifestPath), count($rows)));
        $this->line(sprintf('  filing as    %s / %s / %s / %s',
            $refs['source']->name, $refs['organ']->name, $refs['category']->label_en, $refs['subtype']->name));
        $this->line(sprintf('  staging      %s', $staging));
        if ($limit > 0) {
            $this->line(sprintf('  limit        %d newly filed slides', $limit));
        }
        if ($dry) {
            $this->warn('  DRY RUN — nothing is downloaded and nothing is written.');
        }
        $this->line('');

        if (! $dry && ! is_dir($staging) && ! mkdir($staging, 0775, true) && ! is_dir($staging)) {
            $this->error("Cannot create staging directory: {$staging}");
            return self::FAILURE;
        }

        foreach ($rows as $row) {
            $this->tally['rows']++;

            if ($limit > 0 && $this->tally['filed'] >= $limit) {
                $this->tally['skipped']++;
                continue;
            }

            $this->fileOneSlide($row, $refs, $drive, $linker, $staging, $dry);
        }

        $this->printSummary($dry);

        return $this->tally['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Take one manifest row all the way, or leave it entirely alone.
     */
    private function fileOneSlide(
        array $row,
        array $refs,
        GoogleDriveService $drive,
        CaseLinker $linker,
        string $staging,
        bool $dry
    ): void {
        $fileId   = $row['id'];
        $fileName = $row['filename'];
        $label    = substr($fileName, 0, 24);

        if (Sample::where('file_id', $fileId)->exists()) {
            $this->tally['already']++;
            $this->line(sprintf('  <fg=gray>·</> %-24s already filed', $label));
            return;
        }

        $entitySub  = $this->submitterFromFilename($fileName);
        $folderPath = sprintf(
            '%s/%s/%s/%s/%s',
            config('gdrive.root_folder', 'samples'),
            $refs['source']->name,
            $refs['organ']->name,
            $refs['category']->label_en,
            $fileId
        );

        if ($dry) {
            $this->line(sprintf('  <fg=cyan>?</> %-24s → %s', $label, $folderPath));
            $this->tally['filed']++;
            return;
        }

        try {
            // A previous run may have uploaded the bytes and died before writing
            // the row. Trust Drive's own size, not our assumption.
            $meta = $drive->fetchFileMeta($folderPath . '/' . $fileName);
            $onDriveAlready = isset($meta['ID']) && (int) ($meta['Size'] ?? 0) === $row['size'];

            if ($onDriveAlready) {
                $this->tally['recovered']++;
                $this->line(sprintf('  <fg=yellow>↻</> %-24s already on Drive — writing the row only', $label));
            } else {
                $localPath = $this->download($fileId, $fileName, $staging);
                $this->verifyChecksum($localPath, $row);

                $meta = $drive->uploadLocalFile($localPath, $folderPath);
                if (! isset($meta['ID'])) {
                    throw new \RuntimeException('Drive returned no file id after upload');
                }

                if (! $this->option('keep-local')) {
                    @unlink($localPath);
                    @rmdir(dirname($localPath));
                }
            }

            $sample = $this->createSample($row, $entitySub, $folderPath, $meta, $refs);
            $linked = $linker->linkSampleToCase($sample);

            $this->tally['filed']++;
            $this->line(sprintf(
                '  <fg=green>✓</> %-24s #%-6d %s',
                $label,
                $sample->id,
                $linked ? 'linked to its case' : '<fg=yellow>no case yet</>'
            ));
        } catch (\Throwable $e) {
            $this->tally['failed']++;
            $this->line(sprintf('  <fg=red>✗</> %-24s %s', $label, $e->getMessage()));
            Log::error("[tcga:import-slides] {$fileId}: {$e->getMessage()}");
        }
    }

    /**
     * Pull one file with gdc-client into its own directory, which is how
     * gdc-client lays things out: {staging}/{file_id}/{filename}.
     */
    private function download(string $fileId, string $fileName, string $staging): string
    {
        $expected = "{$staging}/{$fileId}/{$fileName}";
        if (is_file($expected) && filesize($expected) >= self::MIN_FILE_BYTES) {
            return $expected;   // left behind by an interrupted run
        }

        $proc = new Process(
            ['gdc-client', 'download', $fileId, '-d', $staging, '--retry-amount', '3'],
            timeout: 7200
        );
        $proc->run();

        if (! $proc->isSuccessful() || ! is_file($expected)) {
            throw new \RuntimeException('gdc-client failed: ' . trim($proc->getErrorOutput() ?: $proc->getOutput()));
        }

        return $expected;
    }

    /**
     * The checksum is the only thing that proves the bytes we hold are the bytes
     * GDC published. A slide that fails here must not reach Drive, because from
     * that point on nothing downstream would ever question it again.
     */
    private function verifyChecksum(string $localPath, array $row): void
    {
        $size = filesize($localPath);
        if ($size < self::MIN_FILE_BYTES) {
            throw new \RuntimeException("File is only {$size} bytes — truncated download");
        }
        if ($row['size'] > 0 && $size !== $row['size']) {
            throw new \RuntimeException("Size mismatch: got {$size}, manifest says {$row['size']}");
        }
        if ($row['md5'] !== '' && md5_file($localPath) !== $row['md5']) {
            throw new \RuntimeException('md5 mismatch against the manifest');
        }
    }

    private function createSample(
        array $row,
        ?string $entitySub,
        string $folderPath,
        array $meta,
        array $refs
    ): Sample {
        $driveId = $meta['ID'] ?? null;

        return Sample::create([
            'organ_id'           => $refs['organ']->id,
            'data_source_id'     => $refs['source']->id,
            'category_id'        => $refs['category']->id,
            'disease_subtype_id' => $refs['subtype']->id,
            'stain_id'           => $refs['stain']?->id,

            'file_id'            => $row['id'],
            'gdrive_source_id'   => $driveId,
            'file_name'          => $row['filename'],
            'md5sum'             => $row['md5'] ?: null,
            'md5_verified'       => $row['md5'] !== '',
            'file_size_bytes'    => $row['size'] ?: null,
            'file_size_gb'       => $row['size'] ? round($row['size'] / 1073741824, 3) : null,

            'data_format'         => 'SVS',
            'access_level'        => 'open',
            'gdc_state'           => $row['state'] ?: 'released',
            'entity_submitter_id' => $entitySub,
            'entity_type'         => 'slide',
            'disease_subtype'     => $refs['subtype']->name,
            'tissue_name'         => sprintf('/%s/%s/%s/',
                $refs['source']->name, $refs['category']->label_en, $entitySub ?? 'unknown'),

            'storage_path'              => $folderPath,
            'wsi_remote_path'           => $folderPath . '/' . $row['filename'],
            'storage_link'              => $driveId ? "https://drive.google.com/open?id={$driveId}" : null,
            'upload_type'               => 'bulk',
            'bulk_folder_original_path' => $row['id'],
            'storage_status'            => 'available',
            'download_completed_at'     => now(),

            // Patching and verification decide these for themselves later; the
            // defaults below only say which scale this slide is destined for.
            'tile_size_px'   => 224,
            'magnification'  => '20x',
            'is_usable'      => true,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return array<int, array{id:string, filename:string, md5:string, size:int, state:string}>
     */
    private function parseManifest(string $path): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim((string) file_get_contents($path)));
        if (count($lines) < 2) return [];

        $header = array_map(
            static fn ($h) => strtolower(trim($h, " \t\"\xEF\xBB\xBF")),
            explode("\t", array_shift($lines))
        );
        $col = array_flip($header);

        if (! isset($col['id'], $col['filename'])) return [];

        $out = [];
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $c = explode("\t", $line);

            $id = trim($c[$col['id']] ?? '');
            if ($id === '') continue;

            $out[] = [
                'id'       => $id,
                'filename' => trim($c[$col['filename']] ?? ''),
                'md5'      => isset($col['md5'])   ? trim($c[$col['md5']]   ?? '') : '',
                'size'     => isset($col['size'])  ? (int) trim($c[$col['size']] ?? '0') : 0,
                'state'    => isset($col['state']) ? trim($c[$col['state']] ?? '') : '',
            ];
        }

        return $out;
    }

    /**
     * "TCGA-AC-A23C-01Z-00-DX1.0E67….svs" → "TCGA-AC-A23C-01Z-00-DX1"
     */
    private function submitterFromFilename(string $fileName): ?string
    {
        return preg_match('/^(TCGA(?:-[A-Z0-9]+){5})/i', $fileName, $m)
            ? strtoupper($m[1])
            : null;
    }

    /**
     * @return array{source:DataSource, organ:Organ, category:Category, subtype:DiseaseSubtype, stain:?Stain}
     */
    private function resolveReferences(): array
    {
        $source = DataSource::where('name', $this->option('source'))->first();
        $organ  = Organ::where('name', $this->option('organ'))->first();
        $subtype = DiseaseSubtype::where('name', $this->option('subtype'))->first();

        $category = $organ
            ? Category::where('label_en', $this->option('category'))->where('organ_id', $organ->id)->first()
            : null;

        foreach ([
            'data source' => [$source, $this->option('source')],
            'organ'       => [$organ, $this->option('organ')],
            'category'    => [$category, $this->option('category')],
            'subtype'     => [$subtype, $this->option('subtype')],
        ] as $what => [$model, $given]) {
            if (! $model) {
                throw new \RuntimeException("No {$what} named '{$given}' — refusing to guess a classification.");
            }
        }

        return [
            'source'   => $source,
            'organ'    => $organ,
            'category' => $category,
            'subtype'  => $subtype,
            'stain'    => Stain::first(),
        ];
    }

    private function printSummary(bool $dry): void
    {
        $this->line('');
        $this->line('  ────────────────────────────────────────────────');
        $this->line(sprintf('  manifest rows       %d', $this->tally['rows']));
        $this->line(sprintf('  %s  %d', $dry ? 'would file         ' : 'filed              ', $this->tally['filed']));
        if ($this->tally['recovered']) {
            $this->line(sprintf('    of which recovered from a half-done run  %d', $this->tally['recovered']));
        }
        $this->line(sprintf('  already filed       %d', $this->tally['already']));
        if ($this->tally['skipped']) {
            $this->line(sprintf('  left for next run   %d', $this->tally['skipped']));
        }
        if ($this->tally['failed']) {
            $this->error(sprintf('  failed              %d  — see storage/logs', $this->tally['failed']));
        }
        $this->line('');
    }
}
