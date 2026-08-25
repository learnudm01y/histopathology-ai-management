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
use App\Services\TrainingLabelResolver;
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
     *   sample_ids[]          — array of sample IDs (must have feature_extraction_status = "completed")
     *   sample_phases[{id}]   — training_phase for each sample: 1=train, 2=val, 3=test
     *   server_id             — ID of the CLAM training server (servers_names)
     *   training_head_id      — ID of the CLAM model in ai_models
     *   feature_model_id      — ID of the feature extraction model used (TITAN/Virchow2)
     *   label_type            — 'category' | 'disease_type' | 'disease_subtype'
     *   label_map             — OPTIONAL JSON string { "0": "Normal", "1": "Malignant" }.
     *                           When omitted, the class set is derived from the database
     *                           for exactly the samples being trained on (recommended — a
     *                           hand-typed map that did not match the data used to silently
     *                           relabel every unmatched slide as class 0).
     *   model_type            — 'clam_sb' | 'clam_mb'
     *   epochs                — int
     *   learning_rate         — float
     *   bag_size              — int (-1 = no limit)
     *   hier_weight           — float, weight of the auxiliary coarse-level loss (0 = disable)
     *   use_class_weights     — bool, inverse-frequency weighting (fine-grained sets are imbalanced)
     *   gdrive_output_dir     — GDrive output path (optional)
     *
     * label_type = 'disease_subtype' trains on the EXACT disease name (taxonomy leaf)
     * and additionally supervises the coarse Category level, so the head learns
     * "which family" and "which exact entity" jointly.
     */
    public function dispatchTraining(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'sample_ids'        => ['required', 'array', 'min:2'],
            'sample_ids.*'      => ['integer', 'exists:samples,id'],
            'sample_phases'     => ['required', 'array'],
            'sample_phases.*'   => ['integer', 'in:1,2,3'],
            'server_id'         => ['required', 'integer', 'exists:servers_names,id'],
            'training_head_id'  => ['required', 'integer', 'exists:ai_models,id'],
            'feature_model_id'  => ['required', 'integer', 'exists:ai_models,id'],
            'label_type'        => ['required', 'in:category,disease_type,disease_subtype'],
            'label_map'         => ['nullable', 'string'],  // JSON — auto-derived when blank
            'model_type'        => ['required', 'in:clam_sb,clam_mb'],
            'epochs'            => ['required', 'integer', 'min:1', 'max:200'],
            'learning_rate'     => ['required', 'numeric', 'min:0.000001', 'max:0.1'],
            'bag_size'          => ['required', 'integer', 'min:-1'],
            'hier_weight'       => ['nullable', 'numeric', 'min:0', 'max:1'],
            'use_class_weights' => ['nullable', 'boolean'],
            'gdrive_output_dir' => ['nullable', 'string', 'max:255'],
        ]);

        $labelType = $validated['label_type'];

        // ── Build phase map keyed by sample_id ────────────────────────────────
        $phaseMap = $validated['sample_phases']; // [sample_id => 1|2|3]

        // Every selected sample must have a phase assigned
        $missingPhase = array_filter($validated['sample_ids'], fn($id) => ! isset($phaseMap[$id]));
        if (! empty($missingPhase)) {
            return redirect()->back()->withErrors([
                'sample_phases' => 'Every selected sample must be assigned to Train, Val, or Test. ' . count($missingPhase) . ' sample(s) are unassigned.',
            ]);
        }

        // Must have at least 1 Train sample and at least 1 Val sample
        $phaseValues = array_intersect_key($phaseMap, array_flip($validated['sample_ids']));
        $nTrain = count(array_filter($phaseValues, fn($p) => $p == 1));
        $nVal   = count(array_filter($phaseValues, fn($p) => $p == 2));

        if ($nTrain < 1) {
            return redirect()->back()->withErrors(['sample_phases' => 'At least 1 sample must be assigned to Train.']);
        }
        if ($nVal < 1) {
            return redirect()->back()->withErrors(['sample_phases' => 'At least 1 sample must be assigned to Validation.']);
        }

        // ── Data-leakage guard: no case_id may span multiple splits ──────────
        $samplesWithCases = Sample::whereIn('id', $validated['sample_ids'])
            ->with('patientCase:id,case_id')
            ->get(['id', 'case_id']);

        $casePhaseMap = []; // case_id => first_phase_seen
        foreach ($samplesWithCases as $s) {
            $caseId = $s->case_id;
            if (! $caseId) {
                continue;
            }
            $phase = (int) $phaseMap[$s->id];
            if (isset($casePhaseMap[$caseId]) && $casePhaseMap[$caseId] !== $phase) {
                return redirect()->back()->withErrors([
                    'sample_phases' => "Data leakage detected: samples from the same case (case_id={$s->patientCase?->case_id}) are assigned to different splits. All samples from a case must belong to the same split.",
                ]);
            }
            $casePhaseMap[$caseId] = $phase;
        }

        // ── Only include samples with completed feature extraction ─────────────
        $eligibleSamples = Sample::whereIn('id', $validated['sample_ids'])
            ->where('feature_extraction_status', 'completed')
            ->whereNotNull('features_gdrive_path')
            ->with(TrainingLabelResolver::relationsFor($labelType))
            ->get();

        $eligibleSampleIds = $eligibleSamples->pluck('id')->all();
        $skipped = count($validated['sample_ids']) - count($eligibleSampleIds);

        if (count($eligibleSampleIds) < 2) {
            return redirect()->back()->withErrors([
                'sample_ids' => 'Not enough samples with completed feature extraction. At least 2 required.',
            ]);
        }

        // ── Resolve labels ONCE, from the database, for exactly these samples ──
        $spec = TrainingLabelResolver::buildSpec($eligibleSamples, $labelType);

        if (! empty($spec['unlabelled'])) {
            $ids  = implode(', ', array_slice($spec['unlabelled'], 0, 15));
            $more = count($spec['unlabelled']) > 15 ? ' …' : '';
            $what = match ($labelType) {
                TrainingLabelResolver::TYPE_DISEASE_SUBTYPE => 'a disease subtype',
                TrainingLabelResolver::TYPE_DISEASE_TYPE    => 'a case disease type',
                default                                     => 'a category',
            };
            return redirect()->back()->withErrors([
                'label_type' => count($spec['unlabelled']) . " selected sample(s) have no {$what} assigned and cannot be labelled. "
                    . "Fix or deselect them first (sample IDs: {$ids}{$more}).",
            ]);
        }

        if ($spec['n_classes'] < 2) {
            return redirect()->back()->withErrors([
                'label_type' => "The selected samples span only {$spec['n_classes']} distinct class under '{$labelType}'. At least 2 are required.",
            ]);
        }

        // Optional manual label_map: accepted only when it matches the data exactly.
        if (! empty($validated['label_map'])) {
            $manual = json_decode($validated['label_map'], true);
            if (is_array($manual) && count($manual) > 0) {
                $manualNames  = array_map(fn($v) => mb_strtolower(trim((string) $v)), array_values($manual));
                $derivedNames = array_map(fn($v) => mb_strtolower(trim((string) $v)), $spec['label_map']);
                sort($manualNames);
                sort($derivedNames);

                if ($manualNames !== $derivedNames) {
                    return redirect()->back()->withErrors([
                        'label_map' => 'The label map does not match the classes present in the selected samples. '
                            . 'Derived from data: [' . implode(', ', $spec['label_map']) . ']. '
                            . 'Leave the label map blank to use the derived classes.',
                    ]);
                }

                // Honour the operator's class ordering by remapping indices.
                $spec = $this->reorderSpecTo($spec, array_values($manual));
            }
        }

        // ── Class coverage guards ─────────────────────────────────────────────
        $trainClasses = [];
        $valClasses   = [];
        foreach ($spec['sample_labels'] as $sampleId => $lbl) {
            $p = (int) ($phaseMap[$sampleId] ?? 1);
            if ($p === 1) { $trainClasses[$lbl['label']] = true; }
            if ($p === 2) { $valClasses[$lbl['label']]   = true; }
        }

        $missingInTrain = [];
        foreach ($spec['label_map'] as $idx => $name) {
            if (! isset($trainClasses[$idx])) {
                $missingInTrain[] = $name;
            }
        }
        if (! empty($missingInTrain)) {
            return redirect()->back()->withErrors([
                'sample_phases' => 'These classes have no Train sample and can never be learned: '
                    . implode(', ', $missingInTrain) . '. Move at least one slide of each into Train.',
            ]);
        }

        $missingInVal = [];
        foreach ($spec['label_map'] as $idx => $name) {
            if (! isset($valClasses[$idx])) {
                $missingInVal[] = $name;
            }
        }

        // ── Ensure we still have at least 1 train + 1 val after eligibility filter ──
        $eligiblePhases = array_intersect_key($phaseValues, array_flip($eligibleSampleIds));
        $nTrainEligible = count(array_filter($eligiblePhases, fn($p) => $p == 1));
        $nValEligible   = count(array_filter($eligiblePhases, fn($p) => $p == 2));

        if ($nTrainEligible < 1 || $nValEligible < 1) {
            return redirect()->back()->withErrors([
                'sample_ids' => 'After eligibility filtering, at least 1 Train sample and 1 Val sample with completed feature extraction are required.',
            ]);
        }

        // ── Hierarchy configuration ───────────────────────────────────────────
        $isHierarchical  = TrainingLabelResolver::isHierarchicalType($labelType)
                           && $spec['n_parent_classes'] > 1;
        $hierWeight      = $isHierarchical ? (float) ($validated['hier_weight'] ?? 0.30) : 0.0;
        $useClassWeights = (bool) ($validated['use_class_weights'] ?? true);

        // ── Create the training run record ────────────────────────────────────
        $run = TrainingRun::create([
            'training_head_id'  => $validated['training_head_id'],
            'feature_model_id'  => $validated['feature_model_id'],
            'server_id'         => $validated['server_id'],
            'status'            => 'pending',
            'sample_count'      => count($eligibleSampleIds),
            'label_type'        => $labelType,
            'label_map'         => $spec['label_map'],
            'parent_label_map'  => $isHierarchical ? $spec['parent_label_map'] : null,
            'child_to_parent'   => $isHierarchical ? $spec['child_to_parent'] : null,
            'model_type'        => $validated['model_type'],
            'epochs'            => (int) $validated['epochs'],
            'learning_rate'     => (float) $validated['learning_rate'],
            'bag_size'          => (int) $validated['bag_size'],
            'n_classes'         => $spec['n_classes'],
            'n_parent_classes'  => $isHierarchical ? $spec['n_parent_classes'] : 0,
            'hier_weight'       => $hierWeight,
            'use_class_weights' => $useClassWeights,
            'hierarchy_consistent_inference' => $isHierarchical,
            'gdrive_output_dir' => $validated['gdrive_output_dir'] ?? "training/CLAM/run_",
        ]);

        // Fix gdrive_output_dir with the auto-generated run ID
        if (empty($validated['gdrive_output_dir'])) {
            $run->update(['gdrive_output_dir' => "training/CLAM/run_{$run->id}"]);
        }

        // ── Attach samples with phase AND frozen labels to the pivot ──────────
        $pivotData = [];
        foreach ($eligibleSampleIds as $sampleId) {
            $pivotData[$sampleId] = [
                'training_phase' => (int) ($phaseMap[$sampleId] ?? 1),
                'label'          => $spec['sample_labels'][$sampleId]['label'],
                'parent_label'   => $isHierarchical
                    ? $spec['sample_labels'][$sampleId]['parent_label']
                    : null,
            ];
        }
        $run->samples()->sync($pivotData);

        // ── Log split + class distribution ────────────────────────────────────
        $nTest = count(array_filter($eligiblePhases, fn($p) => $p == 3));
        $classSummary = [];
        foreach ($spec['label_map'] as $idx => $name) {
            $classSummary[] = "{$idx}:{$name}=" . ($spec['class_counts'][$idx] ?? 0);
        }
        \Illuminate\Support\Facades\Log::info(
            "[TrainingDispatch] Run #{$run->id} — split: Train={$nTrainEligible} | Val={$nValEligible} | Test={$nTest} — "
            . "label_type={$labelType} n_classes={$spec['n_classes']} "
            . ($isHierarchical ? "n_parent_classes={$spec['n_parent_classes']} hier_weight={$hierWeight} " : 'flat ')
            . 'classes[' . implode(' ', $classSummary) . ']'
        );

        // ── Dispatch the job ──────────────────────────────────────────────────
        TrainingJob::dispatch($run->id);

        $msg = "Training run #{$run->id} dispatched ({$nTrainEligible} train / {$nValEligible} val / {$nTest} test) — "
             . "{$spec['n_classes']} classes from '{$labelType}'"
             . ($isHierarchical ? " with {$spec['n_parent_classes']} parent classes (hierarchical)." : '.');
        if ($skipped > 0) {
            $msg .= " {$skipped} sample(s) skipped (features not ready).";
        }
        if (! empty($missingInVal)) {
            $msg .= ' Warning: no Validation slide for: ' . implode(', ', $missingInVal)
                  . ' — validation metrics for those classes will be undefined.';
        }

        return redirect()->back()->with('success', $msg);
    }

    /**
     * Re-index a derived spec so class order follows the operator-supplied map.
     * Matching is by display name (already validated as set-equal beforehand).
     */
    private function reorderSpecTo(array $spec, array $desiredOrder): array
    {
        $oldIndexByName = [];
        foreach ($spec['label_map'] as $idx => $name) {
            $oldIndexByName[mb_strtolower(trim((string) $name))] = $idx;
        }

        $old2new     = [];
        $newLabelMap = [];
        foreach (array_values($desiredOrder) as $newIdx => $name) {
            $key = mb_strtolower(trim((string) $name));
            if (! isset($oldIndexByName[$key])) {
                return $spec;   // defensive: leave the derived order untouched
            }
            $old2new[$oldIndexByName[$key]] = $newIdx;
            $newLabelMap[$newIdx] = $spec['label_map'][$oldIndexByName[$key]];
        }

        $newChildToParent = [];
        foreach ($spec['child_to_parent'] as $child => $parent) {
            if (isset($old2new[$child])) {
                $newChildToParent[$old2new[$child]] = $parent;
            }
        }
        ksort($newChildToParent);

        $newCounts = array_fill(0, count($newLabelMap), 0);
        foreach ($spec['class_counts'] as $old => $count) {
            if (isset($old2new[$old])) {
                $newCounts[$old2new[$old]] = $count;
            }
        }

        $newSampleLabels = [];
        foreach ($spec['sample_labels'] as $sampleId => $lbl) {
            $newSampleLabels[$sampleId] = [
                'label'        => $old2new[$lbl['label']] ?? $lbl['label'],
                'parent_label' => $lbl['parent_label'],
            ];
        }

        $spec['label_map']       = $newLabelMap;
        $spec['child_to_parent'] = $newChildToParent;
        $spec['class_counts']    = $newCounts;
        $spec['sample_labels']   = $newSampleLabels;

        return $spec;
    }

    /**
     * POST — class preview for the training form.
     * Returns the exact classes that WOULD be created for the given samples and
     * label source, so the operator sees the real label map before dispatching.
     */
    public function trainingClassPreview(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'sample_ids'   => ['required', 'array', 'min:1'],
            'sample_ids.*' => ['integer'],
            'label_type'   => ['required', 'in:category,disease_type,disease_subtype'],
        ]);

        $labelType = $validated['label_type'];

        $samples = Sample::whereIn('id', $validated['sample_ids'])
            ->where('feature_extraction_status', 'completed')
            ->whereNotNull('features_gdrive_path')
            ->with(TrainingLabelResolver::relationsFor($labelType))
            ->get();

        if ($samples->isEmpty()) {
            return response()->json([
                'ok'      => false,
                'message' => 'None of the selected samples have completed feature extraction.',
            ]);
        }

        $spec = TrainingLabelResolver::buildSpec($samples, $labelType);

        $classes = [];
        foreach ($spec['label_map'] as $idx => $name) {
            $classes[] = [
                'index'  => $idx,
                'label'  => $name,
                'count'  => $spec['class_counts'][$idx] ?? 0,
                'parent' => isset($spec['child_to_parent'][$idx])
                    ? ($spec['parent_label_map'][$spec['child_to_parent'][$idx]] ?? null)
                    : null,
            ];
        }

        return response()->json([
            'ok'               => true,
            'label_type'       => $labelType,
            'n_classes'        => $spec['n_classes'],
            'n_parent_classes' => $spec['n_parent_classes'],
            'hierarchical'     => TrainingLabelResolver::isHierarchicalType($labelType) && $spec['n_parent_classes'] > 1,
            'classes'          => $classes,
            'parent_classes'   => $spec['parent_label_map'],
            'unlabelled_count' => count($spec['unlabelled']),
            'eligible_count'   => $samples->count(),
        ]);
    }
}
