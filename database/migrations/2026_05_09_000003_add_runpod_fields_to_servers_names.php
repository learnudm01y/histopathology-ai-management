<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers_names', function (Blueprint $table) {
            // RunPod-specific: the account-level API key used to manage pods via
            // the RunPod REST API (rpa_… key).  Kept separate from api_key, which
            // is the *shared secret* used to authenticate job callbacks.
            $table->text('runpod_api_key')->nullable()->after('api_key');

            // The persistent Network Volume ID attached to the RunPod pod.
            // Used to display in the admin UI and reference in deployment docs.
            $table->string('runpod_network_volume_id', 100)->nullable()->after('runpod_api_key');
        });
    }

    public function down(): void
    {
        Schema::table('servers_names', function (Blueprint $table) {
            $table->dropColumn(['runpod_api_key', 'runpod_network_volume_id']);
        });
    }
};
