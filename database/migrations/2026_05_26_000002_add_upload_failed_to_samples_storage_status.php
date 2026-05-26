<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extend the storage_status ENUM on `samples` to include 'upload_failed'.
 *
 * Why: UploadWsiToDriveJob::failed() marks storage_status='upload_failed' so
 * that the self-healing scanner (wsi:scan-and-upload Phase 0) can detect
 * samples whose upload exhausted all retries and automatically reset them
 * for another attempt after 24 h.
 *
 * Previous ENUM: not_downloaded | downloading | verifying | available | corrupted | missing
 * New ENUM:      not_downloaded | downloading | verifying | available | corrupted | missing | upload_failed
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE samples
            MODIFY COLUMN storage_status
            ENUM('not_downloaded','downloading','verifying','available','corrupted','missing','upload_failed')
            NOT NULL DEFAULT 'not_downloaded'
        ");
    }

    public function down(): void
    {
        // Remove 'upload_failed' rows first to avoid truncation on rollback.
        DB::statement("UPDATE samples SET storage_status = 'corrupted' WHERE storage_status = 'upload_failed'");

        DB::statement("
            ALTER TABLE samples
            MODIFY COLUMN storage_status
            ENUM('not_downloaded','downloading','verifying','available','corrupted','missing')
            NOT NULL DEFAULT 'not_downloaded'
        ");
    }
};
