<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            // Drop foreign key only if it exists
            $foreignKeys = array_column(
                \Illuminate\Support\Facades\DB::select("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'data_sources' AND CONSTRAINT_NAME = 'data_sources_organ_id_foreign'"),
                'CONSTRAINT_NAME'
            );
            if (!empty($foreignKeys)) {
                $table->dropForeign(['organ_id']);
            }

            foreach (['source_type', 'access_type', 'organ_id'] as $col) {
                if (Schema::hasColumn('data_sources', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            $table->enum('source_type', ['TCGA', 'TCIA', 'GDC', 'Internal', 'Other'])->default('Other')->after('full_name');
            $table->enum('access_type', ['open', 'controlled', 'private'])->default('open')->after('base_url');
            $table->foreignId('organ_id')->nullable()->constrained('organs')->nullOnDelete()->after('access_type');
        });
    }
};
