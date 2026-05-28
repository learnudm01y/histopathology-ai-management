<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers_names', function (Blueprint $table) {
            // The port the service listens on inside the RunPod container.
            // Used to build the proxy URL: https://{podId}-{runpod_port}.proxy.runpod.net
            $table->unsignedSmallInteger('runpod_port')
                  ->default(8000)
                  ->after('runpod_template_id')
                  ->comment('Container port: 8000=Virchow2, 8001=TITAN, 8002=CLAM');
        });

        // Backfill correct ports for the three existing RunPod servers
        // (Virchow2=server_id 2 / port 8000, TITAN=server_id 3 / port 8001)
        // Adjust IDs below if your production data differs.
        DB::statement("
            UPDATE servers_names
            SET runpod_port = CASE
                WHEN name LIKE '%Virchow%'  THEN 8000
                WHEN name LIKE '%TITAN%'    THEN 8001
                WHEN name LIKE '%CLAM%'     THEN 8002
                ELSE 8000
            END
            WHERE type = 'external'
        ");
    }

    public function down(): void
    {
        Schema::table('servers_names', function (Blueprint $table) {
            $table->dropColumn('runpod_port');
        });
    }
};
