<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('samples', function (Blueprint $table) {
            // Stage 2 – Feature Extraction
            $table->enum('feature_extraction_status', ['pending', 'processing', 'completed', 'failed'])
                  ->default('pending')
                  ->after('tiling_completed_at');
            $table->timestamp('feature_extraction_completed_at')->nullable()->after('feature_extraction_status');

            // Stage 3 – MIL (Multiple Instance Learning)
            $table->enum('mil_status', ['pending', 'processing', 'completed', 'failed'])
                  ->default('pending')
                  ->after('feature_extraction_completed_at');
            $table->timestamp('mil_completed_at')->nullable()->after('mil_status');

            // Stage 4 – Pathology Decision Layer
            $table->enum('pathology_decision_status', ['pending', 'processing', 'completed', 'failed'])
                  ->default('pending')
                  ->after('mil_completed_at');
            $table->timestamp('pathology_decision_completed_at')->nullable()->after('pathology_decision_status');

            // Stage 5 – Final Diagnosis
            $table->enum('final_diagnosis_status', ['pending', 'processing', 'completed', 'failed'])
                  ->default('pending')
                  ->after('pathology_decision_completed_at');
            $table->string('final_diagnosis_result', 300)->nullable()->after('final_diagnosis_status');
            $table->timestamp('final_diagnosis_completed_at')->nullable()->after('final_diagnosis_result');
        });
    }

    public function down(): void
    {
        Schema::table('samples', function (Blueprint $table) {
            $table->dropColumn([
                'feature_extraction_status',
                'feature_extraction_completed_at',
                'mil_status',
                'mil_completed_at',
                'pathology_decision_status',
                'pathology_decision_completed_at',
                'final_diagnosis_status',
                'final_diagnosis_result',
                'final_diagnosis_completed_at',
            ]);
        });
    }
};
