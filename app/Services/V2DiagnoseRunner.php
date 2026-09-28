<?php

namespace App\Services;

use App\Models\V2Diagnosis;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * The stages of one V2 Diagnose run, in order.
 *
 *   tile      scripts/patch_extract.py at the V2 scale      → RUN/patches, RUN/tiling.json
 *   prepare   scripts/v2_tools.py prepare                   → view images, sheets, density, manifest
 *   analyse   claude -p (headless), the prompt on stdin     → RUN/result.json
 *   finalize  scripts/v2_tools.py finalize                  → RUN/final.json (level-0 coordinates)
 *
 * Claude only ever writes view-pixel coordinates; the conversion to slide
 * pixels is Python's, so the numbers drawn on the slide never depend on a
 * language model doing arithmetic. A result that fails validation goes back
 * to the same Claude session with the errors, a bounded number of times.
 */
class V2DiagnoseRunner
{
    public function runDir(V2Diagnosis $run): string
    {
        return rtrim((string) config('v2_diagnose.runs_dir'), '/\\') . '/' . $run->id;
    }

    /** Where the slide can be read from right now, or null. */
    public function resolveWsi(V2Diagnosis $run): ?string
    {
        if ($run->wsi_path && is_file($run->wsi_path)) {
            return $run->wsi_path;
        }
        $sample = $run->sample;
        if ($sample && $sample->wsi_remote_path) {
            $mount = rtrim((string) config('services.gdrive_mount', '/mnt/gdrive'), '/');
            $path = $mount . '/' . ltrim((string) $sample->wsi_remote_path, '/');
            if (is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    public function tile(V2Diagnosis $run, string $wsi): void
    {
        $dir = $this->runDir($run);
        $t = config('v2_diagnose.tiling');
        File::ensureDirectoryExists($dir);
        File::deleteDirectory("{$dir}/patches");   // a retried run starts clean

        // Tiling reads thousands of scattered regions, and on the Drive mount
        // each one is a network round trip: 18 tiles in 30 minutes, measured.
        // One sequential copy of the whole file runs at full bandwidth (a
        // 70 MB slide in 11 s), so the slide is copied in first and tiled
        // locally. The copy is only for tiling; the viewer keeps the original.
        $source = $wsi;
        $local = null;
        $mount = rtrim((string) config('services.gdrive_mount', '/mnt/gdrive'), '/');
        if (str_starts_with($wsi, $mount . '/')) {
            $local = "{$dir}/slide." . pathinfo($wsi, PATHINFO_EXTENSION);
            if (! @copy($wsi, $local)) {
                throw new \RuntimeException('Could not copy the slide from Drive for tiling.');
            }
            $source = $local;
        }

        // The whole slide, every tile that holds tissue — not patch_extract.py,
        // which samples for training: a 100-tile random cap and a half-tissue
        // rule left 70% of the tissue unread on the first production runs.
        // v2_tools measures the coverage it achieves, and the run records it.
        try {
            $result = $this->python([
                base_path('scripts/v2_tools.py'), 'tile', $dir, $source,
                '--patch', (string) $t['patch_size'],
                '--mpp', (string) $t['target_mpp'],
                '--workers', (string) max(1, $t['workers']),
            ], 6 * 3600);
        } finally {
            if ($local) {
                @unlink($local);    // one slide copy per run is enough disk to spend
            }
        }

        if (empty($result['patches_extracted'])) {
            throw new \RuntimeException('Tiling produced no tissue tiles.');
        }
        file_put_contents("{$dir}/tiling.json", json_encode($result, JSON_PRETTY_PRINT));

        $run->update([
            'run_dir'      => $dir,
            'wsi_path'     => $wsi,
            'slide_width'  => $result['slide_width'] ?? null,
            'slide_height' => $result['slide_height'] ?? null,
            'base_mpp'     => $result['base_mpp'] ?? null,
            'patch_size'   => $result['patch_size'] ?? null,
            'target_mpp'   => $result['target_mpp'] ?? null,
            'scale_l0'     => $result['scale_l0_px_per_out_px'] ?? null,
            'patches'      => $result['patches_extracted'],
        ]);
    }

    public function prepare(V2Diagnosis $run): array
    {
        $dir = $this->runDir($run);
        // Claude runs the helpers from inside the run directory, so they are
        // copied there: the prompt names one fixed relative path, and the run
        // keeps the exact version of the tool it was analysed with.
        File::ensureDirectoryExists("{$dir}/tools");
        copy(base_path('scripts/v2_tools.py'), "{$dir}/tools/v2_tools.py");

        return $this->python(["{$dir}/tools/v2_tools.py", 'prepare', $dir], 3600);
    }

    public function buildPrompt(V2Diagnosis $run): string
    {
        $tpl = (string) file_get_contents(resource_path('prompts/v2_diagnose.md'));
        $mpp = (float) ($run->target_mpp ?: config('v2_diagnose.tiling.target_mpp'));
        $px = (int) ($run->patch_size ?: config('v2_diagnose.tiling.patch_size'));
        $unknown = 'not provided';

        return strtr($tpl, [
            '{{ORGAN}}'    => $run->organ,
            '{{STAIN}}'    => $run->stain ?: $unknown,
            '{{AGE}}'      => $run->age !== null ? "{$run->age} years" : $unknown,
            '{{SEX}}'      => $run->sex ?: $unknown,
            '{{RACE}}'     => $run->race ?: $unknown,
            '{{NOTES}}'    => $run->clinical_notes ? str_replace("\n", ' ', $run->clinical_notes) : 'none',
            '{{TILES}}'    => (string) $run->patches,
            '{{PATCH_PX}}' => (string) $px,
            '{{PATCH_UM}}' => (string) round($px * $mpp),
            '{{MPP}}'      => (string) $mpp,
            '{{SLIDE_W}}'  => (string) $run->slide_width,
            '{{SLIDE_H}}'  => (string) $run->slide_height,
            '{{PY}}'       => $this->pythonBin(),
            '{{SHEETS}}'   => (string) (int) ceil(((int) $run->patches) / 16),
            '{{COVERAGE}}' => ($cov = $this->coverage($run)) !== null ? round($cov * 100, 1) . '%' : 'all',
        ]);
    }

    /**
     * One headless Claude call in the run directory. Returns the CLI's JSON.
     *
     * Tools are allow-listed: reading and writing files in the folder, and
     * running the one helper script. Everything else is denied without a
     * prompt, which is what -p does with a tool that is not on the list.
     */
    public function claude(V2Diagnosis $run, string $prompt, ?string $resume = null): array
    {
        $cfg = config('v2_diagnose.claude');
        $dir = $this->runDir($run);
        $py = $this->pythonBin();

        // --restricted ignores the worker user's own Claude settings (whose
        // permission mode would otherwise let ordinary shell commands through)
        // and confines the file tools to the run directory; dontAsk then denies
        // every call that is not on the allow-list below.
        $cmd = [
            $cfg['binary'], '-p',
            '--output-format', 'json',
            '--model', $cfg['model'],
            '--max-budget-usd', (string) $cfg['max_budget_usd'],
            '--restricted', '--strict-mcp-config',
            '--permission-mode', 'dontAsk',
            '--tools', 'Read,Write,Edit,Glob,Bash',
            '--allowedTools', 'Read', 'Write', 'Edit', 'Glob',
            "Bash({$py} tools/v2_tools.py:*)",
            '--disallowedTools', 'WebFetch', 'WebSearch',
        ];
        if ($resume) {
            array_push($cmd, '--resume', $resume);
        }

        $env = ['CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC' => '1'];
        if (! empty($cfg['home'])) {
            $env['HOME'] = $cfg['home'];
        }

        $process = new Process($cmd, $dir, $env, $prompt, (float) $cfg['timeout']);
        $started = microtime(true);
        $process->run();
        $stdout = trim($process->getOutput());
        $stderr = trim($process->getErrorOutput());

        // Kept whatever happens: this is the record of what Claude said.
        $entry = json_encode([
            'at' => now()->toDateTimeString(), 'resume' => $resume, 'exit' => $process->getExitCode(),
            'seconds' => round(microtime(true) - $started), 'stdout' => $stdout,
            'stderr' => mb_substr($stderr, -4000),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents("{$dir}/claude_calls.jsonl", $entry . "\n", FILE_APPEND);
        $run->update(['raw_output' => trim(($run->raw_output ?? '') . "\n" . $entry)]);

        $json = json_decode($stdout, true);
        if (! is_array($json)) {
            throw new \RuntimeException('The analysis engine returned no result (exit '
                . $process->getExitCode() . '): ' . mb_substr($stderr ?: $stdout, 0, 800));
        }
        return $json;
    }

    public function finalize(V2Diagnosis $run): array
    {
        $dir = $this->runDir($run);
        $args = ["{$dir}/tools/v2_tools.py", 'finalize', $dir];
        $sam = config('v2_diagnose.sam');
        if (! empty($sam['checkpoint'])) {
            array_push($args, '--sam-ckpt', $sam['checkpoint'],
                '--sam-model', $sam['model'], '--sam-device', $sam['device']);
        }
        // finalize exits 1 with {"ok":false,"errors":[...]} on a bad result;
        // that is an answer, not a crash, so it is returned rather than thrown.
        return $this->python($args, 3600, allowFailure: true);
    }

    /**
     * Build the viewer files (view.json, heat.json) and the display check from
     * final.json — for runs finished before those files existed. Uses the
     * copy of the tools in the scripts folder, so an old run gets the current
     * export rather than the version it was analysed with.
     */
    public function export(V2Diagnosis $run): array
    {
        return $this->python([base_path('scripts/v2_tools.py'), 'export', $this->runDir($run)], 600);
    }

    /** Drop the full-resolution tiles; keep what Claude saw and what was drawn. */
    public function cleanup(V2Diagnosis $run): void
    {
        if (config('v2_diagnose.keep_full_patches')) {
            return;
        }
        foreach (glob($this->runDir($run) . '/patches/patch_*') ?: [] as $f) {
            @unlink($f);
        }
    }

    /** Share of the slide's tissue the tiles cover, as measured at tiling. */
    public function coverage(V2Diagnosis $run): ?float
    {
        $t = json_decode((string) @file_get_contents($this->runDir($run) . '/tiling.json'), true);
        return isset($t['coverage']) ? (float) $t['coverage'] : null;
    }

    public function pythonBin(): string
    {
        return (string) config('v2_diagnose.python', 'python3');
    }

    /** Run a Python script and return its last JSON line. */
    private function python(array $args, int $timeout, bool $allowFailure = false): array
    {
        $process = new Process([$this->pythonBin(), ...$args], base_path(),
            ['PYTHONUNBUFFERED' => '1', 'PYTHONIOENCODING' => 'UTF-8'], null, $timeout);
        $process->run();

        $parsed = null;
        $lines = preg_split('/\r?\n/', trim($process->getOutput())) ?: [];
        for ($i = count($lines) - 1; $i >= 0 && $parsed === null; $i--) {
            $try = json_decode(trim($lines[$i]), true);
            $parsed = is_array($try) ? $try : null;
        }

        if ($parsed !== null && ($process->isSuccessful() || $allowFailure) && ! isset($parsed['error'])) {
            return $parsed;
        }

        $reason = $parsed['error'] ?? mb_substr(trim($process->getErrorOutput()) ?: 'no output', -1500);
        Log::error('[V2Diagnose] python failed', ['args' => $args, 'exit' => $process->getExitCode(),
            'stderr' => mb_substr($process->getErrorOutput(), -4000)]);
        throw new \RuntimeException(basename($args[0]) . ' failed: ' . $reason);
    }
}
