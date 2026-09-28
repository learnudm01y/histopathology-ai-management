<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The diagnosis as a standard abbreviation (IDC, ILC, DCIS, ...), kept apart
 * from the free-text name so runs can be filtered and counted by it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_diagnoses', function (Blueprint $table) {
            $table->string('diagnosis_code', 12)->nullable()->after('summary')->index();
        });
    }

    public function down(): void
    {
        Schema::table('v2_diagnoses', function (Blueprint $table) {
            $table->dropIndex(['diagnosis_code']);
            $table->dropColumn('diagnosis_code');
        });
    }
};
