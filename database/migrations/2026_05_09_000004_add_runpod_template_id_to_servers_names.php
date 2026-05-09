<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers_names', function (Blueprint $table) {
            // RunPod Template ID used when launching new pods from this server config.
            $table->string('runpod_template_id', 50)->nullable()->after('runpod_network_volume_id');
        });
    }

    public function down(): void
    {
        Schema::table('servers_names', function (Blueprint $table) {
            $table->dropColumn('runpod_template_id');
        });
    }
};
