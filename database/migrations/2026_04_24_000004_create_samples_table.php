<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('samples', function (Blueprint $table) {
            $table->id();

            // Foreign keys
            $table->foreignId('case_id')->nullable()->constrained('cases')->nullOnDelete();
            $table->foreignId('organ_id')->nullable()->constrained('organs')->nullOnDelete();
            $table->foreignId('data_source_id')->nullable()->constrained('data_sources')->nullOnDelete();

            // GDC / File Identity
            $table->string('file_id', 36)->unique()->nullable();
            $table->string('file_name', 350)->nullable();
            $table->string('md5sum', 32)->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->decimal('file_size_gb', 8, 3)->nullable();
            $table->string('data_format', 10)->default('SVS');
            $table->string('data_type', 50)->nullable();
            $table->string('access_level', 20)->default('open');
            $table->string('gdc_state', 20)->default('released');

            // Slide / Specimen Identity
            $table->string('entity_submitter_id', 150)->nullable();
            $table->string('entity_id', 36)->nullable();
            $table->string('entity_type', 20)->default('slide');

            // Classification / Label
            $table->enum('category', ['normal', 'tumor', 'unknown'])->default('unknown');
            $table->string('disease_subtype', 100)->nullable();
            $table->string('tissue_name', 150)->nullable();
            $table->tinyInteger('training_phase')->nullable();

            // Storage
            $table->string('storage_link', 500)->nullable();
            $table->string('storage_path', 500)->nullable();
            $table->enum('storage_status', [
                'not_downloaded', 'downloading', 'verifying',
                'available', 'corrupted', 'missing'
            ])->default('not_downloaded');
            $table->timestamp('download_started_at')->nullable();
            $table->timestamp('download_completed_at')->nullable();
            $table->boolean('md5_verified')->default(false);

            // Tiling
            $table->enum('tiling_status', ['pending', 'processing', 'done', 'failed'])->default('pending');
            $table->unsignedInteger('tile_count')->nullable();
            $table->unsignedSmallInteger('tile_size_px')->default(256);
            $table->string('magnification', 10)->default('20x');
            $table->decimal('tissue_coverage_pct', 5, 2)->nullable();
            $table->string('tiles_path', 500)->nullable();
            $table->timestamp('tiling_completed_at')->nullable();

            // Quality
            $table->enum('quality_status', ['passed', 'rejected', 'needs_review', 'pending'])->default('pending');
            $table->string('quality_rejection_reason', 200)->nullable();
            $table->boolean('is_usable')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('samples');
    }
};
