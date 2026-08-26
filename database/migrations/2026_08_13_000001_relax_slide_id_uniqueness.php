<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the UNIQUE constraint on slide_verifications.slide_id.
 *
 * slide_id holds a TCGA slide barcode (entity_submitter_id). Two sample rows can
 * legitimately carry the same barcode — different files with different md5sums
 * for the same physical slide. 74 such pairs exist in this database.
 *
 * With slide_id globally unique, the second insert failed with
 *   SQLSTATE[23000] 1062 Duplicate entry '<barcode>' for key
 *   'slide_verifications.slide_id_unique'
 * and the workaround in SlideVerificationService deleted the *other* sample's
 * row to make room, so each pair overwrote the other indefinitely. A row deleted
 * mid-flight also made finalize()'s fresh() return null, raising a TypeError.
 * Together that produced 43,476 failed_jobs rows over 109 days.
 *
 * One verification row per sample is still guaranteed by the existing UNIQUE
 * index on sample_id; slide_id keeps a plain index for lookups.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slide_verifications', function (Blueprint $table) {
            $table->dropUnique('slide_verifications_slide_id_unique');
            $table->index('slide_id', 'slide_verifications_slide_id_index');
        });
    }

    public function down(): void
    {
        // Re-adding the unique index will fail while duplicate barcodes exist.
        // Deduplicate first if this must be reversed.
        Schema::table('slide_verifications', function (Blueprint $table) {
            $table->dropIndex('slide_verifications_slide_id_index');
            $table->unique('slide_id', 'slide_verifications_slide_id_unique');
        });
    }
};
