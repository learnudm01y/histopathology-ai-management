<?php

namespace App\Jobs;

use App\Models\V2Diagnosis;
use App\Services\V2DiagnoseRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * One V2 Diagnose run, start to finish, on one worker.
 *
 * Tried once. A run that dies half-way is marked failed with its reason and
 * re-run from the page as a fresh attempt: re-sending a slide to Claude
 * automatically would double the cost of every failure without anyone
 * deciding to.
 */
class RunV2Diagnosis implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** Slightly under the connection's retry_after, so the queue never hands it out twice. */
    public int $timeout = 7200;

    /** How long a slide that is still arriving is waited for (~3 h at 2 min). */
    private const MAX_WAITS = 90;

    public function __construct(public readonly int $runId, public readonly int $wait = 0)
    {
        $this->onConnection(config('v2_diagnose.queue_connection'));
        $this->onQueue(config('v2_diagnose.queue'));
    }

    public function handle(V2DiagnoseRunner $runner): void
    {
        // Claim the run. Only a queued or waiting run can be claimed, so a
        // duplicate delivery finds nothing to do instead of paying twice.
        $claimed = V2Diagnosis::whereKey($this->runId)
            ->whereIn('status', ['queued', 'waiting_slide'])
            ->update(['status' => 'tiling', 'started_at' => now()]);
        if (! $claimed) {
            return;
        }
        $run = V2Diagnosis::findOrFail($this->runId);

        try {
            // A reading is only worth scoring if the model never saw the
            // answer: refuse before any work is spent on the slide.
            if ($leaks = $runner->blindingLeaks($run)) {
                throw new \RuntimeException('Refused: the case details name the slide, its archive or its recorded '
                    . 'diagnosis (' . implode(', ', $leaks) . '). Remove them so the slide is read blind.');
            }

            $wsi = $runner->resolveWsi($run);
            if (! $wsi) {
                $this->waitForSlide($run);
                return;
            }

            $t = config('v2_diagnose.tiling');
            $run->stage('tiling', "Cutting the whole slide into {$t['patch_size']} px tiles at "
                . "{$t['target_mpp']} µm/px — every tile that holds tissue.");
            $runner->tile($run, $wsi);

            $cov = $runner->coverage($run);
            $run->refresh()->stage('preparing', "{$run->patches} tiles cut"
                . ($cov !== null ? ', covering ' . round($cov * 100, 1) . '% of the tissue' : '')
                . ". Building view images, "
                . 'contact sheets and the density measurement.');
            $runner->prepare($run);

            $prompt = $runner->buildPrompt($run);
            file_put_contents($runner->runDir($run) . '/prompt.md', $prompt);
            $run->update(['prompt' => $prompt, 'claude_model' => config('v2_diagnose.claude.model')]);
            $run->stage('analysing', "Sending {$run->patches} tiles to the AI analysis model.");
            $sheets = (int) ceil($run->patches / 16);
            $runner->mark($run, 'survey', 'Sending the tiles to the AI analysis model', 0, $sheets);

            $out = $runner->claude($run, $prompt);
            $this->account($run, $out);
            $this->guard($runner, $run);
            $session = $out['session_id'] ?? null;

            $run->stage('finalising', 'Checking the result and converting it to slide coordinates.');
            $runner->mark($run, 'finalising', 'Diagnosis received — checking it and converting it to slide coordinates');
            $final = $runner->finalize($run);

            $attempts = (int) config('v2_diagnose.claude.repair_attempts', 2);
            for ($i = 1; empty($final['ok']) && $i <= $attempts && $session; $i++) {
                $run->stage('analysing', "The result failed validation; sent back to the AI analysis model for correction (repair {$i}/{$attempts}).");
                $runner->mark($run, 'report', "The report failed a check — the AI analysis model is correcting it (repair {$i} of {$attempts})", null, null, 0.5);
                $out = $runner->claude($run, $this->repairPrompt($final['errors'] ?? []), $session);
                $this->account($run, $out);
                $this->guard($runner, $run);
                $session = $out['session_id'] ?? $session;
                $run->stage('finalising', 'Checking the repaired result.');
                $runner->mark($run, 'finalising', 'Corrected diagnosis received — checking it again');
                $final = $runner->finalize($run);
            }

            if (empty($final['ok'])) {
                throw new \RuntimeException(($this->claudeEnding ? "{$this->claudeEnding}. " : '')
                    . 'The analysis result did not pass validation: '
                    . implode(' | ', array_slice($final['errors'] ?? ['unknown'], 0, 8)));
            }

            $runner->cleanup($run);
            $run->update([
                'summary'       => $final['summary'] ?? null,
                'diagnosis_code' => $final['diagnosis_code'] ?? null,
                'diagnosis'     => $final['diagnosis'] ?? null,
                'confidence'    => $final['confidence'] ?? null,
                'regions_count' => $final['regions'] ?? 0,
                'sam_refined'   => (bool) ($final['sam_refined'] ?? false),
                'warnings'      => $final['warnings'] ?? [],
                'finished_at'   => now(),
            ]);
            $run->stage('completed', 'Done — ' . ($final['regions'] ?? 0) . ' regions drawn on the slide.');
        } catch (\Throwable $e) {
            Log::error("[V2Diagnose] run #{$run->id} failed: {$e->getMessage()}");
            $run->update(['error' => mb_substr($e->getMessage(), 0, 4000), 'finished_at' => now()]);
            $run->stage('failed', 'Failed: ' . mb_substr($e->getMessage(), 0, 300));
        }
    }

    public function failed(\Throwable $e): void
    {
        // Reached when the worker itself kills the job (timeout), where the
        // catch above never runs.
        $run = V2Diagnosis::find($this->runId);
        if ($run && $run->isRunning()) {
            $run->update(['error' => mb_substr($e->getMessage(), 0, 4000), 'finished_at' => now()]);
            $run->stage('failed', 'Failed: the worker stopped the run (' . class_basename($e) . ').');
        }
    }

    /** The slide is still on its way in: look again in two minutes. */
    private function waitForSlide(V2Diagnosis $run): void
    {
        if ($this->wait >= self::MAX_WAITS) {
            $run->update(['error' => 'The slide never became readable on this server.', 'finished_at' => now()]);
            $run->stage('failed', 'Failed: the slide never became readable on this server.');
            return;
        }
        $run->stage('waiting_slide', 'Waiting for the slide to finish arriving (storage: '
            . ($run->sample?->storage_status ?? 'unknown') . ').');
        app(V2DiagnoseRunner::class)->mark($run, 'fetching', 'Waiting for the slide to finish arriving in storage'
            . ' (checked ' . ($this->wait + 1) . ' ' . ($this->wait ? 'times' : 'time') . ')');
        self::dispatch($this->runId, $this->wait + 1)->delay(now()->addMinutes(2));
    }

    private function account(V2Diagnosis $run, array $out): void
    {
        // Tokens are what a run really takes from a subscription's usage;
        // cost_usd is only the CLI's estimate at API prices (see the view).
        $usage = $run->usage ?? [];
        foreach (['input_tokens', 'output_tokens', 'cache_read_input_tokens', 'cache_creation_input_tokens'] as $k) {
            $usage[$k] = ($usage[$k] ?? 0) + (int) ($out['usage'][$k] ?? 0);
        }

        $run->update([
            'claude_session_id' => $out['session_id'] ?? $run->claude_session_id,
            'usage'       => $usage,
            'cost_usd'    => round(($run->cost_usd ?? 0) + (float) ($out['total_cost_usd'] ?? 0), 4),
            'duration_ms' => ($run->duration_ms ?? 0) + (int) ($out['duration_ms'] ?? 0),
            'num_turns'   => ($run->num_turns ?? 0) + (int) ($out['num_turns'] ?? 0),
        ]);
        // An error ending (max turns, for one) can still leave a valid
        // result.json behind, so validation decides — the ending is only
        // remembered to explain a failure if validation then fails too.
        $this->claudeEnding = ! empty($out['is_error'])
            ? 'The analysis ended with ' . ($out['subtype'] ?? 'an error') . ': '
              . mb_substr((string) ($out['result'] ?? ''), 0, 300)
            : null;
    }

    private ?string $claudeEnding = null;

    /**
     * Stop a run whose model stepped outside its folder or whose helper
     * script changed: its answer may not come from the tissue alone, and
     * finalize would execute the changed script.
     */
    private function guard(V2DiagnoseRunner $runner, V2Diagnosis $run): void
    {
        if ($v = $runner->integrityViolations($run)) {
            throw new \RuntimeException('Integrity check failed, result discarded: '
                . implode(' | ', array_slice($v, 0, 8)));
        }
    }

    private function repairPrompt(array $errors): string
    {
        return "Your result.json failed validation with these errors:\n- "
            . implode("\n- ", array_slice($errors, 0, 40))
            . "\n\nFix result.json, run the validate helper until it reports ok, then reply DONE.";
    }
}
