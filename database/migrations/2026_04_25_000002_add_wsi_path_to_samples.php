<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('samples', function (Blueprint $table) {
            // المسار الكامل للملف WSI على Google Drive
            $table->string('wsi_remote_path', 500)->nullable()->after('storage_path');

            // نوع الرفع: 'single' = ملف واحد، 'bulk' = مجلد كامل (TCGA)
            $table->enum('upload_type', ['single', 'bulk'])->default('single')->after('wsi_remote_path');

            // المسار الأصلي للمجلد عند رفع TCGA
            $table->string('bulk_folder_original_path', 500)->nullable()->after('upload_type');
        });
    }

    public function down(): void
    {
        Schema::table('samples', function (Blueprint $table) {
            $table->dropColumn(['wsi_remote_path', 'upload_type', 'bulk_folder_original_path']);
        });
    }
};
