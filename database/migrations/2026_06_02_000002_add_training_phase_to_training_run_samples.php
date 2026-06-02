<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_run_samples', function (Blueprint $table) {
            // 1 = train  |  2 = validation  |  3 = test
            $table->tinyInteger('training_phase')
                  ->default(1)
                  ->after('sample_id')
                  ->comment('1=train  2=val  3=test');
        });
    }

    public function down(): void
    {
        Schema::table('training_run_samples', function (Blueprint $table) {
            $table->dropColumn('training_phase');
        });
    }
};
