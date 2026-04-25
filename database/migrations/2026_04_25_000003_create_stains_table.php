<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stains', function (Blueprint $table) {
            $table->id();

            // Identification
            $table->string('name', 150)->unique();           // e.g. "Hematoxylin & Eosin"
            $table->string('abbreviation', 30)->unique();    // e.g. "H&E"

            // Classification
            $table->enum('stain_type', [
                'routine',      // H&E — the standard stain
                'special',      // Masson, PAS, Alcian Blue, Congo Red, Silver, Oil Red O…
                'IHC',          // Immunohistochemistry (ER, PR, HER2, Ki67…)
                'ISH',          // In-Situ Hybridisation (FISH, CISH…)
                'fluorescent',  // IF / FISH with fluorescent labels
                'cytology',     // Papanicolaou, Giemsa (smear/cytology slides)
                'other',
            ])->default('routine');

            // For IHC/ISH: optional marker/antibody/probe name (e.g. "ER", "HER2", "EGFR")
            $table->string('marker', 100)->nullable();

            // Description & administrative
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stains');
    }
};
