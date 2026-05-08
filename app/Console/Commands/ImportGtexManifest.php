<?php

namespace App\Console\Commands;

use App\Models\ClinicalCaseInformation;
use App\Models\DataSource;
use App\Models\Organ;
use App\Models\PatientCase;
use App\Models\Sample;
use App\Services\CaseLinker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Imports a GTEx Portal CSV export into the histopathology management system.
 *
 * CSV columns expected (GTEx Portal download format):
 *   Tissue Sample ID | Tissue | Subject ID | Sex | Age Bracket | Hardy Scale |
 *   Pathology Categories | Pathology Notes
 *
 * What this command does:
 *   1. For each unique Subject ID  →  upsert PatientCase  +  ClinicalCaseInformation
 *   2. For each row (Tissue Sample ID) →  link existing Sample (matched via
 *      entity_submitter_id) to its PatientCase and update its ClinicalCaseInformation
 *
 * Usage:
 *   php artisan gtex:import-manifest /path/to/GTEx_Portal.csv [--dry-run] [--force-relink]
 */
class ImportGtexManifest extends Command
{
    protected $signature = 'gtex:import-manifest
        {file : Path to the GTEx Portal CSV file}
        {--dry-run : Show what would change without writing to DB}
        {--force-relink : Re-link samples even if already linked to a case}
        {--result-key= : Cache key to store structured results for the web UI}';

    protected $description = 'Import GTEx Portal CSV manifest: create PatientCases, ClinicalCaseInformation, and link Samples';

    // ── Hardy Scale → readable vital status mapping ──────────────────────────
    private const HARDY_MAP = [
        'ventilator case'             => 'Ventilator',
        'fast death - natural causes' => 'Fast death - natural',
        'fast death - violent'        => 'Fast death - violent',
        'intermediate death'          => 'Intermediate death',
        'slow death'                  => 'Slow death',
    ];

