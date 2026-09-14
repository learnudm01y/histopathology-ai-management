<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every score a deployed model has ever produced.
 *
 * Predictions were living in the cache, which is the wrong home for them twice
 * over: they expire, and a validation run is exactly the thing that must still
 * be readable months later when someone asks how a number was arrived at. A
 * result you cannot produce again on demand did not really happen.
 *
 * Kept apart from `inference_runs`, which belongs to the CLAM training flow and
 * requires a training_run_id these registry models have no equivalent of.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slide_predictions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sample_id')->constrained('samples')->cascadeOnDelete();
            $table->string('model_key', 100)->comment('key in config/diagnosis_models');
            $table->string('model_label', 150)->nullable();

            // What the model said, and what was actually delivered — they differ
            // whenever a guard fired, and the difference is the whole point of
            // auditing a refusal.
            $table->string('call', 16)->nullable()->comment('the raw lean: IDC or ILC');
            $table->decimal('p_ilc', 6, 4)->nullable();
            $table->string('decision', 32)->nullable()->comment('what was delivered, e.g. DO NOT USE');
            $table->boolean('referred')->default(false);
            $table->string('ood_status', 16)->nullable();
            $table->decimal('familiarity', 8, 3)->nullable();

            // The truth as recorded in the database at the time of scoring, so a
            // later relabelling cannot quietly rewrite how a run scored.
            $table->string('truth', 16)->nullable();
            $table->string('site', 16)->nullable();

            $table->unsignedInteger('patches')->nullable();
            $table->json('payload')->nullable()->comment('the full result as returned');

            $table->timestamps();

            $table->index(['model_key', 'created_at']);
            $table->index(['sample_id', 'created_at']);
            $table->index('decision');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slide_predictions');
    }
};
