<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Sample;
use App\Models\SlideVerification;
use App\Support\SampleListFilters;
use App\Support\SimpleXlsxWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Excel export answering "why is this slide not in the training set?".
 *
 * The reasons are re-derived from SlideVerification::evaluateChecks() — the
 * same call the sample page renders — rather than parsed out of the free-text
 * `notes` column, so the sheet and the screen can never disagree. The stored
 * note and the stored outcome are exported alongside the recomputed one, which
 * makes a slide whose status has gone stale (thresholds changed since it was
 * verified) visible instead of silently wrong.
 *
 * Advisory checks (blur, artifacts, background) are reported as warnings and
 * never counted as rejection reasons — see SlideVerification::ADVISORY_CHECKS.
 */
class SampleRejectionsController extends Controller
{
    /** Recorded outcomes that all mean "not usable as it stands". */
    private const NOT_ACCEPTED = [
        SlideVerification::STATUS_FAILED,
        SlideVerification::STATUS_NEEDS_CASE,
        SlideVerification::STATUS_PENDING,
    ];

    private const OUTCOME_LABELS = [
        SlideVerification::STATUS_FAILED     => 'Rejected',
        SlideVerification::STATUS_NEEDS_CASE => 'Held — case information missing',
        SlideVerification::STATUS_PENDING    => 'Pending — checks not finished',
        SlideVerification::STATUS_PASSED     => 'Accepted',
    ];

    /** Used when a slide has no slide_verifications row at all. */
    private const NEVER_VERIFIED = 'Never verified';

    /**
     * Per-check states worth reporting, in the order a reader cares about them.
     * `passed` is deliberately absent — this file is about what went wrong.
     */
    private const REASON_TYPES = [
        SlideVerification::STATE_FAILED      => 'Rejection',
        SlideVerification::STATE_NEEDS_INFO  => 'Missing case information',
        SlideVerification::STATE_NOT_CHECKED => 'Check not run',
        SlideVerification::STATE_WARNING     => 'Warning (advisory)',
    ];

