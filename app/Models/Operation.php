<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * One dispatch from the Operations page, and the slides it went out with.
 *
 * The point of this record is review: which slides — and therefore which
 * patients — were put through a given run, under what settings, and how each
 * one ended. That question could not be answered before, because the only
 * trace a run left behind was a status column on the slide, which the next run
 * overwrote.
 *
 * Item statuses are DERIVED from each slide's own pipeline column rather than
 * reported in by the jobs (see OperationProgress). Feature extraction and
 * training are finished by the remote GPU service writing to `samples` and
 * `training_runs` directly, so a hook inside the Laravel job would never fire
 * for them. Deriving is the one mechanism that works for every kind of
 * operation, which is what auditing them uniformly requires.
 */
class Operation extends Model
{
    public const TYPES = [
        'patch_extraction'   => 'Patch Extraction (Tiling)',
        'feature_extraction' => 'Feature Extraction',
        'training'           => 'Training',
    ];

    /** Statuses that mean the operation is over and its record is now frozen. */
    public const TERMINAL = ['completed', 'completed_with_failures', 'failed', 'cancelled'];

    protected $fillable = [
        'name', 'type', 'status', 'total_items', 'completed_items',
        'failed_items', 'params', 'user_id', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'params'      => 'array',
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(OperationItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Open an operation over a set of slides.
     *
     * Each slide's file name and its case's submitter id are copied onto the
     * item here, at dispatch time, so the audit still names what it processed
     * after a bulk delete has removed the rows themselves.
     *
     * @param  Collection<int, Sample>  $samples
     * @param  array<string, mixed>     $params
     */
    public static function start(string $type, string $name, Collection $samples, array $params = []): self
    {
        return DB::transaction(function () use ($type, $name, $samples, $params) {
            $unique = $samples->unique('id')->values();

            $operation = self::create([
                'name'        => $name,
                'type'        => $type,
                'status'      => 'running',
                'total_items' => $unique->count(),
                'params'      => $params,
                'user_id'     => Auth::id(),
                'started_at'  => now(),
            ]);

            $now  = now();
            $rows = $unique->map(fn (Sample $sample) => [
                'operation_id'      => $operation->id,
                'sample_id'         => $sample->id,
                'case_id'           => $sample->case_id,
                'sample_file_name'  => $sample->file_name,
                'case_submitter_id' => $sample->patientCase?->submitter_id,
                'status'            => 'pending',
                'created_at'        => $now,
                'updated_at'        => $now,
            ])->all();

            if ($rows !== []) {
                OperationItem::insert($rows);
            }

            return $operation;
        });
    }

    /**
     * A name a person can pick out of a list weeks later: what ran, on what
     * settings, over how many slides, and when.
     *
     *   "Feature Extraction · TITAN · RunPod-A · 29 slide(s) · 2026-09-07 18:20"
     *
     * @param  array<int, string|null>  $details  settings worth naming; nulls drop out
     */
    public static function buildName(string $type, array $details, int $slideCount): string
    {
        $parts = array_merge(
            [self::TYPES[$type] ?? ucfirst(str_replace('_', ' ', $type))],
            array_values(array_filter($details, fn ($d) => filled($d))),
            [$slideCount . ' slide(s)', now()->format('Y-m-d H:i')],
        );

        return implode(' · ', $parts);
    }

    /** The operation this one was launched from, when it continues a chain. */
    public function parent(): ?self
    {
        $parentId = $this->params['source_operation_id'] ?? null;

        return $parentId ? self::find($parentId) : null;
    }

    /**
     * Operations launched from this one.
     *
     * The link lives in the child's `params` rather than a column: an operation
     * is identified by what it was asked to do, and "which run fed me" is part
     * of that request, not a separate relationship to maintain.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, self>
     */
    public function children(): \Illuminate\Database\Eloquent\Collection
    {
        return self::where('params->source_operation_id', $this->id)->latest('id')->get();
    }

    /**
     * Recompute the counters from the items.
     *
     * Deliberately a recount rather than an increment: several slides of one
     * run settle at the same moment, and two concurrent increments would lose
     * one another. Recomputing from the rows converges whatever the ordering.
     */
    public function recount(): void
    {
        // A cancelled run stays cancelled. The counters below describe how far
        // it got, but the reason it stopped is an operator decision and must
        // not be recomputed away into "completed".
        if ($this->status === 'cancelled') {
            $this->refreshCounters();

            return;
        }

        $byStatus = $this->items()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $completed = (int) ($byStatus['completed'] ?? 0);
        $failed    = (int) ($byStatus['failed'] ?? 0);
        $skipped   = (int) ($byStatus['skipped'] ?? 0);
        $total     = (int) $byStatus->sum();
        $settled   = $completed + $failed + $skipped;

        $status = match (true) {
            $total === 0                    => 'completed',
            $settled < $total               => 'running',
            $failed === 0                   => 'completed',
            ($completed + $skipped) === 0   => 'failed',
            default                         => 'completed_with_failures',
        };

        $this->forceFill([
            'total_items'     => $total,
            'completed_items' => $completed,
            'failed_items'    => $failed,
            'status'          => $status,
            'finished_at'     => in_array($status, self::TERMINAL, true)
                ? ($this->finished_at ?? now())
                : null,
        ])->save();
    }

    /** Counters only — used where the status itself must not be re-derived. */
    private function refreshCounters(): void
    {
        $byStatus = $this->items()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $this->forceFill([
            'total_items'     => (int) $byStatus->sum(),
            'completed_items' => (int) ($byStatus['completed'] ?? 0),
            'failed_items'    => (int) ($byStatus['failed'] ?? 0),
        ])->save();
    }

    public function getIsRunningAttribute(): bool
    {
        return ! in_array($this->status, self::TERMINAL, true);
    }

    /** How far along, 0–100. An operation with no items is finished by definition. */
    public function getProgressPercentAttribute(): int
    {
        if ($this->total_items < 1) {
            return 100;
        }

        $settled = $this->completed_items + $this->failed_items;

        return (int) min(100, round($settled / $this->total_items * 100));
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? ucfirst(str_replace('_', ' ', $this->type));
    }

    /** Bootstrap contextual colour, shared by the badge and the progress bar. */
    public function getStatusColourAttribute(): string
    {
        return match ($this->status) {
            'completed'               => 'success',
            'completed_with_failures' => 'warning',
            'failed'                  => 'danger',
            'cancelled'               => 'secondary',
            default                   => 'info',
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'completed_with_failures' => 'Completed with failures',
            default                   => ucfirst($this->status),
        };
    }
}
