<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Root the taxonomy in `organs`.
 *
 *      Organ  →  Category (clinical group)  →  Disease Subtype
 *
 * Until now `categories` was a GLOBAL, hand-typed list. That allowed a single
 * category row — and a single disease subtype under it — to be shared by slides
 * from different organs, which makes it an incoherent training class:
 * "Adenocarcinoma" of the lung is not the same entity as "Adenocarcinoma" of the
 * colon, and a class that contains both teaches the model an average of two
 * different morphologies.
 *
 * This migration therefore does more than add a column. Any category that is
 * currently used by slides from N different organs is FORKED into N organ-scoped
 * copies, and the slides (plus their disease subtypes) are repointed to the copy
 * that matches their own organ. Nothing is deleted and no slide loses its label.
 *
 * Categories with no slides at all cannot have their organ inferred; they are
 * left with organ_id = NULL and must be assigned by hand in the taxonomy portal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('organ_id')
                  ->nullable()
                  ->after('id')
                  ->constrained('organs')
                  ->nullOnDelete()
                  ->comment('Root of the taxonomy — a category belongs to exactly one organ');
        });

        $report = [
            'assigned'        => 0,  // category could be scoped to its single organ
            'forked'          => 0,  // extra organ-scoped copies created
            'orphan'          => 0,  // no slides → organ unknown, left NULL
            'samples_moved'   => 0,
            'subtypes_forked' => 0,
        ];

        $categories = DB::table('categories')->orderBy('id')->get();

        foreach ($categories as $cat) {
            // Snapshot the slides BEFORE anything is repointed.
            $rows = DB::table('samples')
                ->where('category_id', $cat->id)
                ->whereNotNull('organ_id')
                ->get(['id', 'organ_id', 'disease_subtype_id']);

            if ($rows->isEmpty()) {
                $report['orphan']++;
                continue;
            }

            $byOrgan  = $rows->groupBy('organ_id');
            $organIds = $byOrgan->keys()->all();

            // The first organ keeps the original row; the rest get copies.
            $primaryOrgan = array_shift($organIds);

            DB::table('categories')
                ->where('id', $cat->id)
                ->update(['organ_id' => $primaryOrgan, 'updated_at' => now()]);
            $report['assigned']++;

            $categoryForOrgan = [$primaryOrgan => $cat->id];

            foreach ($organIds as $organId) {
                $cloneId = DB::table('categories')->insertGetId([
                    'organ_id'   => $organId,
                    'label_en'   => $cat->label_en,
                    'is_active'  => $cat->is_active,
                    'notes'      => $cat->notes,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $categoryForOrgan[$organId] = $cloneId;
                $report['forked']++;

                $ids = $byOrgan[$organId]->pluck('id')->all();
                DB::table('samples')->whereIn('id', $ids)->update(['category_id' => $cloneId]);
                $report['samples_moved'] += count($ids);
            }

            // Subtypes follow their slides into the organ-scoped copy.
            $subtypes = DB::table('disease_subtypes')->where('category_id', $cat->id)->get();

            foreach ($subtypes as $st) {
                foreach ($organIds as $organId) {
                    $ids = $byOrgan[$organId]
                        ->where('disease_subtype_id', $st->id)
                        ->pluck('id')
                        ->all();

                    if (empty($ids)) {
                        continue;
                    }

                    $cloneStId = DB::table('disease_subtypes')->insertGetId([
                        'category_id' => $categoryForOrgan[$organId],
                        'name'        => $st->name,
                        'is_active'   => $st->is_active,
                        'notes'       => $st->notes,
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ]);

                    DB::table('samples')->whereIn('id', $ids)
                        ->update(['disease_subtype_id' => $cloneStId]);
                    $report['subtypes_forked']++;
                }
            }
        }

        // ── A clinical group name must be unique within its organ ─────────────
        $collisions = DB::table('categories')
            ->whereNotNull('organ_id')
            ->selectRaw('organ_id, label_en, COUNT(*) as n')
            ->groupBy('organ_id', 'label_en')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($collisions->isNotEmpty()) {
            $detail = $collisions
                ->map(fn($c) => "organ_id={$c->organ_id} label='{$c->label_en}' ×{$c->n}")
                ->implode('; ');
            throw new RuntimeException(
                "Cannot add UNIQUE(organ_id, label_en): duplicate clinical groups already exist "
                . "within the same organ — {$detail}. Merge or rename them, then re-run the migration."
            );
        }

        Schema::table('categories', function (Blueprint $table) {
            $table->unique(['organ_id', 'label_en'], 'categories_organ_label_unique');
        });

        Log::info('[Migration] categories rooted in organs — ' . json_encode($report));
    }

    public function down(): void
    {
        // The forked rows are intentionally NOT merged back: doing so would have
        // to guess which copy is canonical and would re-introduce the very
        // cross-organ class collision this migration removed.
        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique('categories_organ_label_unique');
            $table->dropForeign(['organ_id']);
            $table->dropColumn('organ_id');
        });
    }
};
