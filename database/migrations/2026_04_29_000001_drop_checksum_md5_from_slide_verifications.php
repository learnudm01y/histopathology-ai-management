<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('slide_verifications', 'checksum_md5')) {
            return; // column was never added on this environment
        }

        Schema::table('slide_verifications', function (Blueprint $table) {
            $indexes = Schema::getIndexListing('slide_verifications');
            if (in_array('slide_verifications_checksum_md5_index', $indexes)) {
                $table->dropIndex(['checksum_md5']);
            }
            $table->dropColumn('checksum_md5');
        });
    }

    public function down(): void
    {
        Schema::table('slide_verifications', function (Blueprint $table) {
            $table->string('checksum_md5', 32)->nullable()->index()
                ->comment('MD5 hash for duplicate detection regardless of filename or path');
        });
    }
};
