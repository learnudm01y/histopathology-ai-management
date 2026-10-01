<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ten characters held every breast case GDC sent ("Yes", "No"), but lung cases
 * carry "Not Reported", and the insert was refused outright, so no patient in
 * that batch got a clinical record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinical_slide_case_information', function (Blueprint $table) {
            $table->string('consistent_pathology_review', 32)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('clinical_slide_case_information', function (Blueprint $table) {
            $table->string('consistent_pathology_review', 10)->nullable()->change();
        });
    }
};
