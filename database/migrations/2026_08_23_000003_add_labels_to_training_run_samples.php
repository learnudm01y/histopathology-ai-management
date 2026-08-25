<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Freeze the resolved numeric labels into the pivot at dispatch time.
 *
 * Before this change the label was re-derived inside TrainingJob from the live
 * sample record, and an unmatched value silently fell back to class 0 —
 * silently poisoning the dataset. Resolving once, up-front, and storing the
 * result makes every run reproducible and lets the job fail loudly instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_run_samples', function (Blueprint $table) {
            $table->unsignedSmallInteger('label')
                  ->nullable()
                  ->after('training_phase')
                  ->comment('Resolved fine (leaf) class index for this run');

            $table->unsignedSmallInteger('parent_label')
                  ->nullable()
                  ->after('label')
                  ->comment('Resolved coarse (parent) class index — hierarchical runs only');
        });
    }

    public function down(): void
    {
        Schema::table('training_run_samples', function (Blueprint $table) {
            $table->dropColumn(['label', 'parent_label']);
        });
    }
};
