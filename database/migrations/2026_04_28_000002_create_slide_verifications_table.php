<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slide_verifications', function (Blueprint $table) {
            $table->id();

            // Link to the samples table
            $table->foreignId('sample_id')->nullable()->constrained('samples')->nullOnDelete();

            // ─── Identity & Linkage ──────────────────────────────────────────
            // • Unique slide identifier — slide_id
            $table->string('slide_id', 100)->nullable()->unique()
                ->comment('Unique identifier for the slide to prevent duplication');

            // • File exists — file_path
            $table->string('file_path', 500)->nullable()
                ->comment('Full storage path; presence confirms the physical file exists');

            // • Patient identifier exists — patient_id
            $table->string('patient_id', 100)->nullable()->index()
                ->comment('Links the slide to its patient source; prevents cross-patient mixing');

            // • Case/sample identifier linked to patient — case_id
            $table->string('case_id', 100)->nullable()->index()
                ->comment('Connects the slide to its clinical case within the patient record');

            // • Project/source identified — project_id
            $table->string('project_id', 50)->nullable()
                ->comment('Dataset or cohort origin e.g. TCGA-BRCA; enables traceability');

            // ─── File & Format Checks ────────────────────────────────────────
            // • Supported file format — file_extension
            $table->string('file_extension', 20)->nullable()
                ->comment('File format extension e.g. svs, ndpi, tiff; must be pipeline-compatible');

            // • File size is reasonable — file_size_mb
            $table->decimal('file_size_mb', 12, 3)->nullable()
                ->comment('File size in MB; very small values indicate incomplete or invalid WSI');

            // ─── File Health Statuses ────────────────────────────────────────
            // • File can be opened successfully — open_slide_status
            $table->enum('open_slide_status', ['passed', 'failed', 'not_checked'])->default('not_checked')
                ->comment('Whether OpenSlide (or equivalent) can load the file without errors');

            // • File is not corrupted — file_integrity_status
            $table->enum('file_integrity_status', ['passed', 'failed', 'not_checked'])->default('not_checked')
                ->comment('Full and partial read integrity; corrupt files may open but fail mid-read');

            // • No read failure when sampling regions — read_test_status
            $table->enum('read_test_status', ['passed', 'failed', 'not_checked'])->default('not_checked')
                ->comment('Spot-check reads across multiple levels to confirm structural reliability');

            // ─── WSI Technical Properties ────────────────────────────────────
            // • Multi-resolution levels exist — level_count
            $table->unsignedSmallInteger('level_count')->nullable()
                ->comment('Number of pyramid levels; >1 confirms the file is a proper pyramidal WSI');

            // • Slide dimensions are sufficient — slide_width, slide_height
            $table->unsignedBigInteger('slide_width')->nullable()
                ->comment('Width in pixels at highest resolution; low values may indicate invalid slides');
            $table->unsignedBigInteger('slide_height')->nullable()
                ->comment('Height in pixels at highest resolution');

            // • MPP-X value exists — mpp_x
            $table->decimal('mpp_x', 10, 6)->nullable()
                ->comment('Microns per pixel along X-axis; required for physical scale interpretation');

            // • MPP-Y value exists — mpp_y
            $table->decimal('mpp_y', 10, 6)->nullable()
                ->comment('Microns per pixel along Y-axis; ensures no uneven scaling or distortion');

            // • Resolution is suitable — magnification_power (objective_power)
            $table->decimal('magnification_power', 5, 2)->nullable()
                ->comment('Scan magnification e.g. 20.0 or 40.0; low values yield insufficient detail');

            // ─── Clinical / Sample Metadata ──────────────────────────────────
            // • Sample type is appropriate — sample_type
            $table->string('sample_type', 100)->nullable()
                ->comment('Type of sample e.g. Diagnostic Slide; incompatible types are excluded');

            // • Stain type is appropriate — stain_type
            $table->string('stain_type', 100)->nullable()
                ->comment('Staining method e.g. H&E; affects color distribution and model compatibility');

            // • Gender available for clinical tracking — gender
            $table->string('gender', 30)->nullable()
                ->comment('Patient gender; used for clinical and epidemiological enrichment');

            // • Age available for clinical tracking — age_at_index / age_at_diagnosis
            $table->unsignedSmallInteger('age_at_index')->nullable()
                ->comment('Patient age at index date in years; supports clinical correlation');

            // • Label exists (for supervised training) — label
            $table->string('label', 100)->nullable()
                ->comment('Supervised learning target e.g. tumor / normal; required for training pipelines');

            // • Label is not ambiguous — label_status
            $table->enum('label_status', ['valid', 'ambiguous', 'unknown'])->default('unknown')
                ->comment('Clarity of the label; ambiguous labels introduce noise into training');

            // ─── Tissue Quality Metrics ──────────────────────────────────────
            // • Sufficient tissue present — tissue_area_percent
            $table->decimal('tissue_area_percent', 5, 2)->nullable()
                ->comment('Percentage of slide covered by tissue; very low values yield no usable patches');

            // • Sufficient number of tissue patches — tissue_patch_count
            $table->unsignedInteger('tissue_patch_count')->nullable()
                ->comment('Estimated usable patch count after segmentation; too few means poor representation');

            // • No severe artifacts — artifact_score
            $table->decimal('artifact_score', 5, 4)->nullable()
                ->comment('0–1 score for visual artifacts (folds, pen marks, bubbles); high = unreliable');

            // • Blur is within acceptable range — blur_score
            $table->decimal('blur_score', 5, 4)->nullable()
                ->comment('0–1 sharpness score; high blur harms cellular structure visibility');

            // • Background does not dominate — background_ratio
            $table->decimal('background_ratio', 5, 4)->nullable()
                ->comment('Fraction of slide that is empty background; high ratio = not useful for analysis');

            // ─── Overall Verification Result ─────────────────────────────────
            $table->enum('verification_status', ['pending', 'passed', 'failed'])->default('pending')
                ->comment('Aggregate result of all verification checks for this slide');

            $table->timestamp('verified_at')->nullable()
                ->comment('Timestamp when verification was last completed');

            $table->text('notes')->nullable()
                ->comment('Free-text notes or rejection reasons from the verification process');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slide_verifications');
    }
};
