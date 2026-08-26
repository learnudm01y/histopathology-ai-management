<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops samples.training_phase.
 *
 * Two different columns were named training_phase:
 *   - samples.training_phase              — editable in three admin views
 *   - training_run_samples.training_phase — the pivot the trainer actually reads
 *
 * TrainingJob only ever read the pivot, so assigning a phase in the sample editor
 * configured nothing while looking like it configured the split. The split is now
 * computed per run (stratified and patient-grouped) in
 * OperationsController::splitSamples and stored on the pivot, which is the single
 * source of truth. All references to this column were removed first.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('samples', 'training_phase')) {
            Schema::table('samples', function (Blueprint $table) {
                $table->dropColumn('training_phase');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('samples', 'training_phase')) {
            Schema::table('samples', function (Blueprint $table) {
                $table->tinyInteger('training_phase')->nullable()->after('tissue_name');
            });
        }
    }
};
