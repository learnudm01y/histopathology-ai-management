<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\FeatureExtractionJob;
use App\Jobs\PatchExtractionJob;
use App\Jobs\TrainingJob;
use App\Models\AiModel;
use App\Models\Sample;
use App\Models\ServerName;
use App\Models\TrainingRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OperationsController extends Controller
{
    /**
     * Dispatch patch extraction for the selected samples.
     *
     * Expects POST body:
     *   sample_ids[]   — array of sample IDs to process
     *   server_id      — ID from servers_names
     *   patch_size_id  — ID from patch_sizes
     *   magnification_id — ID from magnifications
     */
    public function dispatchPatchExtraction(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'sample_ids'       => ['required', 'array', 'min:1'],
            'sample_ids.*'     => ['integer', 'exists:samples,id'],
            'server_id'        => ['required', 'integer', 'exists:servers_names,id'],
            'patch_size_id'    => ['required', 'integer', 'exists:patch_sizes,id'],
            'magnification_id' => ['required', 'integer', 'exists:magnifications,id'],
        ]);

        $count = 0;
        foreach ($validated['sample_ids'] as $sampleId) {
            // Mark as processing immediately so the UI reflects the queued state
            Sample::where('id', $sampleId)
                  ->whereNotIn('tiling_status', ['processing']) // avoid double-dispatch
                  ->update([
                      'tiling_status'    => 'processing',
                      'patch_server_id'  => $validated['server_id'],
                      'patch_size_id'    => $validated['patch_size_id'],
                      'magnification_id' => $validated['magnification_id'],
                  ]);

            PatchExtractionJob::dispatch(
                (int) $sampleId,
                (int) $validated['server_id'],
                (int) $validated['patch_size_id'],
                (int) $validated['magnification_id'],
            );

            $count++;
        }

        return redirect()
            ->back()
            ->with('success', "{$count} sample(s) queued for patch extraction. You can monitor progress via the Tiling Status column.");
    }

    /**
     * Dispatch feature extraction for the selected samples.
     *
     * Expects POST body:
     *   sample_ids[]     — array of sample IDs (must already have tiling_status = "done")
     *   server_id        — ID from servers_names (must be type=external for RunPod)
     *   ai_model_id      — ID from ai_models (selects which model to use, e.g. TITAN)
     */
    public function dispatchFeatureExtraction(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'sample_ids'   => ['required', 'array', 'min:1'],
            'sample_ids.*' => ['integer', 'exists:samples,id'],
            'server_id'    => ['required', 'integer', 'exists:servers_names,id'],
            'ai_model_id'  => ['required', 'integer', 'exists:ai_models,id'],
        ]);

        $count = 0;
        $skipped = 0;
        foreach ($validated['sample_ids'] as $sampleId) {
            /** @var Sample|null $sample */
            $sample = Sample::find($sampleId);

            // Only allow samples whose patches are ready
            if (!$sample || $sample->tiling_status !== 'done' || !$sample->tiles_gdrive_path) {
                $skipped++;
                continue;
            }

            $sample->update([
                'feature_extraction_status'      => 'processing',
                'feature_extraction_ai_model_id' => $validated['ai_model_id'],
                'feature_extraction_server_id'   => $validated['server_id'],
                'feature_extraction_error'       => null,
            ]);

            FeatureExtractionJob::dispatch(
                (int) $sampleId,
                (int) $validated['server_id'],
                (int) $validated['ai_model_id'],
            );

            $count++;
        }

        $msg = "{$count} sample(s) queued for feature extraction.";
        if ($skipped > 0) {
            $msg .= " {$skipped} skipped (patches not ready).";
        }

        return redirect()->back()->with('success', $msg);
    }

    /**
     * Create and dispatch a CLAM training run.
     *
     * Expects POST body:
     *   sample_ids[]       — array of sample IDs
     *   server_id          — ID of the CLAM training server (servers_names)
     *   training_head_id   — ID of the CLAM model in ai_models
     *   feature_model_id   — ID of the feature extraction model used (TITAN/Virchow2)
     *   label_type         — 'category' | 'disease_type'
     *   label_map          — JSON string mapping contiguous indices to the EXACT
     *                        values stored in the database. These are matched
     *                        case-sensitively, so use the stored spelling:
     *                          {"0": "normal", "1": "tumor"}
     *                        (categories.label_en holds lowercase values here —
     *                        "Normal"/"Malignant" match nothing and abort the run)
     *   model_type         — 'clam_sb' | 'clam_mb'
     *   epochs             — int
     *   learning_rate      — float
     *   bag_size           — int (-1 = no limit)
     *   gdrive_output_dir  — GDrive output path (optional)
     *   sample_phases[id]  — OPTIONAL manual split override (1=train 2=val 3=test).
     *                        When omitted, a stratified patient-grouped split is
     *                        computed automatically (see splitSamples()).
     *   split_ratio        — OPTIONAL "60/20/20" style ratio for the auto split
     */
    public function dispatchTraining(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'sample_ids'        => ['required', 'array', 'min:2'],
            'sample_ids.*'      => ['integer', 'exists:samples,id'],
            'server_id'         => ['required', 'integer', 'exists:servers_names,id'],
            'training_head_id'  => ['required', 'integer', 'exists:ai_models,id'],
            'feature_model_id'  => ['required', 'integer', 'exists:ai_models,id'],
            'label_type'        => ['required', 'in:category,disease_type'],
            'label_map'         => ['required', 'string'],  // JSON
            'model_type'        => ['required', 'in:clam_sb,clam_mb'],
            'epochs'            => ['required', 'integer', 'min:1', 'max:200'],
            'learning_rate'     => ['required', 'numeric', 'min:0.000001', 'max:0.1'],
            'bag_size'          => ['required', 'integer', 'min:-1'],
            'gdrive_output_dir' => ['nullable', 'string', 'max:255'],
            'sample_phases'     => ['nullable', 'array'],
            'sample_phases.*'   => ['integer', 'in:1,2,3'],
        ]);

        // ── Decode and validate label_map ─────────────────────────────────────
        $labelMap = json_decode($validated['label_map'], true);
        if (! is_array($labelMap) || count($labelMap) < 2) {
            return redirect()->back()->withErrors(['label_map' => 'Label map must have at least 2 classes.']);
        }

        // Indices are used directly as cross-entropy targets, so a gap (which the
        // UI produces when a middle label row is removed) makes the worker raise
        // "Target N is out of bounds". Reindex to a contiguous 0..n-1 range.
        $labelValues = array_values($labelMap);
        if (count($labelValues) !== count(array_unique($labelValues))) {
            return redirect()->back()->withErrors([
                'label_map' => 'Label map contains duplicate class names.',
            ]);
        }
        $labelMap = $labelValues;   // 0..n-1, contiguous by construction

        // n_classes is derived, never taken from the form: an independent value
        // could disagree with label_map and crash the worker mid-run.
        $nClasses = count($labelMap);

        // ── Eligibility ───────────────────────────────────────────────────────
        // Previously this only checked that a features file existed, so slides
        // that failed verification or were rejected on quality were fully
        // eligible — including for the test split, which invalidates the result.
        $candidates = Sample::whereIn('id', $validated['sample_ids'])
            ->where('feature_extraction_status', 'completed')
            ->whereNotNull('features_gdrive_path')
            ->where('is_usable', true)
            ->where('quality_status', 'passed')
            ->with(['slideVerification', 'patientCase', 'category'])
            ->get();

        $rejected = [];
        $eligible = $candidates->filter(function (Sample $s) use (&$rejected) {
            $v = $s->slideVerification ?? null;
            if ($v && $v->verification_status !== 'passed') {
                $rejected[] = "#{$s->id} (verification={$v->verification_status})";
                return false;
            }
            if (! $v) {
                $rejected[] = "#{$s->id} (no verification record)";
                return false;
            }
            return true;
        })->values();

        $skipped = count($validated['sample_ids']) - $eligible->count();

        if ($eligible->count() < 4) {
            $detail = $rejected ? ' Rejected: ' . implode(', ', array_slice($rejected, 0, 10)) : '';
            return redirect()->back()->withErrors([
                'sample_ids' => sprintf(
                    'Only %d of %d selected sample(s) are eligible. A run needs at least 4 '
                    . '(train and validation must each hold both classes). Eligibility requires: '
                    . 'feature extraction completed, features path set, is_usable, quality_status=passed, '
                    . 'and slide verification passed.%s',
                    $eligible->count(),
                    count($validated['sample_ids']),
                    $detail
                ),
            ]);
        }

        // ── Extraction uniformity ─────────────────────────────────────────────
        // The worker infers the feature dimension from the FIRST bag only, so a
        // run mixing 768-dim (TITAN/CONCH) and 2560-dim (Virchow2) features dies
        // on the first mismatched bag. Mixing patch sizes or magnifications is
        // worse: it trains happily on a non-comparable feature space.
        $featureModels  = $eligible->pluck('feature_extraction_ai_model_id')->unique()->filter()->values();
        $patchSizes     = $eligible->pluck('patch_size_id')->unique()->filter()->values();
        $magnifications = $eligible->pluck('magnification_id')->unique()->filter()->values();

        if ($featureModels->count() > 1) {
            return redirect()->back()->withErrors([
                'feature_model_id' => 'Selected samples were extracted with ' . $featureModels->count()
                    . ' different feature models (ai_model ids: ' . $featureModels->implode(', ')
                    . '). Feature dimensions differ between models, so one run must use exactly one.',
            ]);
        }
        if ($featureModels->count() === 1 && (int) $featureModels[0] !== (int) $validated['feature_model_id']) {
            return redirect()->back()->withErrors([
                'feature_model_id' => 'The selected feature model (id ' . $validated['feature_model_id']
                    . ') is not the model these samples were extracted with (id ' . $featureModels[0] . ').',
            ]);
        }
        if ($patchSizes->count() > 1) {
            return redirect()->back()->withErrors([
                'sample_ids' => 'Selected samples use ' . $patchSizes->count()
                    . ' different patch sizes (patch_size ids: ' . $patchSizes->implode(', ')
                    . '). One run must use a single patch size.',
            ]);
        }
        if ($magnifications->count() > 1) {
            return redirect()->back()->withErrors([
                'sample_ids' => 'Selected samples use ' . $magnifications->count()
                    . ' different magnifications (magnification ids: ' . $magnifications->implode(', ')
                    . '). One run must use a single magnification.',
            ]);
        }

        // ── Resolve each sample's class before splitting ───────────────────────
        // Splitting has to be stratified, which means the label must be known
        // here and not only inside TrainingJob.
        $labelOf   = [];
        $unmatched = [];
        foreach ($eligible as $s) {
            $raw = $validated['label_type'] === 'disease_type'
                ? ($s->patientCase?->disease_type ?? 'unknown')
                : ($s->category?->label_en ?? 'Unknown');

            $idx = array_search($raw, $labelMap, true);
            if ($idx === false) {
                $key = $raw === '' ? '<empty>' : (string) $raw;
                $unmatched[$key] = ($unmatched[$key] ?? 0) + 1;
                continue;
            }
            $labelOf[$s->id] = (int) $idx;
        }

        if (! empty($unmatched)) {
            arsort($unmatched);
            $detail = implode(', ', array_map(
                fn ($l, $n) => "\"{$l}\" ×{$n}",
                array_keys($unmatched),
                $unmatched
            ));
            return redirect()->back()->withErrors([
                'label_map' => sprintf(
                    '%d sample(s) carry a %s value that is not in label_map [%s]. Unmatched: %s. '
                    . 'Matching is case-sensitive — use the exact stored values.',
                    array_sum($unmatched),
                    $validated['label_type'],
                    implode(', ', $labelMap),
                    $detail
                ),
            ]);
        }

        // ── Split ─────────────────────────────────────────────────────────────
        $manualPhases = $validated['sample_phases'] ?? [];
        if (! empty($manualPhases)) {
            $phaseMap = [];
            foreach ($eligible as $s) {
                $phaseMap[$s->id] = (int) ($manualPhases[$s->id] ?? 1);
            }
            $splitNote = 'manual';
        } else {
            $phaseMap  = $this->splitSamples($eligible, $labelOf);
            $splitNote = 'auto (stratified, patient-grouped, seed=42)';
        }

        // ── Leakage check on the resulting split ──────────────────────────────
        // Groups by case_id, falling back to the slide barcode so that the 74
        // barcode-sharing pairs in this database cannot straddle two splits.
        // Samples with neither identifier cannot be checked — that is reported
        // rather than silently skipped.
        $groups   = [];
        $ungrouped = 0;
        foreach ($eligible as $s) {
            $key = $s->case_id ? "case:{$s->case_id}" : ($s->entity_submitter_id ? "slide:{$s->entity_submitter_id}" : null);
            if ($key === null) {
                $ungrouped++;
                continue;
            }
            $groups[$key][] = $phaseMap[$s->id];
        }
        foreach ($groups as $key => $phases) {
            if (count(array_unique($phases)) > 1) {
                return redirect()->back()->withErrors([
                    'sample_ids' => "Data leakage: samples from the same group ({$key}) were assigned to "
                        . 'different splits. All samples of one patient/slide must share a split.',
                ]);
            }
        }

        // ── Split composition must allow training AND scoring ─────────────────
        $byPhase = [1 => [], 2 => [], 3 => []];
        foreach ($phaseMap as $sid => $ph) {
            $byPhase[$ph][] = $labelOf[$sid];
        }
        foreach ([1 => 'train', 2 => 'validation'] as $ph => $name) {
            $n       = count($byPhase[$ph]);
            $classes = count(array_unique($byPhase[$ph]));
            if ($n < 2 || $classes < 2) {
                return redirect()->back()->withErrors([
                    'sample_ids' => sprintf(
                        'The %s split holds %d sample(s) covering %d class(es); it needs at least 2 of each. '
                        . 'A single-class validation split makes the AUC undefined, which silently disables '
                        . 'best-checkpoint selection for the whole run.',
                        $name,
                        $n,
                        $classes
                    ),
                ]);
            }
        }

        // ── Create the training run record ────────────────────────────────────
        $run = TrainingRun::create([
            'training_head_id'  => $validated['training_head_id'],
            'feature_model_id'  => $validated['feature_model_id'],
            'server_id'         => $validated['server_id'],
            'status'            => 'pending',
            'sample_count'      => $eligible->count(),
            'label_type'        => $validated['label_type'],
            'label_map'         => $labelMap,
            'model_type'        => $validated['model_type'],
            'epochs'            => (int) $validated['epochs'],
            'learning_rate'     => (float) $validated['learning_rate'],
            'bag_size'          => (int) $validated['bag_size'],
            'n_classes'         => $nClasses,
            'gdrive_output_dir' => $validated['gdrive_output_dir'] ?? 'training/CLAM/run_',
        ]);

        if (! $validated['gdrive_output_dir']) {
            $run->update(['gdrive_output_dir' => "training/CLAM/run_{$run->id}"]);
        }

        // Attach with the resolved split so the assignment is reproducible.
        $pivot = [];
        foreach ($phaseMap as $sid => $ph) {
            $pivot[$sid] = ['training_phase' => $ph];
        }
        $run->samples()->sync($pivot);

        $nTrain = count($byPhase[1]);
        $nVal   = count($byPhase[2]);
        $nTest  = count($byPhase[3]);

        $run->update(['metrics' => [
            'provenance' => [
                'split_strategy'  => $splitNote,
                'split'           => ['train' => $nTrain, 'val' => $nVal, 'test' => $nTest],
                'patch_size_id'   => $patchSizes->first(),
                'magnification_id' => $magnifications->first(),
                'feature_model_id' => $featureModels->first(),
                'ungrouped_samples' => $ungrouped,
                'excluded_count'  => $skipped,
            ],
        ]]);

        \Illuminate\Support\Facades\Log::info(
            "[TrainingDispatch] Run #{$run->id} — {$splitNote} — Train={$nTrain} Val={$nVal} Test={$nTest}"
        );

        TrainingJob::dispatch($run->id);

        $msg = "Training run #{$run->id} dispatched — {$nTrain} train / {$nVal} val / {$nTest} test ({$splitNote}).";
        if ($skipped > 0) {
            $msg .= " {$skipped} sample(s) excluded as ineligible.";
        }
        if ($ungrouped > 0) {
            $msg .= " Note: {$ungrouped} sample(s) have no case_id or slide barcode, so they could not be "
                  . 'checked for patient-level leakage.';
        }

        return redirect()->back()->with('success', $msg);
    }

    /**
     * Stratified, patient-grouped train/val/test split (60/20/20).
     *
     * Groups samples by case_id (falling back to the slide barcode) so every
     * slide of one patient lands in the same split, then distributes the groups
     * of each class across splits in turn. Deterministic: groups are ordered by a
     * seeded hash, so the same selection always produces the same split.
     *
     * @param  \Illuminate\Support\Collection<int, Sample>  $samples
     * @param  array<int, int>  $labelOf  sample_id => class index
     * @return array<int, int>  sample_id => phase (1=train 2=val 3=test)
     */
    private function splitSamples($samples, array $labelOf): array
    {
        // group key => ['label' => int, 'ids' => int[]]
        $groups = [];
        foreach ($samples as $s) {
            $key = $s->case_id
                ? "case:{$s->case_id}"
                : ($s->entity_submitter_id ? "slide:{$s->entity_submitter_id}" : "sample:{$s->id}");
            $groups[$key]['label']   = $labelOf[$s->id];
            $groups[$key]['ids'][]   = $s->id;
        }

        // Bucket groups by class so each split gets both classes.
        $byClass = [];
        foreach ($groups as $key => $g) {
            $byClass[$g['label']][] = $key;
        }

        $phaseMap = [];
        foreach ($byClass as $keys) {
            // Deterministic order without depending on DB row order.
            usort($keys, fn ($a, $b) => strcmp(md5('42' . $a), md5('42' . $b)));

            $n      = count($keys);
            $nVal   = max(1, (int) floor($n * 0.20));
            $nTest  = max(1, (int) floor($n * 0.20));
            // Keep at least one training group even for very small classes.
            if ($nVal + $nTest >= $n) {
                $nVal  = 1;
                $nTest = ($n >= 3) ? 1 : 0;
            }

            foreach ($keys as $i => $key) {
                $phase = match (true) {
                    $i < $nVal          => 2,
                    $i < $nVal + $nTest => 3,
                    default             => 1,
                };
                foreach ($groups[$key]['ids'] as $sid) {
                    $phaseMap[$sid] = $phase;
                }
            }
        }

        return $phaseMap;
    }
}
