<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every V2 Diagnose run: what went in, what Claude was asked, what it said,
 * and what was drawn from it.
 *
 * The prompt and Claude's raw output are kept verbatim next to the parsed
 * result. A coordinate that looks wrong on the slide months from now has to be
 * traceable to the exact words that produced it, and a parsed copy alone
 * cannot show whether the fault was in the answer or in the parsing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v2_diagnoses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sample_id')->nullable()->constrained('samples')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Clinical context as entered for this run — copied, not joined, so
            // an edit to the case later cannot change what Claude was told.
            $table->string('organ', 100);
            $table->string('stain', 100)->nullable();
            $table->unsignedSmallInteger('age')->nullable();
            $table->string('sex', 20)->nullable();
            $table->string('race', 100)->nullable();
            $table->text('clinical_notes')->nullable();

            $table->string('status', 20)->default('queued')
                ->comment('queued, waiting_slide, tiling, preparing, analysing, finalising, completed, failed');
            $table->string('stage_message', 500)->nullable();
            $table->json('events')->nullable()->comment('timestamped stage log');

            // The slide and how it was cut — everything needed to turn a tile
            // pixel back into a slide pixel.
            $table->string('wsi_path', 1000)->nullable();
            $table->unsignedInteger('slide_width')->nullable();
            $table->unsignedInteger('slide_height')->nullable();
            $table->decimal('base_mpp', 8, 5)->nullable();
            $table->unsignedSmallInteger('patch_size')->nullable();
            $table->decimal('target_mpp', 6, 3)->nullable();
            $table->decimal('scale_l0', 10, 6)->nullable()->comment('level-0 px per tile px');
            $table->unsignedSmallInteger('patches')->nullable();
            $table->string('run_dir', 500)->nullable();

            // Claude
            $table->string('claude_model', 100)->nullable();
            $table->string('claude_session_id', 100)->nullable();
            $table->longText('prompt')->nullable();
            $table->longText('raw_output')->nullable()->comment('CLI stdout of every call, verbatim');
            $table->decimal('cost_usd', 10, 4)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedSmallInteger('num_turns')->nullable();

            // What came back
            $table->text('summary')->nullable()->comment('at most five lines');
            $table->string('diagnosis', 255)->nullable();
            $table->decimal('confidence', 5, 3)->nullable();
            $table->unsignedSmallInteger('regions_count')->nullable();
            $table->boolean('sam_refined')->default(false);
            $table->json('warnings')->nullable();
            $table->text('error')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['sample_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_diagnoses');
    }
};
