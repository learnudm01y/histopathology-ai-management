<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cases', function (Blueprint $table) {
            $table->id();
            $table->string('case_id', 36)->unique();
            $table->string('submitter_id', 50)->nullable();
            $table->string('project_id', 20)->nullable();
            $table->foreignId('organ_id')->nullable()->constrained('organs')->nullOnDelete();
            $table->foreignId('data_source_id')->nullable()->constrained('data_sources')->nullOnDelete();
            $table->string('primary_site', 100)->nullable();
            $table->string('disease_type', 200)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cases');
    }
};
