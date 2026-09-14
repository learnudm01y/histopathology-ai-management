<?php

namespace App\Services;

use App\Models\Sample;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Where a slide is in the pipeline, and what can be asked of it there.
 *
 * The workflow page has one job: never let a slide be scored before it is
 * ready, and never leave a user guessing why. So the stage is read from the
 * slide itself rather than assumed, each stage says what unblocks it, and a
 * model states its own requirements which are checked before it runs.
 *
 * The requirement check matters more than it looks. A model trained on TITAN
 * features at 224px will happily consume Virchow2 features at 512px and return
 * a confident number, because nothing in a feature vector says where it came
 * from. Refusing is the only way that mistake stays visible.
 */
class DiagnosisWorkflow
{
    public const STAGE_MISSING   = 'missing';
    public const STAGE_UPLOADED  = 'uploaded';
    public const STAGE_PATCHED   = 'patched';
    public const STAGE_FEATURES  = 'features';
    public const STAGE_READY     = 'ready';

    /**
     * Read the slide and report where it stands.
     *
     * @return array{stage:string, steps:array, blocking:?string, next_action:?string}
     */
    public function inspect(Sample $sample, ?array $model = null): array
    {
        $steps = [];

        $onDrive = $sample->storage_status === 'available' && $sample->wsi_remote_path;
        $steps[] = [
            'key' => 'upload', 'label' => 'Slide stored',
            'done' => (bool) $onDrive,
            'detail' => $onDrive
                ? basename((string) $sample->wsi_remote_path)
                : "storage is '{$sample->storage_status}'",
        ];

        $patched = $sample->tiling_status === 'done';
        $steps[] = [
            'key' => 'patches', 'label' => 'Patches extracted',
            'done' => $patched,
            'running' => $sample->tiling_status === 'processing',
            'failed' => $sample->tiling_status === 'failed',
            'detail' => $patched
                ? number_format((int) $sample->tile_count) . ' patches at '
                  . $sample->tile_size_px . 'px, ' . $sample->magnification
                : "tiling is '{$sample->tiling_status}'",
        ];

        $hasFeatures = $sample->feature_extraction_status === 'completed'
                       && $sample->features_gdrive_path;
        $steps[] = [
            'key' => 'features', 'label' => 'Features extracted',
            'done' => $hasFeatures,
            'running' => $sample->feature_extraction_status === 'processing',
            'failed' => $sample->feature_extraction_status === 'failed',
            'detail' => $hasFeatures
                ? number_format((int) $sample->features_patch_count) . ' vectors, '
                  . ($sample->features_model_version ?: 'unknown model')
                : "extraction is '{$sample->feature_extraction_status}'",
        ];

        $stage = self::STAGE_MISSING;
        if ($onDrive)     $stage = self::STAGE_UPLOADED;
        if ($patched)     $stage = self::STAGE_PATCHED;
        if ($hasFeatures) $stage = self::STAGE_FEATURES;

        $blocking = null;
        $next = null;

        if (! $onDrive) {
            $blocking = 'The slide image is not available in storage.';
            $next = 'upload';
        } elseif (! $patched) {
            $blocking = $sample->tiling_status === 'processing'
                ? 'Patch extraction is running.'
                : 'This slide has not been cut into patches yet.';
            $next = 'patches';
        } elseif (! $hasFeatures) {
            $blocking = $sample->feature_extraction_status === 'processing'
                ? 'Feature extraction is running.'
                : 'Patches exist, but no features have been extracted from them.';
            $next = 'features';
        }

        // Model-specific admission. Only meaningful once features exist.
        $mismatch = $model && $hasFeatures ? $this->requirementFailures($sample, $model) : [];
        if ($mismatch) {
            $blocking = 'This slide does not meet what the selected model needs.';
            $next = null;
        } elseif (! $blocking) {
            $stage = self::STAGE_READY;
        }

        return [
            'stage' => $stage,
            'steps' => $steps,
            'blocking' => $blocking,
            'next_action' => $next,
            'requirement_failures' => $mismatch,
        ];
    }

