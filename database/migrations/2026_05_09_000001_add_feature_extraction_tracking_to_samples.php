<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the columns required to track the Feature Extraction stage on RunPod.
 *
 * Lifecycle of a sample:
 *   1. tiling_status               = "done"   ← patches uploaded to GDrive (existing)
 *   2. feature_extraction_status   = "pending" → "processing" → "completed" / "failed"
 *
 * The features are produced by an external GPU server (RunPod) and synced
 * back to Google Drive under a folder whose name is the AI-model name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('samples', function (Blueprint $table) {
            // Which AI model performed the feature extraction
            $table->foreignId('feature_extraction_ai_model_id')
                  ->nullable()
                  ->after('magnification_id')
                  ->constrained('ai_models')
                  ->nullOnDelete();

            // Which server actually executed the extraction
            $table->foreignId('feature_extraction_server_id')
                  ->nullable()
                  ->after('feature_extraction_ai_model_id')
                  ->constrained('servers_names')
                  ->nullOnDelete();

            // Where the HDF5 features ended up on Google Drive
            $table->string('features_gdrive_path', 500)
                  ->nullable()
                  ->after('feature_extraction_server_id');

            $table->string('features_gdrive_folder_id', 100)
                  ->nullable()
                  ->after('features_gdrive_path');

            // Path on the RunPod server (kept mainly for traceability / audits)
            $table->string('features_runpod_path', 500)
                  ->nullable()
                  ->after('features_gdrive_folder_id');

            // Stats
            $table->unsignedInteger('features_patch_count')
                  ->nullable()
                  ->after('features_runpod_path');

            $table->unsignedInteger('features_failed_patch_count')
                  ->nullable()
                  ->after('features_patch_count');

            $table->string('features_model_version', 100)
                  ->nullable()
                  ->after('features_failed_patch_count');

            // Free-text error message if the job failed
            $table->text('feature_extraction_error')
                  ->nullable()
                  ->after('features_model_version');
        });
    }

    public function down(): void
    {
        Schema::table('samples', function (Blueprint $table) {
            $table->dropForeign(['feature_extraction_ai_model_id']);
            $table->dropForeign(['feature_extraction_server_id']);
            $table->dropColumn([
                'feature_extraction_ai_model_id',
                'feature_extraction_server_id',
                'features_gdrive_path',
                'features_gdrive_folder_id',
                'features_runpod_path',
                'features_patch_count',
                'features_failed_patch_count',
                'features_model_version',
                'feature_extraction_error',
            ]);
        });
    }
};
