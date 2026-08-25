<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Promote `samples.disease_subtype` (free-text) to a real FK on `disease_subtypes`.
 *
 * Rationale: fine-grained ("exact disease name") training needs a *stable, unique*
 * leaf-class identifier. A free-text column cannot be mapped to a numeric class
 * index reliably (typos / renames / case differences silently collapse classes).
 * The legacy string column is KEPT for display and backward compatibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('samples', function (Blueprint $table) {
            $table->foreignId('disease_subtype_id')
                  ->nullable()
                  ->after('category_id')
                  ->constrained('disease_subtypes')
                  ->nullOnDelete();

            $table->index(['category_id', 'disease_subtype_id'], 'samples_cat_subtype_idx');
        });

        // ── Backfill from the legacy free-text column ─────────────────────────
        // Match on name; prefer the subtype that belongs to the sample's own
        // category so identical names under different categories never collide.
        $subtypes = DB::table('disease_subtypes')->get(['id', 'category_id', 'name']);

        $byCatAndName = [];  // "categoryId|lowername" => id
        $byNameOnly   = [];  // "lowername"            => [ids]
        foreach ($subtypes as $st) {
            $key = mb_strtolower(trim($st->name));
            $byCatAndName[$st->category_id . '|' . $key] = $st->id;
            $byNameOnly[$key][] = $st->id;
        }

        $matched = 0;
        $ambiguous = 0;

        DB::table('samples')
            ->whereNotNull('disease_subtype')
            ->where('disease_subtype', '<>', '')
            ->select(['id', 'category_id', 'disease_subtype'])
            ->orderBy('id')
            ->chunk(500, function ($rows) use ($byCatAndName, $byNameOnly, &$matched, &$ambiguous) {
                foreach ($rows as $row) {
                    $key = mb_strtolower(trim($row->disease_subtype));
                    $id  = null;

                    if ($row->category_id !== null && isset($byCatAndName[$row->category_id . '|' . $key])) {
                        $id = $byCatAndName[$row->category_id . '|' . $key];
                    } elseif (isset($byNameOnly[$key]) && count($byNameOnly[$key]) === 1) {
                        $id = $byNameOnly[$key][0];
                    } elseif (isset($byNameOnly[$key])) {
                        // Same leaf name exists under >1 category and the sample has no
                        // category — leave NULL rather than guess wrong.
                        $ambiguous++;
                    }

                    if ($id !== null) {
                        DB::table('samples')->where('id', $row->id)->update(['disease_subtype_id' => $id]);
                        $matched++;
                    }
                }
            });

        DB::statement('SELECT 1'); // no-op keeps some drivers happy after chunked writes

        \Illuminate\Support\Facades\Log::info(
            "[Migration] disease_subtype_id backfill — matched={$matched} ambiguous_skipped={$ambiguous}"
        );
    }

    public function down(): void
    {
        Schema::table('samples', function (Blueprint $table) {
            $table->dropIndex('samples_cat_subtype_idx');
            $table->dropForeign(['disease_subtype_id']);
            $table->dropColumn('disease_subtype_id');
        });
    }
};
