<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('samples', function (Blueprint $table) {
            // Store the original Google Drive source file ID to enable job retry
            $table->string('gdrive_source_id', 200)->nullable()->after('file_id');
        });
    }

    public function down(): void
    {
        Schema::table('samples', function (Blueprint $table) {
            $table->dropColumn('gdrive_source_id');
        });
    }
};
