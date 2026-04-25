<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('samples', function (Blueprint $table) {
            // FK to stains — nullable (existing samples won't have stain info yet)
            $table->foreignId('stain_id')
                  ->nullable()
                  ->after('category_id')
                  ->constrained('stains')
                  ->nullOnDelete();

            // For IHC samples: the specific antibody/marker used on this slide
            // (e.g. "ER", "PR", "HER2", "Ki67") — separate from the stain type itself
            $table->string('stain_marker', 100)->nullable()->after('stain_id');
        });
    }

    public function down(): void
    {
        Schema::table('samples', function (Blueprint $table) {
            $table->dropForeign(['stain_id']);
            $table->dropColumn(['stain_id', 'stain_marker']);
        });
    }
};
