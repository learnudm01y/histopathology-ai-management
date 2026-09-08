<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets an operation keep its slides through a retry.
 *
 * Retrying used to open a new operation and leave the old one frozen, so a
 * batch that was dispatched together ended up split across two records and the
 * eventual success was filed under a run the operator never asked for. A slide
 * now stays in the group it was dispatched with, and that group records how it
 * finally turned out.
 *
 * Reopening a finished record would ordinarily lose the fact that an attempt
 * failed, which is the part worth auditing. `attempts` keeps it: the count says
 * how many times the group tried this slide, and `last_attempt_at` when it last
 * did, so "completed on the second attempt" stays visible after the status has
 * moved on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operation_items', function (Blueprint $table) {
            $table->unsignedSmallInteger('attempts')->default(1)->after('status');
            $table->timestamp('last_attempt_at')->nullable()->after('attempts');
        });
    }

    public function down(): void
    {
        Schema::table('operation_items', function (Blueprint $table) {
            $table->dropColumn(['attempts', 'last_attempt_at']);
        });
    }
};
