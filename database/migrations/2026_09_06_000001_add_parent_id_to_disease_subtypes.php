<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a disease nest under another disease.
 *
 *      Organ → Clinical Group → Disease → Disease → …
 *
 * "Malignant" is a diagnosis in its own right, but it is also the parent of the
 * exact entities a pathologist actually signs out — "Infiltrating ductal
 * carcinoma", "Lobular carcinoma", … Before this column the taxonomy stopped one
 * level too early and those entities had to be typed as siblings of their own
 * parent, which loses the relationship the coarse/fine training labels rely on.
 *
 * UNIQUE(organ_id, name) is deliberately left untouched: a disease name still
 * identifies exactly one entity within an organ no matter how deep it sits, so
 * one entity can never split into two training classes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('disease_subtypes', function (Blueprint $table) {
            $table->foreignId('parent_id')
                  ->nullable()
                  ->after('category_id')
                  ->constrained('disease_subtypes')
                  ->nullOnDelete()
                  ->comment('Parent disease — NULL for a top-level disease of the clinical group');

            $table->index(['category_id', 'parent_id'], 'disease_subtypes_category_parent_idx');
        });
    }

    public function down(): void
    {
        Schema::table('disease_subtypes', function (Blueprint $table) {
            $table->dropIndex('disease_subtypes_category_parent_idx');
            $table->dropForeign(['parent_id']);
            $table->dropColumn('parent_id');
        });
    }
};