    /**
     * Every way this slide fails the model's stated requirements.
     *
     * Returned as a list rather than a boolean: a user who is told only "not
     * compatible" has to guess, and guessing here usually ends in re-running
     * an eight-hour extraction against the wrong settings.
     */
    public function requirementFailures(Sample $sample, array $model): array
    {
        $need = $model['requires'] ?? [];
        $out = [];

        if (isset($need['feature_model'])) {
            $have = (string) ($sample->features_model_version ?: '');
            if (stripos($have, (string) $need['feature_model']) === false) {
                $out[] = "features come from '{$have}', this model needs {$need['feature_model']}";
            }
        }
        if (isset($need['patch_px']) && (int) $sample->tile_size_px !== (int) $need['patch_px']) {
            $out[] = "patches are {$sample->tile_size_px}px, this model needs {$need['patch_px']}px";
        }
        if (isset($need['magnification'])
            && strcasecmp((string) $sample->magnification, (string) $need['magnification']) !== 0) {
            $out[] = "magnification is {$sample->magnification}, this model needs {$need['magnification']}";
        }
        if (isset($need['min_patches'])
            && (int) $sample->features_patch_count < (int) $need['min_patches']) {
            $out[] = "only {$sample->features_patch_count} patches, this model needs at least "
                   . $need['min_patches'];
        }

        return $out;
    }

    /**
     * Run the model over a slide that inspect() has cleared.
     *
     * @return array the prediction payload, or ['error' => ...]
     */
    public function predict(Sample $sample, array $model): array
    {
        if (($model['runner'] ?? null) !== 'sklearn_local') {
            return ['error' => "Runner '{$model['runner']}' is not wired up on this server yet."];
        }
        foreach (['python', 'script', 'artefact'] as $k) {
            if (! is_file($model[$k] ?? '')) {
                return ['error' => "Model asset missing on the server: {$k} → {$model[$k]}"];
            }
        }

        $proc = new Process([
            $model['python'], $model['script'],
            '--model', $model['artefact'],
            '--features', (string) $sample->features_gdrive_path,
            '--json',
        ], timeout: 300);

        $proc->run();

        if (! $proc->isSuccessful()) {
            $err = trim($proc->getErrorOutput() ?: $proc->getOutput());
            Log::error("[DiagnosisWorkflow] sample #{$sample->id}: {$err}");
            return ['error' => 'The model could not read this slide: ' . mb_substr($err, 0, 300)];
        }

        $decoded = json_decode($proc->getOutput(), true);
        if (! is_array($decoded)) {
            return ['error' => 'The model returned something unreadable.'];
        }

        $decoded['sample_id'] = $sample->id;
        $decoded['slide'] = $sample->entity_submitter_id ?: $sample->file_name;
        return $decoded;
    }

    /** Where this slide's evidence images live, generated or not. */
    public function evidenceDir(Sample $sample): string
    {
        return storage_path("app/evidence/{$sample->id}");
    }

    /**
     * Work out why the model said what it said, for one slide.
     *
     * Kept separate from predict() and run on request rather than with every
     * score: it re-reads the whole feature file and streams the patch archive
     * to cut out the winning tiles, which is minutes of work nobody wants
     * spent on a slide they were only checking the stage of.
     *
     * @return array the evidence record, or ['error' => ...]
     */
    public function evidence(Sample $sample, array $model): array
    {
        $script = $model['evidence'] ?? null;
        if (! $script || ! is_file($script)) {
            return ['error' => 'This model does not ship an evidence view.'];
        }
        if (! $sample->features_gdrive_path) {
            return ['error' => 'No features for this slide yet.'];
        }

        $mount = rtrim((string) config('services.gdrive_mount', '/mnt/gdrive'), '/');
        $out = $this->evidenceDir($sample);

        // The patch archive sits beside the features, under the tiles tree
        // rather than the features tree. Derived rather than stored, so the
        // two paths cannot drift apart.
        $tiles = $mount . '/' . str_replace(
            'samples/features/TITAN/', 'samples/sliced_slides/',
            ltrim((string) $sample->features_gdrive_path, '/')
        ) . '/patches.tar.gz';

        $proc = new Process([
            $model['python'], $script,
            '--model', $model['artefact'],
            '--features', $mount . '/' . ltrim((string) $sample->features_gdrive_path, '/'),
            '--tiles', $tiles,
            '--label', (string) ($sample->entity_submitter_id ?: $sample->file_name),
            '--out', $out,
        ], timeout: 900);

        $proc->run();

        if (! $proc->isSuccessful()) {
            $err = trim($proc->getErrorOutput() ?: $proc->getOutput());
            Log::error("[DiagnosisWorkflow] evidence for #{$sample->id}: {$err}");
            return ['error' => 'Could not build the evidence view: ' . mb_substr($err, 0, 300)];
        }

        $json = $out . '/evidence.json';
        $decoded = is_file($json) ? json_decode((string) file_get_contents($json), true) : null;

        return is_array($decoded) ? $decoded : ['error' => 'The evidence run produced nothing readable.'];
    }
}
