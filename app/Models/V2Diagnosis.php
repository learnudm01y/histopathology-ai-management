<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One V2 Diagnose run. The parsed result (level-0 coordinates) lives on disk
 * as final.json in the run directory; this row holds everything needed to
 * find, filter and audit it.
 */
class V2Diagnosis extends Model
{
    protected $table = 'v2_diagnoses';

    public const RUNNING = ['queued', 'waiting_slide', 'tiling', 'preparing', 'analysing', 'finalising'];

    protected $fillable = [
        'sample_id', 'user_id', 'organ', 'stain', 'age', 'sex', 'race', 'clinical_notes',
        'status', 'stage_message', 'events',
        'wsi_path', 'slide_width', 'slide_height', 'base_mpp', 'patch_size', 'target_mpp',
        'scale_l0', 'patches', 'run_dir',
        'claude_model', 'claude_session_id', 'prompt', 'raw_output', 'cost_usd',
        'duration_ms', 'num_turns', 'usage',
        'summary', 'diagnosis_code', 'diagnosis', 'confidence', 'regions_count', 'sam_refined', 'warnings', 'error',
        'started_at', 'finished_at',
    ];

    protected $casts = [
        'events'      => 'array',
        'usage'       => 'array',
        'warnings'    => 'array',
        'sam_refined' => 'boolean',
        'base_mpp'    => 'float',
        'target_mpp'  => 'float',
        'scale_l0'    => 'float',
        'cost_usd'    => 'float',
        'confidence'  => 'float',
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function sample(): BelongsTo
    {
        return $this->belongsTo(Sample::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRunning(): bool
    {
        return in_array($this->status, self::RUNNING, true);
    }

    /** Move to a stage and append it to the log, in one write. */
    public function stage(string $status, string $message): void
    {
        $events = $this->events ?? [];
        $events[] = ['at' => now()->toDateTimeString(), 'status' => $status, 'message' => $message];
        $this->update(['status' => $status, 'stage_message' => $message, 'events' => $events]);
    }

    /** The drawable result, or null before a run finishes. */
    public function finalResult(): ?array
    {
        $path = $this->run_dir ? $this->run_dir . '/final.json' : null;
        if (! $path || ! is_file($path)) {
            return null;
        }
        return json_decode((string) file_get_contents($path), true) ?: null;
    }

    /**
     * The small drawing file (view.json): coverage, checks and warnings for
     * the page, without reading the multi-megabyte final.json on every view.
     */
    public function viewData(): ?array
    {
        $path = $this->run_dir ? $this->run_dir . '/view.json' : null;
        if (! $path || ! is_file($path)) {
            return null;
        }
        return json_decode((string) file_get_contents($path), true) ?: null;
    }
}
