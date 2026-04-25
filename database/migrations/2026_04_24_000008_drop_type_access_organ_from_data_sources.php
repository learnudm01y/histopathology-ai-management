<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            $table->dropForeign(['organ_id']);
            $table->dropColumn(['source_type', 'access_type', 'organ_id']);
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
