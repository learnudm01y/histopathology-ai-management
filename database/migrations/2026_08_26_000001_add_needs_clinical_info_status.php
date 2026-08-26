<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the `needs_clinical_info` outcome to slide verification and to sample
 * quality.
 *
 * A slide whose only gap is the patient / case record used to be reported as
 * `failed` (patient_id and case_id were plain presence checks), which put a
 * technically perfect slide in the same bucket as a corrupted file and made it
 * look permanently rejected. It must not be reported as `passed` either: a
 * slide with no case behind it cannot be trained on, because its label has no
 * provenance.
 *
 * The new value sits between the two — the slide is held, not rejected, and the
 * verification note lists exactly which fields a human has to fill in.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->setEnum(
            'slide_verifications',
            'verification_status',
            ['pending', 'passed', 'failed', 'needs_clinical_info'],
            'pending'
        );

        $this->setEnum(
            'samples',
            'quality_status',
            ['passed', 'rejected', 'needs_review', 'pending', 'needs_clinical_info'],
            'pending'
        );
    }

    public function down(): void
    {
        // Nothing may be left carrying a value the narrower enum cannot hold.
        DB::table('slide_verifications')
            ->where('verification_status', 'needs_clinical_info')
            ->update(['verification_status' => 'pending']);

        DB::table('samples')
            ->where('quality_status', 'needs_clinical_info')
            ->update(['quality_status' => 'pending']);

        $this->setEnum(
            'slide_verifications',
            'verification_status',
            ['pending', 'passed', 'failed'],
            'pending'
        );

        $this->setEnum(
            'samples',
            'quality_status',
            ['passed', 'rejected', 'needs_review', 'pending'],
            'pending'
        );
    }

    /**
     * Rewrite an ENUM column's value set.
     *
     * MySQL/MariaDB has no portable Schema builder call for this, and doctrine
     * /dbal refuses to introspect ENUM at all, so the column is redefined with
     * raw SQL. On any other driver (sqlite in tests) the column is a plain
     * string and there is nothing to do.
     *
     * @param  array<int, string>  $values
     */
    private function setEnum(string $table, string $column, array $values, string $default): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        // MODIFY replaces the whole column definition, so an existing COMMENT
        // is dropped unless it is repeated here.
        $comment = DB::selectOne(
            'SELECT column_comment AS c FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column]
        )?->c;

        $list = implode(', ', array_map(fn (string $v) => "'" . addslashes($v) . "'", $values));

        DB::statement(sprintf(
            'ALTER TABLE `%s` MODIFY `%s` ENUM(%s) NOT NULL DEFAULT %s%s',
            $table,
            $column,
            $list,
            "'" . addslashes($default) . "'",
            $comment ? " COMMENT '" . addslashes($comment) . "'" : ''
        ));
    }
};
