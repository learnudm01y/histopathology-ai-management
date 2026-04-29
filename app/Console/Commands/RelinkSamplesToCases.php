<?php

namespace App\Console\Commands;

use App\Models\PatientCase;
use App\Models\Sample;
use Illuminate\Console\Command;

/**
 * Repairs linkage between samples (slides) and clinical case records.
 *
 * This is needed for samples that were imported via bulk folder upload before
 * the GDC UUID / entity_submitter_id extraction was fixed, resulting in:
 *   - samples.file_id = Google Drive file ID instead of GDC file UUID
 *   - samples.entity_submitter_id = synthetic folder-path string instead of TCGA entity ID
 *   - samples.case_id = NULL (not linked to any clinical case)
 *
 * The command runs three passes:
 *  Pass 1 – Fix file_id: if bulk_folder_original_path is a GDC UUID and file_id is not,
 *            update file_id to the GDC UUID.
 *  Pass 2 – Fix entity_submitter_id: extract TCGA entity submitter from file_name.
 *  Pass 3 – Link case_id: match by entity_submitter_id → cases.submitter_id (first 3 segments).
 */
class RelinkSamplesToCases extends Command
{
    protected $signature   = 'samples:relink-cases {--dry-run : Show what would change without writing to DB}';
    protected $description = 'Repair GDC file_id / entity_submitter_id / case_id linkage for bulk-uploaded samples';

    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function handle(): int
    {
        $dry = $this->option('dry-run');
        if ($dry) {
            $this->warn('DRY-RUN mode — no changes will be written.');
        }

        $fixedFileId    = 0;
        $fixedSubmitter = 0;
        $linked         = 0;

        // ── Pass 1: Fix file_id from bulk_folder_original_path ───────────────
        $this->line('Pass 1: fixing file_id from bulk_folder_original_path …');

        $samples = Sample::whereNotNull('bulk_folder_original_path')->get();
        foreach ($samples as $sample) {
            $folderUuid = $sample->bulk_folder_original_path;
            if (!preg_match(self::UUID_PATTERN, $folderUuid)) continue;

            // Already correct
            if ($sample->file_id && preg_match(self::UUID_PATTERN, $sample->file_id)) continue;

            $this->line("  Sample #{$sample->id}: file_id '{$sample->file_id}' → '{$folderUuid}'");
            if (!$dry) {
                $sample->file_id = strtolower($folderUuid);
                $sample->save();
            }
            $fixedFileId++;
        }

        // ── Pass 2: Fix entity_submitter_id from file_name ──────────────────
        $this->line('Pass 2: fixing entity_submitter_id from file_name …');

        $samples = Sample::whereNotNull('file_name')->get();
        foreach ($samples as $sample) {
            $proper = $this->extractTcgaSubmitterId($sample->file_name);
            if (!$proper) continue;
            if ($sample->entity_submitter_id === $proper) continue;

            $this->line("  Sample #{$sample->id}: entity_submitter_id '{$sample->entity_submitter_id}' → '{$proper}'");
            if (!$dry) {
                $sample->entity_submitter_id = $proper;
                $sample->save();
            }
            $fixedSubmitter++;
        }

        // ── Pass 3: Link case_id ─────────────────────────────────────────────
        $this->line('Pass 3: linking case_id via entity_submitter_id / file_name …');

        // Sub-pass A: via entity_submitter_id (may now be fixed by Pass 2)
        $orphans = Sample::whereNull('case_id')->whereNotNull('entity_submitter_id')->get();
        foreach ($orphans as $sample) {
            $sub = $this->extractPatientSubmitter($sample->entity_submitter_id);
            if (!$sub) continue;

            $case = PatientCase::where('submitter_id', $sub)->first();
            if (!$case) continue;

            $this->line("  Sample #{$sample->id} ('{$sample->file_name}'): case_id → #{$case->id} ({$case->submitter_id})");
            if (!$dry) {
                $sample->case_id = $case->id;
                $sample->save();
            }
            $linked++;
        }

        // Sub-pass B: via file_name for any still-unlinked samples
        $remaining = Sample::whereNull('case_id')->whereNotNull('file_name')->get();
        foreach ($remaining as $sample) {
            $entitySub = $this->extractTcgaSubmitterId($sample->file_name);
            if (!$entitySub) continue;

            $sub = $this->extractPatientSubmitter($entitySub);
            if (!$sub) continue;

            $case = PatientCase::where('submitter_id', $sub)->first();
            if (!$case) continue;

            $this->line("  Sample #{$sample->id} ('{$sample->file_name}'): case_id → #{$case->id} ({$case->submitter_id}) [via file_name]");
            if (!$dry) {
                $sample->case_id      = $case->id;
                // Also correct entity_submitter_id while we're here
                $sample->entity_submitter_id = $entitySub;
                $sample->save();
            }
            $linked++;
        }

        $this->info("Done. file_id fixed: {$fixedFileId} | entity_submitter_id fixed: {$fixedSubmitter} | cases linked: {$linked}");

        return Command::SUCCESS;
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Extracts TCGA slide entity submitter from a WSI filename.
     * e.g. "TCGA-BH-A203-11A-04-TSD.45eca4c3-....svs" → "TCGA-BH-A203-11A-04-TSD"
     */
    private function extractTcgaSubmitterId(?string $filename): ?string
    {
        if (!$filename) return null;
        if (!preg_match('/^([A-Z0-9]+-[A-Z0-9]+-[A-Z0-9]+(?:-[A-Z0-9]+(?:-\d+)?(?:-[A-Z0-9]+)?)?)/i', $filename, $m)) {
            return null;
        }
        return $m[1];
    }

    /**
     * Reduces "TCGA-BH-A203-11A-04-TSD" → "TCGA-BH-A203" (first 3 hyphen segments).
     */
    private function extractPatientSubmitter(?string $entitySub): ?string
    {
        if (!$entitySub) return null;
        $parts = explode('-', $entitySub);
        if (count($parts) < 3) return null;
        return implode('-', array_slice($parts, 0, 3));
    }
}
