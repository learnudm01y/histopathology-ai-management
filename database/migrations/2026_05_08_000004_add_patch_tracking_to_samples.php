<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('samples', function (Blueprint $table) {
            // Server that executed the patch extraction
            $table->foreignId('patch_server_id')
                  ->nullable()
                  ->constrained('servers_names')
                  ->nullOnDelete()
                  ->after('tiling_completed_at');

            // Patch-size configuration used
            $table->foreignId('patch_size_id')
                  ->nullable()
                  ->constrained('patch_sizes')
                  ->nullOnDelete()
                  ->after('patch_server_id');

            // Google Drive storage for the extracted patches folder
            $table->string('tiles_gdrive_folder_id', 100)->nullable()->after('patch_size_id');
            $table->string('tiles_gdrive_path', 500)->nullable()->after('tiles_gdrive_folder_id');
        });
    }

    public function down(): void
    {
        Schema::table('samples', function (Blueprint $table) {
            $table->dropForeign(['patch_server_id']);
            $table->dropForeign(['patch_size_id']);
            $table->dropColumn([
                'patch_server_id',
                'patch_size_id',
                'tiles_gdrive_folder_id',
                'tiles_gdrive_path',
            ]);
        });
    }
};
