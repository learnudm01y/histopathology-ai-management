<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_runs', function (Blueprint $table) {
            $table->id();

            // ── AI models involved ────────────────────────────────────────────
            $table->unsignedBigInteger('training_head_id')->nullable()->comment('CLAM or other MIL head from ai_models');
            $table->unsignedBigInteger('feature_model_id')->nullable()->comment('Foundation model used for feature extraction (TITAN/Virchow2)');
            $table->foreign('training_head_id')->references('id')->on('ai_models')->nullOnDelete();
            $table->foreign('feature_model_id')->references('id')->on('ai_models')->nullOnDelete();

            // ── Infrastructure ────────────────────────────────────────────────
            $table->unsignedBigInteger('server_id')->nullable()->comment('RunPod CLAM server from servers_names');
            $table->foreign('server_id')->references('id')->on('servers_names')->nullOnDelete();

            // ── Job status ────────────────────────────────────────────────────
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'cancelled'])
                  ->default('pending');

            // ── Dataset metadata ──────────────────────────────────────────────
            $table->integer('sample_count')->default(0);
            $table->string('label_type')->default('category')->comment('category | disease_type | custom');
            $table->json('label_map')->nullable()->comment('Numeric label → human label mapping');

            // ── Hyperparameters sent to CLAM server ───────────────────────────
            $table->string('model_type')->default('clam_sb')->comment('clam_sb | clam_mb');
            $table->integer('epochs')->default(20);
            $table->decimal('learning_rate', 10, 8)->default(0.0001);
            $table->integer('bag_size')->default(-1)->comment('-1 = no limit');
            $table->integer('n_classes')->default(2);

            // ── Results ───────────────────────────────────────────────────────
            $table->json('metrics')->nullable()->comment('best_val_auc, accuracy, training history');
            $table->string('gdrive_output_dir')->nullable()->comment('GDrive path where checkpoint is stored');
            $table->string('model_gdrive_path')->nullable()->comment('Full GDrive path of best checkpoint');
            $table->text('error')->nullable();

            // ── Timestamps ────────────────────────────────────────────────────
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        // Pivot table: which samples are part of which training run
        Schema::create('training_run_samples', function (Blueprint $table) {
            $table->unsignedBigInteger('training_run_id');
            $table->unsignedBigInteger('sample_id');
            $table->primary(['training_run_id', 'sample_id']);
            $table->foreign('training_run_id')->references('id')->on('training_runs')->cascadeOnDelete();
            $table->foreign('sample_id')->references('id')->on('samples')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_run_samples');
        Schema::dropIfExists('training_runs');
    }
};
