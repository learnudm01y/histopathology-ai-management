<?php

namespace App\Console\Commands;

use App\Models\DiseaseSubtype;
use App\Models\Sample;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * File slides against the exact disease their own clinical record already names.
 *
 * A slide imported with only a coarse label ("malignant") is a slide whose
 * diagnosis was never lost — GDC shipped it in the case's clinical record all
 * along. This command reads that record and moves the slide down to the leaf
 * the taxonomy already has for it.
 *
 * The key is the ICD-O-3 morphology code, not the free-text diagnosis: the code
 * is a controlled vocabulary, while the text arrives spelled a dozen ways
 * ("Infiltrating Lobular Carcinoma", "Lobular/Ductal", "Other, specify") and
 * cannot be matched reliably.
 *
 * Only unambiguous codes are mapped. A mixed tumour (8522/3 duct AND lobular,
 * 8523/3, 8524/3) is a distinct clinical entity, not a variant of either parent
 * class — forcing it into one would put a slide the model must not learn as IDC
 * into the IDC class. Those are reported and left where they are.
 *
 * Safety rules, in order:
 *   • dry run unless --apply is passed
 *   • a slide is only moved when it currently sits on NOTHING or on an ANCESTOR
 *     of the target (the coarse parent it needs refining from). A slide already
 *     filed against a different leaf is a conflict: it is reported, never
 *     overwritten, because that label may be a human decision.
 *   • the target disease must belong to the slide's own organ, so a mapping can
 *     never pull a slide across the organ boundary the taxonomy guarantees.
 */
class RefileDiseasesFromClinical extends Command
{
    protected $signature = 'taxonomy:refile-from-clinical
                            {--apply : Write the changes; without this the command only reports}
                            {--organ= : Restrict to one organ id}';

    protected $description = 'File slides against the exact disease named in their GDC clinical record';

    /**
     * ICD-O-3 morphology code → the disease name the taxonomy uses for it.
     * Deliberately narrow: only codes that name one entity outright.
     */
    private const MORPHOLOGY_MAP = [
        '8500/3' => 'IDC',   // Infiltrating duct carcinoma, NOS
        '8520/3' => 'ILC',   // Infiltrating lobular carcinoma, NOS
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $this->newLine();
        $this->line('<options=bold>Refile slides from their clinical record</>');
        $this->line(str_repeat('─', 78));
        $this->line($apply
            ? '<fg=yellow>APPLY mode — the database will be written to.</>'
            : 'Dry run — nothing is written. Re-run with --apply to commit.');

        // sample → cases.case_id (GDC UUID) → clinical record.
        $query = Sample::query()
            ->join('cases', 'cases.id', '=', 'samples.case_id')
            ->join('clinical_slide_case_information as clin', 'clin.case_id', '=', 'cases.case_id')
            ->whereNotNull('clin.morphology')
            ->select([
                'samples.id', 'samples.organ_id', 'samples.category_id',
                'samples.disease_subtype_id', 'samples.file_name',
                'clin.morphology', 'clin.primary_diagnosis',
            ]);

        if ($this->option('organ')) {
            $query->where('samples.organ_id', (int) $this->option('organ'));
        }

        $rows = $query->get();
        $this->line('  slides with a clinical morphology code: ' . $rows->count());

        $moved      = [];   // target name => count
        $already    = 0;
        $conflicts  = [];
        $unmapped   = [];   // morphology => count
        $noTarget   = [];   // "organ:name" => count
        $updates    = [];   // sample id => [disease_subtype_id, disease_subtype]

        // Ancestor ids per disease, so "is the slide on a coarser parent?" is a
        // lookup rather than a query per slide.
        $ancestorCache = [];

        foreach ($rows as $row) {
            $code = trim((string) $row->morphology);

            if (! isset(self::MORPHOLOGY_MAP[$code])) {
                $unmapped[$code] = ($unmapped[$code] ?? 0) + 1;
                continue;
            }

            $name   = self::MORPHOLOGY_MAP[$code];
            $target = DiseaseSubtype::where('organ_id', $row->organ_id)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->first();

            if (! $target) {
                $key = $row->organ_id . ':' . $name;
                $noTarget[$key] = ($noTarget[$key] ?? 0) + 1;
                continue;
            }

            if ((int) $row->disease_subtype_id === (int) $target->id) {
                $already++;
                continue;
            }

            if (! isset($ancestorCache[$target->id])) {
                $ancestorCache[$target->id] = array_map(
                    fn (DiseaseSubtype $a) => $a->id,
                    $target->ancestors()
                );
            }

            // Only refine downwards: from nothing, or from a coarser parent.
            $current = $row->disease_subtype_id === null ? null : (int) $row->disease_subtype_id;
            if ($current !== null && ! in_array($current, $ancestorCache[$target->id], true)) {
                $conflicts[] = [
                    $row->id,
                    mb_substr((string) $row->file_name, 0, 34),
                    DiseaseSubtype::find($current)?->name ?? ('#' . $current),
                    $code . ' → ' . $name,
                ];
                continue;
            }

            $updates[$row->id] = $target;
            $moved[$name] = ($moved[$name] ?? 0) + 1;
        }

        // ── What the run would do ────────────────────────────────────────────
        $this->newLine();
        $this->line('<options=bold>To refile</>');
        if ($moved === []) {
            $this->info('   Nothing to move — every slide already sits on the disease its record names.');
        } else {
            $this->table(['disease', 'slides'], collect($moved)->map(fn ($n, $d) => [$d, $n])->values()->all());
        }
        $this->line('   already correct: ' . $already);

        if ($unmapped !== []) {
            $this->newLine();
            $this->line('<options=bold>Left alone — no unambiguous mapping</>');
            arsort($unmapped);
            $this->table(
                ['morphology', 'slides'],
                collect($unmapped)->map(fn ($n, $c) => [$c, $n])->values()->all()
            );
            $this->line('   Mixed and unreported tumours are distinct entities; they need a human decision.');
        }

        if ($noTarget !== []) {
            $this->newLine();
            $this->warn('   Mapped, but the organ has no such disease yet (add it first):');
            foreach ($noTarget as $key => $n) {
                [$organId, $name] = explode(':', $key, 2);
                $this->line("     organ #{$organId} needs \"{$name}\" — {$n} slide(s) waiting");
            }
        }

        if ($conflicts !== []) {
            $this->newLine();
            $this->warn('   Conflicts — already filed against another disease, left untouched:');
            $this->table(['sample', 'file', 'currently', 'record says'], $conflicts);
        }

        if (! $apply || $updates === []) {
            $this->newLine();
            $this->line($updates === [] ? 'Nothing to do.' : 'Dry run finished — re-run with --apply to commit.');
            return self::SUCCESS;
        }

        // ── Write ────────────────────────────────────────────────────────────
        // The legacy free-text column is kept in step with the FK: the taxonomy
        // editor syncs it on rename and the export and verifier both read it, so
        // leaving it saying "malignant" would contradict the row's own label.
        $byTarget = [];
        foreach ($updates as $sampleId => $target) {
            $byTarget[$target->id]['name']  = $target->name;
            $byTarget[$target->id]['ids'][] = $sampleId;
        }

        $written = 0;
        DB::transaction(function () use ($byTarget, &$written) {
            foreach ($byTarget as $targetId => $spec) {
                $written += Sample::whereIn('id', $spec['ids'])->update([
                    'disease_subtype_id' => $targetId,
                    'disease_subtype'    => $spec['name'],
                ]);
            }
        });

        $this->newLine();
        $this->info("   Refiled {$written} slide(s).");

        return self::SUCCESS;
    }
}
