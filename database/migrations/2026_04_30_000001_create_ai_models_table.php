<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_models', function (Blueprint $table) {
            $table->id();

            // Identification
            $table->string('name', 100)->unique();              // e.g. "TITAN"
            $table->string('full_name', 200)->nullable();       // e.g. "Multimodal Whole Slide Foundation Model"
            $table->string('provider', 100)->nullable();        // e.g. "MahmoodLab"
            $table->string('version', 50)->nullable();          // e.g. "v1"

            // Classification
            $table->enum('model_type', [
                'foundation',       // slide-level / patch foundation models
                'classification',
                'segmentation',
                'detection',
                'multimodal',
                'other',
            ])->default('foundation');

            $table->enum('level', [
                'patch',            // tile/patch-level encoders
                'slide',            // slide-level / WSI-level
                'region',
                'other',
            ])->default('slide');

            // Source links
            $table->string('huggingface_url', 500)->nullable();
            $table->string('paper_url', 500)->nullable();
            $table->string('repo_url', 500)->nullable();

            // Technical specs
            $table->string('input_resolution', 50)->nullable();   // e.g. "512x512"
            $table->string('embedding_dim', 30)->nullable();      // e.g. "1024"
            $table->string('parameters', 30)->nullable();         // e.g. "1.1B"
            $table->string('license', 100)->nullable();           // e.g. "CC-BY-NC-ND-4.0"

            // Description & administrative
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);        // default model for training

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_models');
    }
};
