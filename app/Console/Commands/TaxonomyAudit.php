<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\DiseaseSubtype;
use App\Models\Organ;
use App\Models\Sample;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Read-only audit of the organ-rooted taxonomy.
 *
 * Reports the three things that need a human decision after rooting the
 * taxonomy in `organs`, and never changes anything:
 *
 *   1. Clinical groups with no organ (they cannot receive diseases or be trained)
 *   2. Slides whose stored Google Drive paths predate the {organ}/{group} layout
 *      — including the exact rclone commands to realign them
 *   3. Leftovers worth cleaning: unused diseases, obvious test rows, and
 *      diseases too rare to appear in both a train and a validation split
 */
class TaxonomyAudit extends Command
{
    protected $signature = 'taxonomy:audit
                            {--min-slides=2 : Flag diseases with fewer slides than this}';

    protected $description = 'Audit the Organ → Clinical Group → Disease taxonomy (read-only)';

    public function handle(): int
    {
        $remote = config('gdrive.remote_name');

        $this->newLine();
        $this->line('<options=bold>Taxonomy audit — Organ → Clinical Group → Disease</>');
        $this->line(str_repeat('─', 78));

        $issues = 0;

        // ── 1. Groups with no organ ───────────────────────────────────────────
        $this->newLine();
        $this->line('<options=bold>1. Clinical groups with no organ</>');

        $unrooted = Category::unrooted()->withCount(['samples', 'diseaseSubtypes'])->get();
        if ($unrooted->isEmpty()) {
            $this->info('   None — every clinical group is rooted in an organ.');
        } else {
            $issues += $unrooted->count();
            $this->warn('   ' . $unrooted->count() . ' group(s) cannot be used until an organ is assigned:');
            $this->table(
                ['id', 'group', 'slides', 'diseases'],
                $unrooted->map(fn($c) => [
                    $c->id, $c->label_en, $c->samples_count, $c->disease_subtypes_count,
                ])->all()
            );
            $this->line('   Fix: Settings → Categories → edit each group and pick its organ.');
        }

        // ── 2. Drive paths still on the old {category} layout ─────────────────
        $this->newLine();
        $this->line('<options=bold>2. Google Drive paths predating the {organ}/{group} layout</>');

        $samples = Sample::with(['dataSource', 'organ', 'category', 'patientCase'])
            ->where(function ($q) {
                $q->whereNotNull('storage_path')
                  ->orWhereNotNull('tiles_gdrive_path')
                  ->orWhereNotNull('features_gdrive_path');
            })
            ->get();

        $drift = [];
        foreach ($samples as $s) {
            $organSlug = Str::slug($s->organ?->name ?? '');
            if ($organSlug === '') {
                continue;
            }
            foreach (['storage_path' => 'WSI', 'tiles_gdrive_path' => 'patches', 'features_gdrive_path' => 'features'] as $col => $kind) {
                $path = $s->{$col};
                if (! $path) {
                    continue;
                }
                // The organ segment is what the new convention adds; if no path
                // segment matches the organ, the folder is on the old layout.
                $segments = array_map(fn($p) => Str::slug($p), explode('/', $path));
                if (! in_array($organSlug, $segments, true)) {
                    $drift[] = [
                        'sample' => $s->id,
                        'kind'   => $kind,
                        'column' => $col,
                        'organ'  => $s->organ?->name,
                        'path'   => $path,
                    ];
                }
            }
        }

        if (empty($drift)) {
            $this->info('   None — every stored path already carries its organ.');
        } else {
            $issues += count($drift);
            $this->warn('   ' . count($drift) . ' stored path(s) still use the old layout.');
            $this->line('   These keep working: the path is read from the database, not recomputed.');
            $this->line('   Only NEW extractions land under the organ-rooted layout, so the Drive');
            $this->line('   tree will be mixed until these are moved.');
            $this->newLine();
            $this->table(
                ['sample', 'kind', 'organ', 'stored path'],
                array_map(fn($d) => [$d['sample'], $d['kind'], $d['organ'], $d['path']], $drift)
            );

            $this->line('   <options=bold>To realign (review each before running):</>');

            $undecidable = 0;
            foreach ($drift as $d) {
                $sample = $samples->firstWhere('id', $d['sample']);
                $new    = $this->targetPath($sample, $d['column']);

                if ($new === null) {
                    // WSI folder names embed a random UUID generated at upload
                    // time, so the destination cannot be derived — and guessing
                    // one would move real data somewhere wrong.
                    $undecidable++;
                    continue;
                }

                $this->line("     rclone moveto \"{$remote}:{$d['path']}\" \"{$remote}:{$new}\"");
                $this->line("       then: UPDATE samples SET {$d['column']}='{$new}' WHERE id={$d['sample']};");
            }

            if ($undecidable > 0) {
                $this->newLine();
                $this->line("   {$undecidable} WSI folder(s) are deliberately left alone: their folder name");
                $this->line('   contains a UUID minted at upload time, so no destination can be derived.');
                $this->line('   They need no move — the path is stored per slide and resolves correctly.');
            }

            $this->newLine();
            $this->line('   <fg=yellow>Nothing above was executed. Moving Drive folders is irreversible —</>');
            $this->line('   <fg=yellow>run the commands yourself once you have reviewed them.</>');
        }

        // ── 3. Cleanup candidates ─────────────────────────────────────────────
        $this->newLine();
        $this->line('<options=bold>3. Cleanup candidates</>');

        $minSlides = (int) $this->option('min-slides');

        $unused = DiseaseSubtype::with(['organ', 'category'])
            ->withCount('samples')
            ->having('samples_count', '=', 0)
            ->get();

        if ($unused->isNotEmpty()) {
            $this->warn('   ' . $unused->count() . ' disease(s) have no slides at all:');
            $this->table(
                ['id', 'organ', 'group', 'disease'],
                $unused->map(fn($d) => [
                    $d->id, $d->organ?->name ?? '—', $d->category?->label_en ?? '—', $d->name,
                ])->all()
            );
        }

        $thin = DiseaseSubtype::with(['organ', 'category'])
            ->withCount('samples')
            ->having('samples_count', '>', 0)
            ->having('samples_count', '<', $minSlides)
            ->get();

        if ($thin->isNotEmpty()) {
            $this->warn('   ' . $thin->count() . " disease(s) have fewer than {$minSlides} slides — "
                . 'they cannot appear in both Train and Validation:');
            $this->table(
                ['id', 'organ', 'group', 'disease', 'slides'],
                $thin->map(fn($d) => [
                    $d->id, $d->organ?->name ?? '—', $d->category?->label_en ?? '—', $d->name, $d->samples_count,
                ])->all()
            );
        }

        // Obvious placeholder rows — named, never deleted automatically.
        $needles  = ['test', 'sample', 'todo', 'tmp', 'temp', 'xxx', 'dummy'];
        $looksTest = function (?string $name) use ($needles): bool {
            $n = mb_strtolower(trim((string) $name));
            foreach ($needles as $needle) {
                if ($n !== '' && str_contains($n, $needle)) {
                    return true;
                }
            }
            return false;
        };

        $suspect = [];
        foreach (Organ::all() as $o) {
            if ($looksTest($o->name)) { $suspect[] = ['organ', $o->id, $o->name]; }
        }
        foreach (Category::with('organ')->get() as $c) {
            if ($looksTest($c->label_en)) { $suspect[] = ['group', $c->id, $c->qualified_name]; }
        }
        foreach (DiseaseSubtype::with(['organ', 'category'])->get() as $d) {
            if ($looksTest($d->name)) { $suspect[] = ['disease', $d->id, $d->qualified_name]; }
        }

        if (! empty($suspect)) {
            $this->warn('   ' . count($suspect) . ' row(s) look like leftover test data:');
            $this->table(['level', 'id', 'name'], $suspect);
            $this->line('   <fg=yellow>Not deleted. Some may be attached to real slides or Drive folders —</>');
            $this->line('   <fg=yellow>review each in the admin portal before removing it.</>');
        }

        if ($unused->isEmpty() && $thin->isEmpty() && empty($suspect)) {
            $this->info('   Nothing to clean up.');
        }

        // ── Summary ───────────────────────────────────────────────────────────
        $this->newLine();
        $this->line(str_repeat('─', 78));
        $this->line(sprintf(
            '  organs: %d   clinical groups: %d   diseases: %d   slides: %d',
            Organ::count(), Category::count(), DiseaseSubtype::count(), Sample::count()
        ));
        $this->line($issues === 0
            ? '  <fg=green>No blocking taxonomy issues.</>'
            : "  <fg=yellow>{$issues} item(s) need a decision — see above.</>");
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Where a stored path SHOULD live under the organ-rooted layout.
     *
     * Derived from the sample's own metadata using the same formula as the
     * extraction jobs — never by shuffling segments of the old path. Legacy
     * paths come in several shapes (some carry a username, sliced_slides has
     * had two different segment orders), so a positional guess would happily
     * produce a plausible-looking destination that is simply wrong, and the
     * caller would then rclone real data into it.
     *
     * Returns null when the destination is genuinely underivable.
     */
    private function targetPath(Sample $sample, string $column): ?string
    {
        $root   = rtrim((string) config('gdrive.root_folder', 'samples'), '/');
        $source = Str::slug($sample->dataSource?->name ?? 'unknown_source');
        $organ  = Str::slug($sample->organ?->name ?? 'unknown_organ');
        $group  = Str::slug($sample->category?->label_en ?? 'unknown_group');
        $caseId = $sample->patientCase?->case_id ?? 'no_case';

        $magFolder = $sample->magnification_id
            ? \App\Models\Magnification::whereKey($sample->magnification_id)->value('folder_name')
            : null;
        $sizePx = $sample->patch_size_id
            ? \App\Models\PatchSize::whereKey($sample->patch_size_id)->value('size_px')
            : null;

        if ($column === 'tiles_gdrive_path') {
            if (! $magFolder || ! $sizePx) {
                return null;
            }
            return implode('/', [
                $root, 'sliced_slides', $magFolder, $source, $organ, $group, $caseId,
                "sample_{$sample->id}_{$sizePx}px",
            ]);
        }

        if ($column === 'features_gdrive_path') {
            $modelFolder = $sample->feature_extraction_ai_model_id
                ? \App\Models\AiModel::whereKey($sample->feature_extraction_ai_model_id)->value('name')
                : null;
            if (! $modelFolder || ! $magFolder || ! $sizePx) {
                return null;
            }
            return implode('/', [
                $root, 'features', $modelFolder, $magFolder, $source, $organ, $group, $caseId,
                "sample_{$sample->id}_{$sizePx}px",
            ]);
        }

        // storage_path (the WSI folder) ends in a UUID minted at upload time.
        return null;
    }
}
