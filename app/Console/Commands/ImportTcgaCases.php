<?php

namespace App\Console\Commands;

use App\Models\Sample;
use App\Services\GdcCaseImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Pulls case records straight from the GDC API and files them.
 *
 *   php artisan tcga:import-cases --orphans --dry-run
 *   php artisan tcga:import-cases --orphans
 *   php artisan tcga:import-cases --case=TCGA-AC-A23E --case=TCGA-B6-A0IE
 *
 * Slides arrive from a manifest before their patients exist, so a freshly
 * imported slide sits with `case_id` null until someone supplies the clinical
 * export. --orphans closes that loop without anyone supplying anything: it reads
 * the patient barcodes off the unlinked slides, asks GDC for those cases, and
 * files them. Each case adopts its waiting slides as it lands.
 *
 * Re-running is free. Cases are keyed on the GDC UUID, so an existing case is
 * refreshed rather than duplicated — which also makes this the way to pull a
 * fresher copy of clinical records we already hold. That matters here: the older
 * records in this database came from a legacy export that carried no year of
 * diagnosis and no stage, and the absence itself tracked the diagnosis closely
 * enough to be mistaken for signal. Re-importing over them replaces that gap
 * with what GDC holds today.
 */
class ImportTcgaCases extends Command
{
    protected $signature = 'tcga:import-cases
        {--orphans : Take the patients of every sample that has no case}
        {--case=* : Explicit patient barcodes, e.g. TCGA-AC-A23E}
        {--chunk=90 : Patients per API request}
        {--dry-run : List the patients that would be fetched, and stop}';

    protected $description = 'Fetch case + clinical records from the GDC API and file them';

    private const ENDPOINT = 'https://api.gdc.cancer.gov/cases';

    /** Everything the clinical table has columns for. */
    private const EXPAND = 'demographic,diagnoses,diagnoses.treatments,diagnoses.pathology_details'
        . ',follow_ups,follow_ups.molecular_tests,follow_ups.other_clinical_attributes,project';

    public function handle(GdcCaseImporter $importer): int
    {
        $patients = $this->collectPatients();

        if ($patients === []) {
            $this->info('  Nothing to fetch — no orphan slides and no --case given.');
            return self::SUCCESS;
        }

        $this->line('');
        $this->info('  tcga:import-cases');
        $this->line('  ────────────────────────────────────────────────');
        $this->line(sprintf('  patients   %d', count($patients)));

        if ($this->option('dry-run')) {
            $this->warn('  DRY RUN — nothing is fetched and nothing is written.');
            foreach (array_slice($patients, 0, 20) as $p) {
                $this->line('    ' . $p);
            }
            if (count($patients) > 20) {
                $this->line(sprintf('    … and %d more', count($patients) - 20));
            }
            $this->line('');
            return self::SUCCESS;
        }

        $totals  = [];
        $fetched = 0;

        foreach (array_chunk($patients, max(1, (int) $this->option('chunk'))) as $i => $chunk) {
            $this->line(sprintf('  · batch %d — asking GDC for %d patients', $i + 1, count($chunk)));

            try {
                $hits = $this->fetchCases($chunk);
            } catch (\Throwable $e) {
                $this->error('    ' . $e->getMessage());
                return self::FAILURE;
            }

            $fetched += count($hits);
            if ($hits === []) {
                $this->warn('    GDC returned no records for this batch');
                continue;
            }

            foreach ($importer->importCases($hits) as $key => $n) {
                $totals[$key] = ($totals[$key] ?? 0) + $n;
            }
        }

        $missing = count($patients) - $fetched;

        $this->line('');
        $this->line('  ────────────────────────────────────────────────');
        $this->line(sprintf('  records from GDC    %d', $fetched));
        if ($missing > 0) {
            $this->warn(sprintf('  not found in GDC    %d', $missing));
        }
        $this->line(sprintf('  cases created       %d', $totals['cases_created']    ?? 0));
        $this->line(sprintf('  cases refreshed     %d', $totals['cases_updated']    ?? 0));
        $this->line(sprintf('  clinical created    %d', $totals['clinical_created'] ?? 0));
        $this->line(sprintf('  clinical refreshed  %d', $totals['clinical_updated'] ?? 0));
        $this->line(sprintf('  <fg=green>slides linked       %d</>', $totals['samples_linked'] ?? 0));
        $this->line('');

        return self::SUCCESS;
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return list<string> distinct patient barcodes
     */
    private function collectPatients(): array
    {
        $patients = array_map(
            static fn ($c) => strtoupper(trim((string) $c)),
            (array) $this->option('case')
        );

        if ($this->option('orphans')) {
            Sample::whereNull('case_id')
                ->whereNotNull('entity_submitter_id')
                ->pluck('entity_submitter_id')
                ->each(function (string $entity) use (&$patients) {
                    // "TCGA-AC-A23E-01Z-00-DX1" → "TCGA-AC-A23E"
                    $parts = explode('-', $entity);
                    if (count($parts) >= 3) {
                        $patients[] = strtoupper(implode('-', array_slice($parts, 0, 3)));
                    }
                });
        }

        return array_values(array_unique(array_filter($patients)));
    }

    /**
     * @param  list<string>  $patients
     * @return array<int, array<string, mixed>>
     */
    private function fetchCases(array $patients): array
    {
        $response = Http::timeout(180)
            ->retry(3, 2000)
            ->acceptJson()
            ->post(self::ENDPOINT, [
                'filters' => [
                    'op'      => 'in',
                    'content' => ['field' => 'cases.submitter_id', 'value' => $patients],
                ],
                'expand' => self::EXPAND,
                'format' => 'JSON',
                'size'   => (string) (count($patients) + 10),
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException("GDC returned HTTP {$response->status()}");
        }

        return $response->json('data.hits') ?? [];
    }
}
