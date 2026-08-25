<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hierarchical (coarse → fine) training support for CLAM runs.
 *
 *  label_map         — leaf/fine classes   : { "0": "Ductal carcinoma", "1": "Lobular carcinoma", ... }
 *  parent_label_map  — coarse classes      : { "0": "Benign", "1": "Malignant", ... }
 *  child_to_parent   — leaf idx → coarse idx: { "0": 1, "1": 1, "2": 0, ... }
 *
 * The CLAM head gets an auxiliary coarse classifier trained jointly with the fine
 * one (weight = hier_weight); at inference the fine logits are made hierarchy
 * consistent by masking with the predicted coarse branch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_runs', function (Blueprint $table) {
            $table->json('parent_label_map')
                  ->nullable()
                  ->after('label_map')
                  ->comment('Coarse class index → human label (hierarchical runs only)');

            $table->json('child_to_parent')
                  ->nullable()
                  ->after('parent_label_map')
                  ->comment('Fine class index → coarse class index');

            $table->unsignedSmallInteger('n_parent_classes')
                  ->default(0)
                  ->after('n_classes')
                  ->comment('0 = flat (non-hierarchical) run');

            $table->decimal('hier_weight', 4, 3)
                  ->default(0.300)
                  ->after('n_parent_classes')
                  ->comment('Weight of the auxiliary coarse-level loss');

            $table->boolean('use_class_weights')
                  ->default(true)
                  ->after('hier_weight')
                  ->comment('Inverse-frequency class weighting in the bag loss');

            $table->boolean('hierarchy_consistent_inference')
                  ->default(true)
                  ->after('use_class_weights')
                  ->comment('Mask fine logits by the predicted coarse branch at eval time');
        });
    }

    public function down(): void
    {
        Schema::table('training_runs', function (Blueprint $table) {
            $table->dropColumn([
                'parent_label_map',
                'child_to_parent',
                'n_parent_classes',
                'hier_weight',
                'use_class_weights',
                'hierarchy_consistent_inference',
            ]);
        });
    }
};