    public function handle(CaseLinker $linker): int
    {
        $path   = $this->argument('file');
        $dry    = $this->option('dry-run');
        $force  = $this->option('force-relink');

        if (!file_exists($path)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }

        if ($dry) {
            $this->warn('DRY-RUN mode — no changes will be written.');
        }

        // ── Load / resolve the GTEx DataSource record (auto-create if missing) ─
        $dataSource = DataSource::where('name', 'like', 'GTEx%')->first();
        if (!$dataSource) {
            if ($dry) {
                $this->warn('No DataSource named "GTEx" found — would be auto-created on real run.');
                $dataSource = new DataSource(['id' => 0, 'name' => 'GTEx']);
            } else {
                $dataSource = DataSource::create([
                    'name'        => 'GTEx',
                    'description' => 'Genotype-Tissue Expression (GTEx) Project',
                    'is_active'   => true,
                ]);
                $this->info("Created DataSource: GTEx (id={$dataSource->id})");
            }
        }

        // ── Parse CSV ─────────────────────────────────────────────────────────
        $rows = $this->parseCsv($path);
        if (empty($rows)) {
            $this->error('CSV is empty or could not be parsed.');
            return self::FAILURE;
        }

        $this->info(sprintf('Loaded %d rows from CSV.', count($rows)));

        $casesCreated    = 0;
        $casesUpdated   = 0;
        $samplesLinked  = 0;
        $subjectsSkipped = 0;
        $skippedDetails  = [];   // [ [subject, tissue, tissue_ids[]] ]
        $warnings        = [];

        // Group rows by Subject ID so we only upsert one case per donor
        $bySubject = [];
        foreach ($rows as $row) {
            $subjectId = trim($row['Subject ID'] ?? '');
            if (!$subjectId) continue;
            $bySubject[$subjectId][] = $row;
        }

        $this->info(sprintf('Found %d unique subjects (donors).', count($bySubject)));
        $bar = $this->output->createProgressBar(count($bySubject));
        $bar->start();

        foreach ($bySubject as $subjectId => $subjectRows) {
            // Use the first row for subject-level demographics
            $firstRow  = $subjectRows[0];
            $tissue    = trim($firstRow['Tissue'] ?? '');

            // ── Pre-compute tissue IDs for this subject ────────────────────────
            $tissueIds = array_values(array_filter(array_map(
                fn($r) => trim($r['Tissue Sample ID'] ?? ''),
                $subjectRows
            )));

            // ── Guard: skip subjects with NO existing samples in the DB ────────
            // We only create PatientCase + ClinicalInfo if at least one sample
            // was already uploaded for this donor.
            $hasSample = false;
            foreach ($tissueIds as $tid) {
                if (Sample::where('entity_submitter_id', $tid)
                          ->orWhere('file_name', 'like', $tid . '.%')
                          ->exists()) {
                    $hasSample = true;
                    break;
                }
            }

            if (!$hasSample) {
                $subjectsSkipped++;
                $skippedDetails[] = [
                    'subject'    => $subjectId,
                    'tissue'     => $tissue,
                    'tissue_ids' => $tissueIds,
                ];
                $bar->advance();
                continue;
            }

            // ── Resolve or create PatientCase ─────────────────────────────────
            $organ = $this->resolveOrgan($tissue, $dataSource);

            if (!$dry) {
                $existingCase = PatientCase::where('submitter_id', $subjectId)->first();
                $isNew = $existingCase === null;

                $case = PatientCase::updateOrCreate(
                    ['submitter_id' => $subjectId],
                    [
                        'case_id'        => $subjectId,
                        'project_id'     => 'GTEx',
                        'organ_id'       => $organ?->id,
                        'data_source_id' => $dataSource->id,
                        'primary_site'   => $tissue,
                        'disease_type'   => 'Normal Tissue',
                    ]
                );

                if ($isNew) {
                    $casesCreated++;
                } else {
                    $casesUpdated++;
                }

                // ── Upsert ClinicalCaseInformation ────────────────────────────
                $this->upsertClinical($case, $firstRow);

                // ── Link orphan samples to this case ──────────────────────────
                // Strategy A: CaseLinker (handles both entity_submitter_id and
                // file_name patterns) — covers samples uploaded BEFORE the CSV.
                $linked = $linker->linkCaseToOrphanSamples($case);
                $samplesLinked += $linked;

                // Strategy B: --force-relink: re-link by explicit Tissue Sample ID
                // even if the sample is already linked to some other case.
                if ($force) {
                    foreach ($subjectRows as $row) {
                        $tissueId = trim($row['Tissue Sample ID'] ?? '');
                        if (!$tissueId) continue;
                        $result = $this->linkSample($tissueId, $case, $row, true);
                        if ($result === 'linked') {
                            $samplesLinked++;
                        } elseif (str_starts_with($result, 'warn:')) {
                            $warnings[] = substr($result, 5);
                        }
                    }
                }
            } else {
                // Dry run — estimate counts only
                $exists = PatientCase::where('submitter_id', $subjectId)->exists();
                if ($exists) $casesUpdated++; else $casesCreated++;

                // $tissueIds already pre-computed above (and guard passed)
                foreach ($tissueIds as $tissueId) {
                    $found = \App\Models\Sample::where('entity_submitter_id', $tissueId)
                        ->orWhere('file_name', 'like', $tissueId . '.%')
                        ->exists();
                    if ($found) {
                        $samplesLinked++;
                    } else {
                        $warnings[] = "No sample found for Tissue Sample ID '{$tissueId}'";
                    }
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('── Summary ─────────────────────────────────────────────────────');
        $this->line("  Cases created  : {$casesCreated}");
        $this->line("  Cases updated  : {$casesUpdated}");
        $this->line("  Samples linked : {$samplesLinked}");
        $this->line("  Subjects skipped (no slides uploaded): {$subjectsSkipped}");

        // ── Skipped subjects detail ────────────────────────────────────────────
        if (!empty($skippedDetails)) {
            $this->newLine();
            $this->warn('── Skipped Subjects (' . count($skippedDetails) . ') — No slides found in the system ────────────────');
            $this->warn('   Reason: Case information cannot be imported unless at least one slide (SVS file)');
            $this->warn('           for this donor has been uploaded to the system first.');
            $this->warn('   Action: Upload the corresponding slide files, then re-run this import.');
            $this->newLine();

            foreach ($skippedDetails as $entry) {
                $ids = implode(', ', $entry['tissue_ids']);
                $this->line(
                    sprintf('  <fg=yellow>%-20s</> | %-35s | Tissue IDs: %s',
                        $entry['subject'],
                        $entry['tissue'],
                        $ids
                    )
                );
            }
        }

        if (!empty($warnings)) {
            $this->warn("\n── Warnings (" . count($warnings) . ') ─────────────────────────────────────────────');
            foreach ($warnings as $w) {
                $this->warn("  • {$w}");
            }
        }

        $this->info('Done.');

        // ── Store structured results for web UI (if caller passed a result-key) ─
        if ($resultKey = $this->option('result-key')) {
            Cache::put($resultKey, [
                'dry_run'          => $dry,
                'cases_created'    => $casesCreated,
                'cases_updated'    => $casesUpdated,
                'samples_linked'   => $samplesLinked,
                'skipped_count'    => $subjectsSkipped,
                'skipped_details'  => $skippedDetails,   // [ {subject, tissue, tissue_ids[]} ]
                'warnings'         => $warnings,
            ], now()->addMinutes(10));
        }

        return self::SUCCESS;
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Force-link a specific tissue sample to its PatientCase.
     * Used only when --force-relink is set (already-linked samples).
     *
     * @return 'linked'|'skipped'|'warn:<message>'
     */
    private function linkSample(string $tissueId, PatientCase $case, array $row, bool $force): string
    {
        // Match by entity_submitter_id (GTEx Tissue Sample ID e.g. GTEX-1117F-2826)
        $sample = Sample::where('entity_submitter_id', $tissueId)->first();

        if (!$sample) {
            // Also try matching by file_name prefix (GTEX-1117F-2826.svs)
            $sample = Sample::where('file_name', 'like', $tissueId . '.%')->first();
        }

        if (!$sample) {
            return "warn:No sample found for Tissue Sample ID '{$tissueId}' — import the SVS file first.";
        }

        // Skip if already linked (unless forced)
        if ($sample->case_id && !$force) {
            return 'skipped';
        }

        $sample->case_id = $case->id;

        // Normalise entity_submitter_id to the GTEx Tissue Sample ID
        if ($sample->entity_submitter_id !== $tissueId) {
            $sample->entity_submitter_id = $tissueId;
        }

        $sample->save();
        return 'linked';
    }

    /**
     * Upsert a ClinicalCaseInformation row for the given PatientCase.
     * GTEx has no disease/treatment data — we fill what is available.
     */
    private function upsertClinical(PatientCase $case, array $row): void
    {
        $sex         = strtolower(trim($row['Sex'] ?? ''));
        $ageBracket  = trim($row['Age Bracket'] ?? '');
        $hardy       = strtolower(trim($row['Hardy Scale'] ?? ''));
        $pathCat     = trim($row['Pathology Categories'] ?? '');
        $pathNotes   = trim($row['Pathology Notes'] ?? '');
        $tissue      = trim($row['Tissue'] ?? '');

        // Map age bracket "20-29" to midpoint integer
        $ageAtIndex = null;
        if (preg_match('/(\d+)-(\d+)/', $ageBracket, $m)) {
            $ageAtIndex = (int) round(((int)$m[1] + (int)$m[2]) / 2);
        } elseif (preg_match('/(\d+)\+/', $ageBracket, $m)) {
            $ageAtIndex = (int)$m[1] + 5; // e.g. "70+" → 75
        }

        $vitalStatus = self::HARDY_MAP[$hardy] ?? ($hardy ?: null);

        ClinicalCaseInformation::updateOrCreate(
            ['case_id' => $case->case_id],
            [
                'submitter_id'              => $case->submitter_id,
                'project_id'                => 'GTEx',
                'primary_site'              => $tissue,
                'disease_type'              => 'Normal Tissue',
                'gender'                    => $sex ?: null,
                'age_at_index'              => $ageAtIndex,
                'vital_status'              => $vitalStatus,
                // Pathology stored in diagnosis fields (closest match)
                'diagnosis_submitter_id'    => $pathCat ?: null,
                'primary_diagnosis'         => $pathNotes ?: null,
                'tissue_or_organ_of_origin' => $tissue ?: null,
            ]
        );
    }

    /**
     * Resolve the Organ model from the GTEx tissue label.
     * "Breast - Mammary Tissue" → Organ where name LIKE 'Breast%'
     */
    private function resolveOrgan(string $tissue, DataSource $dataSource): ?Organ
    {
        if (!$tissue) return null;

        // Extract the primary site from "Breast - Mammary Tissue" → "Breast"
        $primary = trim(explode('-', $tissue)[0]);

        return Organ::where('name', 'like', $primary . '%')
                    ->where('is_active', true)
                    ->first();
    }

    /**
     * Parse a CSV file, returning an array of associative rows.
     * Handles BOM, quoted fields, and different line endings.
     *
     * @return array<int, array<string, string>>
     */
    private function parseCsv(string $path): array
    {
        $content = file_get_contents($path);
        if ($content === false) return [];

        // Strip UTF-8 BOM if present
        $content = ltrim($content, "\xEF\xBB\xBF");

        // Normalise line endings
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        $lines = explode("\n", trim($content));
        if (empty($lines)) return [];

        $headers = str_getcsv(array_shift($lines), ',', '"');
        $headers = array_map('trim', $headers);

        $rows = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $cols = str_getcsv($line, ',', '"');
            $row  = [];
            foreach ($headers as $i => $header) {
                $row[$header] = $cols[$i] ?? '';
            }
            $rows[] = $row;
        }

        return $rows;
    }
}
