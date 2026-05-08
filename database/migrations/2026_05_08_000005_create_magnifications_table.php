<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('magnifications', function (Blueprint $table) {
            $table->id();
            $table->string('label', 20)->unique();           // e.g.  "x10", "x20", "x40"
            $table->unsignedSmallInteger('value');           // numeric: 10, 20, 40
            $table->string('folder_name', 30)->unique();     // used as folder slug: "10x", "20x", "40x"
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Add magnification_id to samples
        Schema::table('samples', function (Blueprint $table) {
            $table->foreignId('magnification_id')
                  ->nullable()
                  ->after('patch_size_id')
                  ->constrained('magnifications')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('samples', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\Magnification::class);
            $table->dropColumn('magnification_id');
        });

        Schema::dropIfExists('magnifications');
    }
};
