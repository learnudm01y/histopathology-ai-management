<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patch_sizes', function (Blueprint $table) {
            $table->id();

            $table->unsignedSmallInteger('size_px');             // Patch width/height in pixels (e.g. 224, 256, 512)
            $table->string('label', 150);                        // Human-readable label  (e.g. "256×256 — Standard CNN")
            $table->unsignedTinyInteger('wsi_level')->default(0);// WSI pyramid level (0 = highest resolution)
            $table->unsignedSmallInteger('overlap_px')->default(0); // Overlap between adjacent patches

            // Link to the AI model this patch size is designed for (optional).
            // A single size can be used for multiple models (nullable FK).
            $table->foreignId('ai_model_id')
                  ->nullable()
                  ->constrained('ai_models')
                  ->nullOnDelete();

            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patch_sizes');
    }
};
