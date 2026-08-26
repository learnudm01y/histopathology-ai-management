<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scope a training run to one organ.
 *
 * The organ is deliberately a SCOPE, not a level the model has to predict: the
 * anatomical site arrives with the specimen, so spending network capacity on
 * re-deriving it from morphology is waste. Restricting a run to a single organ
 * also keeps the class count realistic (tens of leaves instead of hundreds) and
 * leaves the clinical group as the coarse level the auxiliary head supervises:
 *
 *      Organ  = run scope           (filter)
 *      Group  = coarse class        (auxiliary head)
 *      Disease = fine class         (main head)
 *
 * NULL means a legacy or deliberately cross-organ run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_runs', function (Blueprint $table) {
            $table->foreignId('organ_id')
                  ->nullable()
                  ->after('server_id')
                  ->constrained('organs')
                  ->nullOnDelete()
                  ->comment('Organ this run is scoped to; NULL = legacy / cross-organ run');
        });
    }

    public function down(): void
    {
        Schema::table('training_runs', function (Blueprint $table) {
            $table->dropForeign(['organ_id']);
            $table->dropColumn('organ_id');
        });
    }
};