    /**
     * GET /admin/samples/rejections/export
     *
     * scope=summary  (default) — one row per slide, reasons joined in one cell
     * scope=reasons            — one row per individual reason, for pivoting
     *
     * status=rejected (default) — only slides the system rejected
     * status=unusable           — every slide that is not accepted
     *
     * The Samples list filters (organ, group, storage, search) are honoured, so
     * the file always matches the list the button was pressed on.
     */
    public function export(Request $request): BinaryFileResponse
    {
        $detailed = $request->get('scope') === 'reasons';
        $status   = $request->get('status') === 'unusable' ? 'unusable' : 'rejected';

        $query = Sample::query()
            ->with(['organ', 'category', 'dataSource', 'patientCase', 'slideVerification'])
            ->orderBy('id');

        SampleListFilters::apply($query, $request);
        $this->applyOutcomeFilter($query, $status);

        $path = tempnam(sys_get_temp_dir(), 'sample_rejections') . '.xlsx';

        SimpleXlsxWriter::write(
            $path,
            $detailed ? $this->reasonHeaders() : $this->summaryHeaders(),
            $detailed ? $this->reasonRows($query) : $this->summaryRows($query),
            $detailed ? 'Rejection reasons' : 'Rejected slides',
        );

        $filename = 'sample-rejections-'
            . ($status === 'unusable' ? 'not-accepted' : 'rejected') . '-'
            . ($detailed ? 'by-reason' : 'summary') . '-'
            . now()->format('Ymd-His') . '.xlsx';

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    // ── Query ────────────────────────────────────────────────────────────────

    private function applyOutcomeFilter(Builder $query, string $status): void
    {
        if ($status === 'rejected') {
            $query->whereHas(
                'slideVerification',
                fn (Builder $q) => $q->where('verification_status', SlideVerification::STATUS_FAILED),
            );

            return;
        }

        // Everything the system does not currently accept — including slides
        // that were never verified, which have no row to inspect but are just
        // as absent from training as a rejected one.
        $query->where(fn (Builder $q) => $q
            ->whereHas(
                'slideVerification',
                fn (Builder $v) => $v->whereIn('verification_status', self::NOT_ACCEPTED),
            )
            ->orWhereDoesntHave('slideVerification'));
    }

    // ── Sheet: one row per slide ─────────────────────────────────────────────

    private function summaryHeaders(): array
    {
        return [
            'Sample ID', 'Slide ID', 'File name',
            'Case (submitter ID)', 'Organ', 'Disease group', 'Data source',
            'Outcome (recorded)', 'Outcome (recomputed now)',
            'Rejection reasons (count)', 'Rejection reasons', 'Failed check codes',
            'Missing case information', 'Warnings (advisory)', 'Checks not run',
            'Verified at', 'Storage status', 'File size (GB)', 'Recorded note',
        ];
    }

    private function summaryRows(Builder $query): \Generator
    {
        foreach ($query->lazy(500) as $sample) {
            [$buckets, $recomputed] = $this->inspect($sample->slideVerification);
            $failed = $buckets[SlideVerification::STATE_FAILED];

            yield [
                (int) $sample->id,
                $sample->entity_submitter_id,
                $sample->file_name,
                $sample->patientCase?->submitter_id,
                $sample->organ?->name,
                $sample->category?->label_en,
                $sample->dataSource?->name,
                $this->recordedOutcome($sample->slideVerification),
                $recomputed,
                count($failed),
                $this->numbered($failed),
                $failed === [] ? null : implode(', ', array_column($failed, 'code')),
                $this->numbered($buckets[SlideVerification::STATE_NEEDS_INFO]),
                $this->numbered($buckets[SlideVerification::STATE_WARNING]),
                $this->numbered($buckets[SlideVerification::STATE_NOT_CHECKED]),
                $sample->slideVerification?->verified_at?->format('Y-m-d H:i:s'),
                $sample->storage_status,
                $sample->file_size_gb,
                $sample->slideVerification?->notes,
            ];
        }
    }

    // ── Sheet: one row per reason ────────────────────────────────────────────

    private function reasonHeaders(): array
    {
        return [
            'Sample ID', 'Slide ID', 'File name',
            'Case (submitter ID)', 'Organ', 'Disease group', 'Data source',
            'Outcome (recorded)', 'Reason type', 'Reason number',
            'Check group', 'Check code', 'Reason', 'Measured value / detail',
            'Verified at',
        ];
    }

    private function reasonRows(Builder $query): \Generator
    {
        foreach ($query->lazy(500) as $sample) {
            [$buckets] = $this->inspect($sample->slideVerification);

            $lead = [
                (int) $sample->id,
                $sample->entity_submitter_id,
                $sample->file_name,
                $sample->patientCase?->submitter_id,
                $sample->organ?->name,
                $sample->category?->label_en,
                $sample->dataSource?->name,
                $this->recordedOutcome($sample->slideVerification),
            ];
            $verifiedAt = $sample->slideVerification?->verified_at?->format('Y-m-d H:i:s');

            $emitted = 0;

            foreach (self::REASON_TYPES as $state => $typeLabel) {
                foreach ($buckets[$state] as $i => $check) {
                    $emitted++;
                    yield array_merge($lead, [
                        $typeLabel,
                        $i + 1,
                        SlideVerification::GROUP_LABELS[$check['group']] ?? $check['group'],
                        $check['code'],
                        $check['label'],
                        $check['detail'],
                        $verifiedAt,
                    ]);
                }
            }

            // A slide that was never verified has no checks to list, but the
            // fact that nothing ever ran on it is itself the answer.
            if ($emitted === 0) {
                yield array_merge($lead, [
                    'Not verified', 1, '—', '—',
                    'Slide verification has never been run for this slide',
                    null, $verifiedAt,
                ]);
            }
        }
    }

    // ── Shared ───────────────────────────────────────────────────────────────

    /**
     * Sort one slide's checks into the buckets the sheets report on, and
     * recompute the outcome those checks imply right now.
     *
     * @return array{0: array<string, array<int, array>>, 1: string}
     */
    private function inspect(?SlideVerification $verification): array
    {
        $buckets = array_fill_keys(array_keys(self::REASON_TYPES), []);

        if ($verification === null) {
            return [$buckets, self::NEVER_VERIFIED];
        }

        foreach ($verification->evaluateChecks() as $check) {
            if (array_key_exists($check['state'], $buckets)) {
                $buckets[$check['state']][] = $check;
            }
        }

        // Mirrors SlideVerificationService::finalize(): only a hard failure
        // rejects a slide — a missing case field holds it, an unfinished check
        // leaves it pending.
        $outcome = match (true) {
            $buckets[SlideVerification::STATE_FAILED]      !== [] => SlideVerification::STATUS_FAILED,
            $buckets[SlideVerification::STATE_NEEDS_INFO]  !== [] => SlideVerification::STATUS_NEEDS_CASE,
            $buckets[SlideVerification::STATE_NOT_CHECKED] !== [] => SlideVerification::STATUS_PENDING,
            default                                               => SlideVerification::STATUS_PASSED,
        };

        return [$buckets, self::OUTCOME_LABELS[$outcome]];
    }

    private function recordedOutcome(?SlideVerification $verification): string
    {
        if ($verification === null) {
            return self::NEVER_VERIFIED;
        }

        return self::OUTCOME_LABELS[$verification->verification_status]
            ?? (string) $verification->verification_status;
    }

    /**
     * "1) Sufficient tissue present (4.2 — required min 10%) | 2) …"
     *
     * @param  array<int, array>  $checks
     */
    private function numbered(array $checks): ?string
    {
        if ($checks === []) {
            return null;
        }

        $parts = [];
        foreach (array_values($checks) as $i => $check) {
            $detail  = $check['detail'];
            $parts[] = ($i + 1) . ') ' . $check['label']
                . ($detail === null || $detail === '' ? '' : ' (' . $detail . ')');
        }

        return implode(' | ', $parts);
    }
}
