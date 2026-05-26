<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 *  INTEGRITY FIX — slide_verifications table
 * ═══════════════════════════════════════════════════════════════
 *
 * ROOT CAUSE OF THE BUG (48,000 Duplicate Entry errors):
 * ────────────────────────────────────────────────────────────────
 * 1. Early code inserted records with slide_id set but sample_id = NULL.
 * 2. Later code tried to clean them up with:
 *       WHERE slide_id = 'X' AND sample_id != $id
 *    In MySQL, (NULL != 5) evaluates to NULL — not TRUE.
 *    So orphan rows with sample_id = NULL were NEVER deleted.
 * 3. The subsequent INSERT tried to write a new row with the same
 *    slide_id → UNIQUE constraint violation → job crash → retry →
 *    same crash again → 48,000 errors accumulated in the log.
 *
 * This migration:
 *   A. Rescues orphan rows by linking them to their correct sample.
 *   B. Deletes any remaining orphans that could not be rescued.
 *   C. Removes any duplicate non-null sample_id rows (keeps newest).
 *   D. Adds a UNIQUE index on sample_id so the database itself
 *      prevents this class of bug from ever occurring again.
 *      (MySQL UNIQUE indexes ignore NULL values, so nullable
 *       sample_id for soft-deleted samples still works correctly.)
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── A. Rescue: link orphan rows to their correct sample ──────────────
        // An orphan has slide_id set but sample_id = NULL.
        // If a Sample with entity_submitter_id = slide_id exists AND
        // that sample does not already have its own verification row,
        // we can safely adopt the orphan by filling in its sample_id.
        // MySQL error 1093: cannot reference the UPDATE target table directly
        // in a subquery. Workaround: wrap in a derived table so MySQL treats
        // it as a separate temporary result and allows the self-join.
        DB::statement("
            UPDATE slide_verifications sv
            INNER JOIN samples s
                ON s.entity_submitter_id = sv.slide_id
            SET sv.sample_id = s.id
            WHERE sv.sample_id IS NULL
              AND sv.slide_id  IS NOT NULL
              AND s.id NOT IN (
                  SELECT sample_id FROM (
                      SELECT sample_id
                      FROM   slide_verifications
                      WHERE  sample_id IS NOT NULL
                  ) AS existing_samples
              )
        ");

        // ── B. Delete: remaining orphans with sample_id = NULL ───────────────
        // These are rows that either had no matching Sample, or whose
        // slide_id conflicted with an already-existing sample-keyed row.
        // Keeping them would cause every future INSERT to fail with
        // a UNIQUE constraint violation on slide_id.
        DB::table('slide_verifications')
            ->whereNull('sample_id')
            ->delete();

        // ── C. Deduplicate: remove extra rows for the same sample_id ─────────
        // Keep the row with the highest id (most recently created/updated).
        DB::statement("
            DELETE sv1
            FROM   slide_verifications sv1
            INNER JOIN slide_verifications sv2
                ON  sv1.sample_id = sv2.sample_id
                AND sv1.id        < sv2.id
            WHERE sv1.sample_id IS NOT NULL
        ");

        // ── D. Enforce: add UNIQUE constraint on sample_id ───────────────────
        // From this point forward, the database will reject any attempt to
        // create two verification rows for the same sample, at the storage
        // engine level — regardless of what the application code does.
        //
        // MySQL UNIQUE indexes treat NULL as distinct, so multiple rows with
        // sample_id = NULL are still permitted (for future soft-deleted samples).
        Schema::table('slide_verifications', function (Blueprint $table) {
            // Guard: only add if it doesn't already exist (idempotent).
            $indexes = collect(DB::select("SHOW INDEX FROM slide_verifications"))
                ->pluck('Key_name')
                ->toArray();

            if (!in_array('slide_verifications_sample_id_unique', $indexes, true)) {
                $table->unique('sample_id', 'slide_verifications_sample_id_unique');
            }
        });
    }

    public function down(): void
    {
        Schema::table('slide_verifications', function (Blueprint $table) {
            $indexes = collect(DB::select("SHOW INDEX FROM slide_verifications"))
                ->pluck('Key_name')
                ->toArray();

            if (in_array('slide_verifications_sample_id_unique', $indexes, true)) {
                $table->dropUnique('slide_verifications_sample_id_unique');
            }
        });
    }
};
