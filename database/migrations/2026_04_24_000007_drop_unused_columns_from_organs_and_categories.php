<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop name_ar, tcga_project_id, icd_site_code from organs (if they exist)
        Schema::table('organs', function (Blueprint $table) {
            foreach (['name_ar', 'tcga_project_id', 'icd_site_code'] as $col) {
                if (Schema::hasColumn('organs', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        // Drop name (slug), label_ar, badge_class from categories (if they exist)
        Schema::table('categories', function (Blueprint $table) {
            foreach (['name', 'label_ar', 'badge_class'] as $col) {
                if (Schema::hasColumn('categories', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('organs', function (Blueprint $table) {
            $table->string('name_ar', 100)->nullable()->after('name');
            $table->string('tcga_project_id', 20)->nullable()->after('name_ar');
            $table->string('icd_site_code', 10)->nullable()->after('tcga_project_id');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->string('name', 50)->unique()->after('id');
            $table->string('label_ar', 100)->nullable()->after('label_en');
            $table->string('badge_class', 30)->default('secondary')->after('label_ar');
        });
    }
};
