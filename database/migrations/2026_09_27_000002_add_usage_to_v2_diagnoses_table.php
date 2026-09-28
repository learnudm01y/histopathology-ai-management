<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a run actually consumed, in tokens.
 *
 * The CLI's total_cost_usd is an estimate at API prices and is reported even
 * when the run goes through a Claude subscription, where nothing is billed
 * per call and what matters is how much of the plan's usage a run takes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_diagnoses', function (Blueprint $table) {
            $table->json('usage')->nullable()->after('num_turns')
                ->comment('summed token counts across every Claude call of the run');
        });
    }

    public function down(): void
    {
        Schema::table('v2_diagnoses', function (Blueprint $table) {
            $table->dropColumn('usage');
        });
    }
};
