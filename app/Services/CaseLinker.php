<?php

namespace App\Services;

use App\Models\PatientCase;
use App\Models\Sample;
use Illuminate\Support\Facades\Log;

/**
 * Single source of truth for slide ↔ case linkage.
 *
 * Used by every entry point that creates/updates a Sample or PatientCase,
 * to guarantee bidirectional reconciliation regardless of upload order.
 *
 * TCGA matching strategy:
 *   1) Sample.entity_submitter_id  →  first 3 hyphen-segments  →  cases.submitter_id
 *      (e.g. "TCGA-BH-A203-11A-04-TSD"  →  "TCGA-BH-A203")
 *   2) Sample.file_name (TCGA pattern) → same reduction → cases.submitter_id
 *      Used as a fallback when entity_submitter_id was set to a synthetic
 *      folder-path string by a bulk upload before the proper extractor was
 *      applied.
 *
 * Idempotent — safe to call multiple times. No-op if sample is already linked.
 */
class CaseLinker
{
    private const TCGA_PATTERN =
        '/^([A-Z0-9]+-[A-Z0-9]+-[A-Z0-9]+(?:-[A-Z0-9]+(?:-\d+)?(?:-[A-Z0-9]+)?)?)/i';

    /**
     * Attempt to link a single Sample to its PatientCase.
     * Returns true when case_id was newly assigned, false otherwise.
     */
    public function linkSampleToCase(Sample $sample): bool
    {
        // Always work with fresh DB state.
        $sample->refresh();

        if ($sample->case_id) {
            return false;
        }

        // ── Strategy 1: existing entity_submitter_id ─────────────────────────
        $patientSub = $this->reduceToPatientSubmitter($sample->entity_submitter_id);
        if ($patientSub) {
            $case = PatientCase::where('submitter_id', $patientSub)->first();
            if ($case) {
                $sample->case_id = $case->id;
                $sample->save();
                Log::info(
                    "[CaseLinker] Sample #{$sample->id} → case #{$case->id} ({$case->submitter_id}) via entity_submitter_id"
                );
                return true;
            }
        }

        // ── Strategy 2: derive TCGA submitter from file_name ─────────────────
        $entitySub = $this->extractTcgaSubmitterId($sample->file_name);
        if (!$entitySub) {
            return false;
        }

        $patientSub = $this->reduceToPatientSubmitter($entitySub);
        if (!$patientSub) {
            return false;
        }

        $case = PatientCase::where('submitter_id', $patientSub)->first();
        if (!$case) {
            return false;
        }

        $sample->case_id = $case->id;
        // Normalize entity_submitter_id when it was a synthetic folder string.
        if ($sample->entity_submitter_id !== $entitySub) {
            $sample->entity_submitter_id = $entitySub;
        }
        $sample->save();
        Log::info(
            "[CaseLinker] Sample #{$sample->id} → case #{$case->id} ({$case->submitter_id}) via file_name"
        );
        return true;
    }

    /**
     * Reverse direction: a PatientCase has just been created / updated —
     * find every orphan Sample whose entity_submitter_id (or file_name)
     * reduces to this case's submitter_id and link them.
     *
     * Returns the number of samples linked.
     */
    public function linkCaseToOrphanSamples(PatientCase $case): int
    {
        if (!$case->submitter_id) {
            return 0;
        }

        $linked  = 0;
        $orphans = Sample::whereNull('case_id')
            ->where(function ($q) {
                $q->whereNotNull('entity_submitter_id')
                  ->orWhereNotNull('file_name');
            })
            ->get();

        foreach ($orphans as $sample) {
            // Try Strategy 1 first
            $patientSub = $this->reduceToPatientSubmitter($sample->entity_submitter_id);
            if ($patientSub === $case->submitter_id) {
                $sample->case_id = $case->id;
                $sample->save();
                Log::info(
                    "[CaseLinker] Reverse-link: case #{$case->id} ← sample #{$sample->id} (entity_submitter_id)"
                );
                $linked++;
                continue;
            }

            // Strategy 2: file_name fallback
            $entitySub = $this->extractTcgaSubmitterId($sample->file_name);
            if (!$entitySub) continue;
            $patientSub = $this->reduceToPatientSubmitter($entitySub);
            if ($patientSub !== $case->submitter_id) continue;

            $sample->case_id = $case->id;
            if ($sample->entity_submitter_id !== $entitySub) {
                $sample->entity_submitter_id = $entitySub;
            }
            $sample->save();
            Log::info(
                "[CaseLinker] Reverse-link: case #{$case->id} ← sample #{$sample->id} (file_name)"
            );
            $linked++;
        }

        return $linked;
    }

    /**
     * Full sweep: reconcile every orphan sample against every case.
     * Use sparingly — meant for end-of-batch consistency passes
     * (e.g. after a bulk import touches many cases at once).
     *
     * Returns the number of samples newly linked.
     */
    public function reconcileAllOrphans(): int
    {
        $linked  = 0;
        $orphans = Sample::whereNull('case_id')->get();

        foreach ($orphans as $sample) {
            if ($this->linkSampleToCase($sample)) {
                $linked++;
            }
        }
        return $linked;
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * "TCGA-BH-A203-11A-04-TSD"  →  "TCGA-BH-A203"
     */
    private function reduceToPatientSubmitter(?string $entitySub): ?string
    {
        if (!$entitySub) return null;
        $parts = explode('-', $entitySub);
        if (count($parts) < 3) return null;
        return implode('-', array_slice($parts, 0, 3));
    }

    /**
     * "TCGA-BH-A203-11A-04-TSD.45eca4c3-….svs"  →  "TCGA-BH-A203-11A-04-TSD"
     */
    private function extractTcgaSubmitterId(?string $filename): ?string
    {
        if (!$filename) return null;
        return preg_match(self::TCGA_PATTERN, $filename, $m) ? $m[1] : null;
    }
}
