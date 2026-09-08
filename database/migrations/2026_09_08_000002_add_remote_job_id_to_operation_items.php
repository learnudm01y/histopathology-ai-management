<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remembers the id the GPU worker gave each dispatched slide.
 *
 * The worker keeps its queue in memory, so restarting it — to change its
 * settings, or because the pod stopped — silently drops every slide waiting in
 * it. Laravel went on believing those slides were in flight, and nothing could
 * tell a slide the worker is still chewing on from one it forgot.
 *
 * With the worker's own job id on record, resuming can ASK about each slide
 * (GET /jobs/{id}) and re-dispatch only the ones the worker no longer knows.
 * That is what makes resume safe to press twice: a slide still queued on the
 * worker is left alone rather than sent again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operation_items', function (Blueprint $table) {
            $table->string('remote_job_id', 64)->nullable()->after('last_attempt_at');
            $table->index('remote_job_id');
        });
    }

    public function down(): void
    {
        Schema::table('operation_items', function (Blueprint $table) {
            $table->dropIndex(['remote_job_id']);
            $table->dropColumn('remote_job_id');
        });
    }
};
