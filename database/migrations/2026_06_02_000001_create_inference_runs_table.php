<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inference_runs', function (Blueprint $table) {
            $table->id();

            // ── Trained model to use ──────────────────────────────────────────
            $table->unsignedBigInteger('training_run_id');
            $table->foreign('training_run_id')->references('id')->on('training_runs')->cascadeOnDelete();

            // ── Infrastructure ────────────────────────────────────────────────
            $table->unsignedBigInteger('server_id')->nullable()->comment('RunPod server to run inference on');
            $table->foreign('server_id')->references('id')->on('servers_names')->nullOnDelete();

            // ── Slide source ──────────────────────────────────────────────────
            $table->enum('slide_source', ['sample', 'gdrive'])->default('sample')
                  ->comment('sample = existing DB sample; gdrive = GDrive features path');
            $table->unsignedBigInteger('sample_id')->nullable()
                  ->comment('FK to samples when slide_source=sample');
            $table->foreign('sample_id')->references('id')->on('samples')->nullOnDelete();
            $table->string('slide_name')->nullable()
                  ->comment('Display name for the slide (set automatically from sample or provided)');
            $table->string('slide_features_gdrive_path')->nullable()
                  ->comment('GDrive path to the .h5 features file (when slide_source=gdrive or copied from sample)');

            // ── Job status ────────────────────────────────────────────────────
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])
                  ->default('pending');

            // ── Inference results ─────────────────────────────────────────────
            $table->json('prediction')->nullable()
                  ->comment('{"class_label":"Normal","class_index":0,"confidence":0.92,"probabilities":{"0":0.92,"1":0.08}}');
            $table->string('attention_map_gdrive_path')->nullable()
                  ->comment('GDrive path to the attention map image');
            $table->string('gdrive_output_dir')->nullable()
                  ->comment('Base GDrive folder: inference/results/run_{id}/');

            // ── Error tracking ─────────────────────────────────────────────────
            $table->text('error')->nullable();

            // ── Timestamps ────────────────────────────────────────────────────
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inference_runs');
    }
};
