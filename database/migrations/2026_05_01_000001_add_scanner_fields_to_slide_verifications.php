<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slide_verifications', function (Blueprint $table) {
            $table->string('scanner_vendor', 100)->nullable()->after('stain_type')
                ->comment('Scanner manufacturer extracted from WSI metadata e.g. Aperio, Hamamatsu');
            $table->string('scanner_model', 100)->nullable()->after('scanner_vendor')
                ->comment('Scanner model/ID extracted from WSI metadata e.g. AT2, CS2');
        });
    }

    public function down(): void
    {
        Schema::table('slide_verifications', function (Blueprint $table) {
            $table->dropColumn(['scanner_vendor', 'scanner_model']);
        });
    }
};
