<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers_names', function (Blueprint $table) {
            $table->id();

            $table->string('name', 150);                         // e.g. "Hostinger Server"
            $table->enum('type', ['local', 'external'])
                  ->default('local');                            // local = same machine, external = remote API
            $table->string('api_url', 500)->nullable();          // Base URL for external server API
            $table->text('api_key')->nullable();                 // Encrypted API key for external servers
            $table->string('host', 255)->nullable();             // IP / hostname (for display only)
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servers_names');
    }
};
