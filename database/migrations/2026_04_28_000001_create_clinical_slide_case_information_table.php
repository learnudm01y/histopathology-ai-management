<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinical_slide_case_information', function (Blueprint $table) {
            $table->id();

            // Link to samples table (populated when matching slide is imported)
            $table->foreignId('sample_id')->nullable()->constrained('samples')->nullOnDelete();

            // ─── Top-Level Case Identity ─────────────────────────────────────
            $table->string('case_id', 36)->unique();           // GDC UUID  e.g. 0045349c-...
            $table->string('submitter_id', 50)->index();       // e.g. TCGA-A1-A0SB
            $table->string('project_id', 50)->nullable();      // from project.project_id  e.g. TCGA-BRCA
            $table->string('disease_type', 150)->nullable();
            $table->string('primary_site', 100)->nullable();
            $table->string('index_date', 50)->nullable();
            $table->string('consent_type', 100)->nullable();
            $table->integer('days_to_consent')->nullable();
            $table->string('lost_to_followup', 10)->nullable();
            $table->string('state', 30)->nullable();
            $table->string('updated_datetime', 50)->nullable(); // ISO-8601 string from GDC

            // ─── Demographic ─────────────────────────────────────────────────
            $table->string('demographic_id', 36)->nullable();
            $table->string('gender', 30)->nullable();
            $table->string('sex_at_birth', 30)->nullable();
            $table->string('race', 100)->nullable();
            $table->string('ethnicity', 100)->nullable();
            $table->unsignedSmallInteger('age_at_index')->nullable();           // years
            $table->integer('days_to_birth')->nullable();                        // negative
            $table->string('vital_status', 30)->nullable();
            $table->string('age_is_obfuscated', 10)->nullable();                // "true"/"false"
            $table->string('country_of_residence_at_enrollment', 100)->nullable();
            $table->string('demographic_state', 30)->nullable();
            $table->string('demographic_updated_datetime', 50)->nullable();

            // ─── Primary Diagnosis ───────────────────────────────────────────
            $table->string('diagnosis_id', 36)->nullable();
            $table->string('diagnosis_submitter_id', 100)->nullable();
            $table->string('primary_diagnosis', 200)->nullable();
            $table->string('tissue_or_organ_of_origin', 150)->nullable();
            $table->string('site_of_resection_or_biopsy', 150)->nullable();
            $table->string('icd_10_code', 20)->nullable();
            $table->string('morphology', 30)->nullable();
            $table->string('classification_of_tumor', 50)->nullable();
            $table->string('diagnosis_is_primary_disease', 10)->nullable();     // "true"/"false"
            $table->string('method_of_diagnosis', 100)->nullable();
            $table->string('synchronous_malignancy', 20)->nullable();
            $table->string('laterality', 30)->nullable();
            $table->string('prior_malignancy', 20)->nullable();
            $table->string('prior_treatment', 20)->nullable();
            $table->string('metastasis_at_diagnosis', 100)->nullable();
            $table->unsignedSmallInteger('year_of_diagnosis')->nullable();
            $table->integer('days_to_diagnosis')->nullable();
            $table->decimal('days_to_last_follow_up', 10, 1)->nullable();
            $table->unsignedInteger('age_at_diagnosis')->nullable();            // in days (GDC format)
            $table->string('diagnosis_state', 30)->nullable();
            $table->string('diagnosis_updated_datetime', 50)->nullable();

            // ─── AJCC Staging ────────────────────────────────────────────────
            $table->string('ajcc_pathologic_stage', 50)->nullable();
            $table->string('ajcc_pathologic_t', 20)->nullable();
            $table->string('ajcc_pathologic_n', 20)->nullable();
            $table->string('ajcc_pathologic_m', 20)->nullable();
            $table->string('ajcc_staging_system_edition', 20)->nullable();

            // ─── Pathology Details (from primary diagnosis) ──────────────────
            $table->string('pathology_detail_id', 36)->nullable();
            $table->string('pathology_detail_submitter_id', 100)->nullable();
            $table->string('consistent_pathology_review', 10)->nullable();
            $table->unsignedSmallInteger('lymph_nodes_positive')->nullable();
            $table->unsignedSmallInteger('lymph_nodes_tested')->nullable();
            $table->string('pathology_detail_state', 30)->nullable();
            $table->string('pathology_detail_created_datetime', 50)->nullable();
            $table->string('pathology_detail_updated_datetime', 50)->nullable();

            // ─── JSON — Nested / Multi-value Fields ─────────────────────────
            // sites_of_involvement — array of strings
            $table->json('sites_of_involvement')->nullable();

            // diagnoses — full array (may contain multiple diagnoses per case)
            $table->json('diagnoses')->nullable();

            // treatments — array of treatments extracted from the primary diagnosis
            $table->json('treatments')->nullable();

            // follow_ups — full follow_ups array (includes molecular_tests and
            //              other_clinical_attributes nested within each entry)
            $table->json('follow_ups')->nullable();

            // molecular_tests — all molecular tests flattened across all follow_ups
            $table->json('molecular_tests')->nullable();

            // other_clinical_attributes — flattened across all follow_ups
            //   (e.g. menopause_status)
            $table->json('other_clinical_attributes')->nullable();

            // raw_json — original complete case object as imported
            $table->json('raw_json')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_slide_case_information');
    }
};
