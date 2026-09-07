<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An audit trail for the work the Operations page dispatches.
 *
 * A dispatch used to leave no record of itself. Patch extraction wrote
 * `tiling_status` onto each slide and nothing said which slides went out
 * together, under which settings, or by whose hand — and re-tiling a slide
 * later overwrote the only evidence the earlier run had ever happened.
 *
 * `operations` is one dispatch; `operation_items` is one slide inside it.
 * Each item keeps the slide's file name and its case's submitter id as a
 * SNAPSHOT: slides and cases are bulk-deletable here, and an audit that
 * evaporates when its subject is deleted is not an audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // patch_extraction | feature_extraction | training — deliberately a
            // string, so a new kind of operation needs no migration to be audited.
            $table->string('type', 40);
            $table->string('status', 30)->default('running');
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('completed_items')->default(0);
            $table->unsignedInteger('failed_items')->default(0);
            // What the operation was asked to do: server, patch size, magnification,
            // the training run id — whatever the dispatch needs to be reproducible.
            $table->json('params')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('operation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operation_id')->constrained()->cascadeOnDelete();
            // nullOnDelete, not cascade: deleting a slide must not erase the record
            // that it was processed. The snapshot columns below carry the identity.
            $table->foreignId('sample_id')->nullable()->constrained('samples')->nullOnDelete();
            $table->foreignId('case_id')->nullable()->constrained('cases')->nullOnDelete();
            $table->string('sample_file_name')->nullable();
            $table->string('case_submitter_id')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('message')->nullable();
            $table->timestamps();

            // One slide appears at most once per operation, so re-dispatching is
            // never able to double-count the same work inside one record.
            $table->unique(['operation_id', 'sample_id']);
            $table->index(['operation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_items');
        Schema::dropIfExists('operations');
    }
};
