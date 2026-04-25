<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('samples', function (Blueprint $table) {
            // Add FK column right after entity_type
            $table->foreignId('category_id')
                  ->nullable()
                  ->after('entity_type')
                  ->constrained('categories')
                  ->nullOnDelete();

            // Drop the old ENUM column
            $table->dropColumn('category');
        });
    }

    public function down(): void
    {
        Schema::table('samples', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropColumn('category_id');
            $table->enum('category', ['normal', 'tumor', 'unknown'])->default('unknown')->after('entity_type');
        });
    }
};
