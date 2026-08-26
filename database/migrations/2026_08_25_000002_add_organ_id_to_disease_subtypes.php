<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Denormalise the organ onto `disease_subtypes` and enforce the real clinical rule:
 *
 *      a disease name is unique WITHIN an organ.
 *
 * The organ is always derivable through category.organ_id, but storing it here
 * buys two things a join cannot:
 *
 *   1. UNIQUE(organ_id, name) — the constraint has to live on this table. Without
 *      it "Invasive ductal carcinoma" could be created twice under the same organ
 *      via two different clinical groups, silently splitting one entity into two
 *      training classes.
 *   2. Organ-scoped training queries stop needing a join through categories.
 *
 * It is kept in sync by DiseaseSubtype::booted() and by CategoriesController when
 * a group is moved between organs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('disease_subtypes', function (Blueprint $table) {
            $table->foreignId('organ_id')
                  ->nullable()
                  ->after('id')
                  ->constrained('organs')
                  ->nullOnDelete()
                  ->comment('Denormalised from category.organ_id — the organ this disease belongs to');
        });

        // ── Backfill from the parent clinical group ───────────────────────────
        $updated = DB::table('disease_subtypes as d')
            ->join('categories as c', 'c.id', '=', 'd.category_id')
            ->whereNotNull('c.organ_id')
            ->update(['d.organ_id' => DB::raw('c.organ_id')]);

        // ── A disease name must be unique within its organ ────────────────────
        $collisions = DB::table('disease_subtypes')
            ->whereNotNull('organ_id')
            ->selectRaw('organ_id, name, COUNT(*) as n')
            ->groupBy('organ_id', 'name')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($collisions->isNotEmpty()) {
            $detail = $collisions
                ->map(fn($c) => "organ_id={$c->organ_id} name='{$c->name}' ×{$c->n}")
                ->implode('; ');
            throw new RuntimeException(
                "Cannot add UNIQUE(organ_id, name): the same disease name already exists more than "
                . "once within one organ — {$detail}. Merge the duplicates (they are the same "
                . "clinical entity and must be one training class), then re-run the migration."
            );
        }

        Schema::table('disease_subtypes', function (Blueprint $table) {
            $table->unique(['organ_id', 'name'], 'disease_subtypes_organ_name_unique');
            $table->index(['organ_id', 'is_active'], 'disease_subtypes_organ_active_idx');
        });

        Log::info("[Migration] disease_subtypes.organ_id backfilled for {$updated} row(s)");
    }

    public function down(): void
    {
        Schema::table('disease_subtypes', function (Blueprint $table) {
            $table->dropIndex('disease_subtypes_organ_active_idx');
            $table->dropUnique('disease_subtypes_organ_name_unique');
            $table->dropForeign(['organ_id']);
            $table->dropColumn('organ_id');
        });
    }
};
