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

    /**
     * Run a finished run again under its own id: same slide, same case
     * details, a fresh analysis. The previous attempt's folder is kept beside
     * it as <id>.attemptN (the evidence of what it answered and which tools it
     * called), its outcome is written into the stage log, and every result
     * field is cleared, so the page and the evaluation see only the new answer.
     */
    public function rerunInPlace(V2Diagnosis $run): void
    {
        if ($run->isRunning()) {
            throw new \RuntimeException("Run #{$run->id} is still {$run->status}.");
        }
        $dir = $this->runDir($run);
        $kept = null;
        if (is_dir($dir)) {
            for ($n = 1; is_dir("{$dir}.attempt{$n}"); $n++);
            if (! @rename($dir, "{$dir}.attempt{$n}")) {
                throw new \RuntimeException("Could not set the previous attempt of run #{$run->id} aside.");
            }
            $kept = basename($dir) . ".attempt{$n}";
        }
        $previous = $run->status === 'completed'
            ? trim(($run->diagnosis_code ?? '?') . ' ' . ($run->confidence ?? ''))
            : 'failed — ' . mb_substr((string) $run->error, 0, 200);

        $run->update(array_fill_keys(['stage_message', 'slide_width', 'slide_height', 'base_mpp', 'patch_size',
            'target_mpp', 'scale_l0', 'patches', 'run_dir', 'claude_model', 'claude_session_id', 'prompt',
            'raw_output', 'cost_usd', 'duration_ms', 'num_turns', 'usage', 'summary', 'diagnosis_code',
            'diagnosis', 'confidence', 'regions_count', 'warnings', 'error', 'started_at', 'finished_at'], null)
            + ['sam_refined' => false]);
        $run->stage('queued', "Re-run in place. Previous attempt: {$previous}"
            . ($kept ? "; its folder is kept as {$kept}." : '.'));
        \App\Jobs\RunV2Diagnosis::dispatch($run->id);
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
            $this->copyWithProgress($run, $wsi, $local);
            $source = $local;
        }
        $this->mark($run, 'tiling', 'Finding the tissue on the slide');

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
        $tools = "{$dir}/tools/v2_tools.py";
        @unlink($tools);
        copy(base_path('scripts/v2_tools.py'), $tools);
        // The model runs this file and finalize runs it after the model: it
        // is made read-only and its hash kept, so a changed copy is caught
        // before anything executes it again (see integrityViolations()).
        @chmod($tools, 0444);
        file_put_contents("{$dir}/tools.sha256", hash_file('sha256', $tools));
        $this->mark($run, 'preparing', 'Building the view images the AI model will read');

        return $this->python(["{$dir}/tools/v2_tools.py", 'prepare', $dir], 3600);
    }

    /**
     * Which version of the instructions a run was given: the first 10 hex of
     * the template's sha1. Written into each run folder (prompt_version), so
     * accuracy can be counted per version and a rule change is measured on
     * the runs made after it.
     */
    public function promptVersion(): string
    {
        return substr((string) sha1_file(resource_path('prompts/v2_diagnose.md')), 0, 10);
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
     * Tools are allow-listed: reading files in the folder, writing result.json,
     * and running the read-only helpers. Everything else is denied without a
     * prompt, which is what -p does with a tool that is not on the list.
     *
     * The run directory sits inside the application (its .env, the database
     * credentials, every other run's answer), so a bare Read or Write rule
     * would reach all of it. The rules are path-scoped, the helper refuses
     * other folders (V2_SANDBOX), and every tool call is recorded and checked
     * independently of the CLI's own enforcement (tool_calls.jsonl).
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
            // stream-json reports each step as it happens, which is what the
            // page's progress is read from; its last line is the same result
            // object that plain json output returns.
            '--output-format', 'stream-json', '--verbose',
            '--model', $cfg['model'],
            '--max-budget-usd', (string) $cfg['max_budget_usd'],
            '--restricted', '--strict-mcp-config',
            '--permission-mode', 'dontAsk',
            '--tools', 'Read,Write,Edit,Glob,Bash',
            '--allowedTools', 'Read(./**)', 'Glob(./**)', 'Write(./result.json)', 'Edit(./result.json)',
            "Bash({$py} tools/v2_tools.py show .:*)",
            "Bash({$py} tools/v2_tools.py contours .:*)",
            "Bash({$py} tools/v2_tools.py zoom .:*)",
            "Bash({$py} tools/v2_tools.py survey .:*)",
            "Bash({$py} tools/v2_tools.py validate .:*)",
            '--disallowedTools', 'WebFetch', 'WebSearch',
        ];
        if ($resume) {
            array_push($cmd, '--resume', $resume);
        }

        $env = ['CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC' => '1', 'V2_SANDBOX' => '1'];
        if (! empty($cfg['home'])) {
            $env['HOME'] = $cfg['home'];
        }

        $process = new Process($cmd, $dir, $env, $prompt, (float) $cfg['timeout']);
        $started = microtime(true);
        $watch = ['phase' => $resume ? 2 : 0, 'sheets' => [], 'views' => [],
                  'sheets_total' => (int) ceil(((int) $run->patches) / 16)];
        $resultLine = null;
        $buf = '';

        // Read the stream as it arrives rather than at the end: the lines that
        // carry the images the model looked at run to megabytes, and only the
        // tool calls and the final result are wanted.
        $process->start();
        while ($process->isRunning()) {
            usleep(300_000);
            $process->checkTimeout();
            $buf .= $process->getIncrementalOutput();
            $process->clearOutput();
            $buf = $this->consumeStream($run, $buf, $watch, $resultLine);
        }
        $this->consumeStream($run, $buf . $process->getIncrementalOutput() . "\n", $watch, $resultLine);

        $stdout = trim((string) $resultLine);
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

    /**
     * Complete lines of the stream: tool calls move the progress on, the
     * result line is kept. Returns the unfinished tail.
     */
    private function consumeStream(V2Diagnosis $run, string $buf, array &$watch, ?string &$resultLine): string
    {
        while (($nl = strpos($buf, "\n")) !== false) {
            $line = trim(substr($buf, 0, $nl));
            $buf = substr($buf, $nl + 1);
            // Tool results, with the images in them, are never needed.
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, '{"type":"user"')) {
                // Tool results carry the images and are otherwise skipped; a
                // permission denial is noted, so the audit knows the call
                // had no effect.
                if (str_contains($line, 'has been denied') || str_contains($line, 'No such tool available')) {
                    $this->recordDenials($run, (array) json_decode($line, true));
                }
                continue;
            }
            $ev = json_decode($line, true);
            if (! is_array($ev)) {
                continue;
            }
            if (($ev['type'] ?? null) === 'result') {
                $resultLine = $line;
            } elseif (($ev['type'] ?? null) === 'assistant') {
                foreach ($ev['message']['content'] ?? [] as $c) {
                    if (($c['type'] ?? null) === 'tool_use') {
                        $this->recordTool($run, (string) ($c['name'] ?? ''), (array) ($c['input'] ?? []), (string) ($c['id'] ?? ''));
                        $this->noteTool($run, (string) ($c['name'] ?? ''), (array) ($c['input'] ?? []), $watch);
                    }
                }
            }
        }
        return $buf;
    }

    /**
     * What one tool call says about how far the reading has got. The model
     * surveys the contact sheets, then opens tiles at full size, then writes
     * and validates its report; the phase only ever moves forward.
     */
    private function noteTool(V2Diagnosis $run, string $tool, array $in, array &$watch): void
    {
        $path = str_replace('\\', '/', (string) ($in['file_path'] ?? ''));
        $cmd = (string) ($in['command'] ?? '');
        $n = $watch['sheets_total'];

        if ($tool === 'Read' && preg_match('#sheets/sheet_(\d+)#', $path, $m)) {
            $watch['sheets'][(int) $m[1]] = true;
            if ($watch['phase'] === 0) {
                $k = count($watch['sheets']);
                $this->mark($run, 'survey', "Contact sheet {$k} of {$n} read", $k, $n);
            }
        } elseif ($tool === 'Read' && preg_match('#(overview|manifest)\.#', $path)) {
            if ($watch['phase'] === 0) {
                $this->mark($run, 'survey', 'Reading the slide overview and tile map', count($watch['sheets']), $n);
            }
        } elseif (($tool === 'Read' && preg_match('#view/(P\d+)\.jpg#', $path, $m))
            || ($tool === 'Bash' && preg_match('#v2_tools\.py (?:show|contours|zoom) \S+ (P\d+)#', $cmd, $m))) {
            if ($watch['phase'] > 1) {
                return;
            }
            $watch['phase'] = 1;
            $watch['views'][$m[1]] = true;
            $k = count($watch['views']);
            $what = $tool === 'Read' ? "Examining tile {$m[1]} at full resolution" : "Measuring nuclear density on tile {$m[1]}";
            $this->mark($run, 'detail', "{$what} — {$k} " . ($k === 1 ? 'tile' : 'tiles') . ' opened so far', $k);
        } elseif (in_array($tool, ['Write', 'Edit'], true) && str_ends_with($path, 'result.json')) {
            $watch['phase'] = 2;
            $this->mark($run, 'report', 'Writing the diagnosis report', null, null, 0.35);
        } elseif ($tool === 'Bash' && str_contains($cmd, 'v2_tools.py validate')) {
            $watch['phase'] = 2;
            $this->mark($run, 'report', 'Checking the report against the required format', null, null, 0.7);
        }
    }

    /**
     * Every tool call the model makes, appended to tool_calls.jsonl with the
     * rule it broke, if any. Attempts count, not only what got through: the
     * CLI denies a call after the stream has already shown it, and a run
     * whose model went looking outside its folder is not a blind reading.
     */
    private function recordTool(V2Diagnosis $run, string $tool, array $in, string $id = ''): void
    {
        $dir = $this->runDir($run);
        $violation = $this->toolViolation($dir, $tool, $in);
        $entry = json_encode([
            'at' => time(), 'id' => $id, 'tool' => $tool,
            'input' => array_intersect_key($in, array_flip(['file_path', 'path', 'pattern', 'command'])),
            'violation' => $violation,
            'outside' => $violation !== null && $this->reachesOutside($dir, $tool, $in),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        @file_put_contents("{$dir}/tool_calls.jsonl", $entry . "\n", FILE_APPEND);
    }

    /** The tool calls the CLI refused, from one tool-result line of the stream. */
    private function recordDenials(V2Diagnosis $run, array $ev): void
    {
        foreach ($ev['message']['content'] ?? [] as $c) {
            $text = is_string($c['content'] ?? null) ? $c['content'] : json_encode($c['content'] ?? '');
            if (($c['type'] ?? null) === 'tool_result' && ! empty($c['is_error']) && ! empty($c['tool_use_id'])
                && (str_contains((string) $text, 'has been denied') || str_contains((string) $text, 'No such tool available'))) {
                @file_put_contents($this->runDir($run) . '/tool_calls.jsonl',
                    json_encode(['at' => time(), 'denied' => $c['tool_use_id']]) . "\n", FILE_APPEND);
            }
        }
    }

    /**
     * Whether a call names anything outside the run folder: another path, a
     * parent directory, the home directory, a variable, or a tool that
     * reaches off the machine. Such an attempt voids a run even when the CLI
     * refused it; a refused call that stays inside the folder had no effect.
     */
    public function reachesOutside(string $dir, string $tool, array $in): bool
    {
        switch ($tool) {
            case 'Read':
            case 'Glob':
                return $this->toolViolation($dir, $tool, $in) !== null;
            case 'Grep':
            case 'LS':
                // Not allowed, but a search of the run folder reaches nothing outside it (run #70).
                return $this->toolViolation($dir, 'Glob', ['path' => $in['path'] ?? '.', 'pattern' => $in['glob'] ?? '']) !== null;
            case 'Write':
            case 'Edit':
                return $this->toolViolation($dir, 'Read', $in) !== null;
            case 'Bash':
                $c = (string) ($in['command'] ?? '');
                // ~ only as the home directory and $ only as a variable: "~0.95"
                // in a note (runs #21, #48) is text, not a path.
                if (preg_match('#\.\./|/\.\.|(?:^|[\s\'"=(,])~(?:/|[\s\'"]|$)|\$[A-Za-z_{(]|`#', $c)) {
                    return true;
                }
                preg_match_all('#(?:^|[\s\'"=(,])(/[^\s\'")<>;|&,]+)#', $c, $m);
                foreach ($m[1] as $path) {
                    // /dev/null discards output; runs #48 and #50 were voided for it alone.
                    if ($path !== $this->pythonBin() && $path !== '/dev/null'
                        && $this->toolViolation($dir, 'Read', ['file_path' => $path]) !== null) {
                        return true;
                    }
                }
                return false;
            default:
                return true;
        }
    }

    /** Why one tool call is outside what a run may do, or null when it is allowed. */
    public function toolViolation(string $dir, string $tool, array $in): ?string
    {
        $roots = array_unique(array_filter([self::normalisePath($dir), ($r = realpath($dir)) ? self::normalisePath($r) : null]));
        $resolve = function (string $p) use ($dir): string {
            $p = str_replace('\\', '/', $p);
            $absolute = str_starts_with($p, '/') || preg_match('#^[A-Za-z]:/#', $p);
            return self::normalisePath($absolute ? $p : "{$dir}/{$p}");
        };
        $inside = function (string $p) use ($resolve, $roots): bool {
            $p = $resolve($p);
            foreach ($roots as $root) {
                if ($p === $root || str_starts_with($p, $root . '/')) {
                    return true;
                }
            }
            return false;
        };

        switch ($tool) {
            case 'Read':
                $p = (string) ($in['file_path'] ?? '');
                return $p !== '' && $inside($p) ? null : "read outside the run folder: {$p}";
            case 'Glob':
                $base = (string) ($in['path'] ?? '.');
                $pattern = str_replace('\\', '/', (string) ($in['pattern'] ?? ''));
                if (! $inside($base) || str_contains($pattern, '..') || str_starts_with($pattern, '/')
                    || preg_match('#^[A-Za-z]:/#', $pattern) || str_starts_with($pattern, '~')) {
                    return "glob outside the run folder: {$base} {$pattern}";
                }
                return null;
            case 'Write':
            case 'Edit':
                $p = (string) ($in['file_path'] ?? '');
                return $p !== '' && $resolve($p) === $resolve('result.json') ? null : "wrote a file other than result.json: {$p}";
            case 'Bash':
                $c = trim((string) ($in['command'] ?? ''));
                $py = preg_quote($this->pythonBin(), '#');
                $helper = "#^{$py} tools/v2_tools\.py (show|contours|zoom|survey|validate) \.(?: [A-Za-z0-9_.\- ]*)?(?: 2>&1)?$#";
                // One helper, or several chained with && (run #29 zoomed three tiles in one call).
                $calls = preg_split('/\s*&&\s*/', $c);
                if (count(array_filter($calls, fn ($x) => preg_match($helper, $x))) === count($calls)) {
                    return null;
                }
                // The CLI lets plain read-only commands on the folder through
                // (runs #5-#24 used `cat manifest.json && ls sheets view`);
                // they are fine as long as no argument leaves the folder.
                $segs = preg_split('/\s*(?:&&|\|)\s*/', $c);
                foreach ($segs as $seg) {
                    if (! preg_match('#^(cat|ls|head|tail|wc)(?: [A-Za-z0-9_.\-*/ ]*)?$#', $seg)
                        || preg_match('#(\.\.|~)#', $seg)) {
                        return "ran a command other than the helpers: {$c}";
                    }
                    // A full path is fine when it is the run folder itself
                    // (runs #106, #109: `ls /var/www/.../v2_diagnose/106`).
                    preg_match_all('#(?:^|\s)(/\S+)#', $seg, $abs);
                    foreach ($abs[1] as $path) {
                        if (! $inside($path)) {
                            return "ran a command other than the helpers: {$c}";
                        }
                    }
                }
                return null;
            default:
                return "used a tool that is not allowed: {$tool}";
        }
    }

    /** A path with . and .. resolved lexically, / separators, no trailing slash. */
    private static function normalisePath(string $path): string
    {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $seg;
        }
        return '/' . implode('/', $parts);
    }

    /**
     * What makes a finished analysis untrustworthy: a helper script that no
     * longer matches the copy made for the run, or any recorded tool call
     * outside the run's limits. Empty when the run is clean. Checked before
     * finalize, which executes the run's copy of the helper.
     *
     * @return string[]
     */
    public function integrityViolations(V2Diagnosis $run): array
    {
        $dir = $this->runDir($run);
        $out = [];
        $want = trim((string) @file_get_contents("{$dir}/tools.sha256"));
        $tools = "{$dir}/tools/v2_tools.py";
        if ($want === '' || ! is_file($tools) || ! hash_equals($want, (string) hash_file('sha256', $tools))) {
            $out[] = 'the helper script tools/v2_tools.py changed during the analysis';
        }
        $calls = array_map(fn ($l) => (array) json_decode($l, true),
            @file("{$dir}/tool_calls.jsonl", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
        $denied = array_flip(array_filter(array_column($calls, 'denied')));
        foreach ($calls as $e) {
            // A refused call that stayed inside the folder changed nothing;
            // anything that reached outside counts, refused or not.
            if (! empty($e['violation']) && (! empty($e['outside']) || ! isset($denied[$e['id'] ?? '']))) {
                $out[] = $e['violation'];
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Terms in the case details the model is given that would tell it the
     * answer or which archive the slide came from: the slide's own
     * identifiers, its recorded diagnosis, and archive names. The model must
     * read the tissue blind to all of them.
     *
     * @return string[]
     */
    public function blindingLeaks(V2Diagnosis $run): array
    {
        $text = implode("\n", array_filter([$run->organ, $run->stain, $run->race, $run->clinical_notes]));
        if (trim($text) === '') {
            return [];
        }
        $terms = ['TCGA', 'BRACS', 'CPTAC', 'CAMELYON', 'BACH', 'GDC'];
        if ($s = $run->sample) {
            $s->loadMissing('category:id,label_en', 'diseaseSubtype:id,name');
            array_push($terms, $s->entity_submitter_id, pathinfo((string) $s->file_name, PATHINFO_FILENAME),
                $s->category?->label_en, $s->diseaseSubtype?->name);
        }
        $found = [];
        foreach (array_unique(array_filter(array_map(fn ($t) => trim((string) $t), $terms), fn ($t) => mb_strlen($t) >= 2)) as $t) {
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($t, '/') . '(?![\p{L}\p{N}])/iu', $text)) {
                $found[] = $t;
            }
        }
        if (preg_match('/TCGA-[A-Z0-9]{2}-[A-Z0-9]{4}/i', $text, $m)) {
            $found[] = $m[0];
        }
        return array_values(array_unique($found));
    }

    /** The slide copied off the Drive mount in pieces, reporting as it goes. */
    private function copyWithProgress(V2Diagnosis $run, string $from, string $to): void
    {
        $size = (int) @filesize($from);
        $mb = fn (int $b) => number_format($b / 1048576);
        $in = @fopen($from, 'rb');
        $out = $in ? @fopen($to, 'wb') : false;
        if (! $in || ! $out) {
            throw new \RuntimeException('Could not copy the slide from Drive for tiling.');
        }
        $this->mark($run, 'fetching', 'Copying the slide from storage', 0, $size ?: null);
        $copied = 0;
        $last = microtime(true);
        try {
            while (! feof($in)) {
                $chunk = fread($in, 8 << 20);
                if ($chunk === false || ($chunk !== '' && fwrite($out, $chunk) !== strlen($chunk))) {
                    throw new \RuntimeException('Could not copy the slide from Drive for tiling.');
                }
                $copied += strlen($chunk);
                if (microtime(true) - $last >= 2) {
                    $last = microtime(true);
                    $this->mark($run, 'fetching', "Copying the slide from storage — {$mb($copied)} of {$mb($size)} MB",
                        $copied, $size ?: null);
                }
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /**
     * Record where a run is, for the page: the step, how far through it, and
     * when each step began. One small file beside the run's other records;
     * tiling and preparation write their own counts to progress_py.json.
     */
    public function mark(V2Diagnosis $run, string $step, ?string $detail = null,
                         ?int $done = null, ?int $total = null, ?float $frac = null): void
    {
        $dir = $this->runDir($run);
        File::ensureDirectoryExists($dir);
        $file = "{$dir}/progress.json";
        $started = $this->readJson($file)['started'] ?? [];
        $started[$step] ??= time();
        $p = ['step' => $step, 'detail' => $detail, 'done' => $done, 'total' => $total,
              'frac' => $frac, 'updated' => time(), 'started' => $started];
        @file_put_contents("{$file}.tmp", json_encode($p, JSON_UNESCAPED_UNICODE));
        @rename("{$file}.tmp", $file);
    }

    private function readJson(string $file): array
    {
        return is_file($file) ? (json_decode((string) @file_get_contents($file), true) ?: []) : [];
    }

    /** The steps of a run as the page shows them: label, and its share of the whole in percent. */
    public const STEPS = [
        'queued'     => ['Waiting in the analysis queue', 0, 2],
        'fetching'   => ['Fetching the slide from storage', 2, 8],
        'tiling'     => ['Cutting the whole slide into tiles', 8, 25],
        'preparing'  => ['Preparing the tiles for the AI model', 25, 35],
        'survey'     => ['AI model surveying the whole slide', 35, 62],
        'detail'     => ['AI model examining key tiles at full resolution', 62, 82],
        'report'     => ['Receiving the diagnosis from the AI analysis model', 82, 90],
        'finalising' => ['Checking the result and mapping it onto the slide', 90, 99],
    ];

    /** The step a run status stands for, refined by the step last marked. */
    private function stepOf(string $status, ?string $marked): ?string
    {
        return match ($status) {
            'queued'        => 'queued',
            'waiting_slide' => 'fetching',
            'waiting_quota' => 'queued',
            'tiling'        => in_array($marked, ['fetching', 'tiling'], true) ? $marked : 'tiling',
            'preparing'     => 'preparing',
            'analysing'     => in_array($marked, ['survey', 'detail', 'report'], true) ? $marked : 'survey',
            'finalising'    => 'finalising',
            default         => null,
        };
    }

    /**
     * A run's progress for the page: the overall percentage, the step being
     * worked on and what exactly is happening in it, and every step with its
     * state and how long it took.
     */
    public function progress(V2Diagnosis $run): array
    {
        $dir = $this->runDir($run);
        $p = $this->readJson("{$dir}/progress.json");
        $marked = $p['step'] ?? null;
        $status = $run->status;

        // When each step began: from the progress file where it has it,
        // otherwise from the stage log (runs begun before the file existed).
        $started = array_map('intval', $p['started'] ?? []);
        $started['queued'] ??= $run->created_at?->timestamp;
        $lastRunning = null;
        foreach ($run->events ?? [] as $e) {
            if ($k = $this->stepOf((string) ($e['status'] ?? ''), null)) {
                $started[$k] ??= strtotime((string) $e['at']) ?: null;
                $lastRunning = (string) $e['status'];
            }
        }

        $current = match ($status) {
            'completed' => 'finalising',
            'failed'    => isset(self::STEPS[$marked]) ? $marked : $this->stepOf((string) $lastRunning, null),
            default     => $this->stepOf((string) $status, $marked),
        } ?? 'queued';

        $detail = $done = $total = $frac = null;
        if ($marked === $current) {
            [$detail, $done, $total, $frac] = [$p['detail'] ?? null, $p['done'] ?? null, $p['total'] ?? null, $p['frac'] ?? null];
        }
        if (in_array($current, ['tiling', 'preparing'], true)) {
            $py = $this->readJson("{$dir}/progress_py.json");
            if (($py['phase'] ?? null) === ($current === 'tiling' ? 'tile' : 'prepare')) {
                [$detail, $done, $total, $frac] = [$py['detail'] ?? $detail, $py['done'] ?? null, $py['total'] ?? null, null];
            }
        }
        if ($frac === null && $total) {
            $frac = min(1, $done / $total);
        } elseif ($frac === null && $current === 'detail' && $done) {
            $frac = min(0.95, $done / 45);       // a large slide opens about 30-60 tiles
        }

        [, $lo, $hi] = self::STEPS[$current];
        $percent = $status === 'completed' ? 100 : (int) floor($lo + ($hi - $lo) * max(0, min(1, (float) $frac)));

        $keys = array_keys(self::STEPS);
        $at = array_search($current, $keys, true);
        $steps = [];
        foreach ($keys as $i => $k) {
            $state = match (true) {
                $status === 'completed' || $i < $at => 'done',
                $i === $at => $status === 'failed' ? 'failed' : 'active',
                default => 'pending',
            };
            // A finished step lasted until the next step that has a start time.
            $until = null;
            foreach (array_slice($keys, $i + 1) as $next) {
                if (isset($started[$next])) {
                    $until = $started[$next];
                    break;
                }
            }
            $until ??= $status === 'completed' ? $run->finished_at?->timestamp : null;
            $steps[] = [
                'key' => $k, 'label' => self::STEPS[$k][0], 'state' => $state,
                'seconds' => $state === 'done' && isset($started[$k]) && $until ? max(0, $until - $started[$k]) : null,
            ];
        }

        return [
            'percent' => $percent,
            'step'    => $current,
            'label'   => self::STEPS[$current][0],
            'detail'  => $detail,
            'done'    => $done,
            'total'   => $total,
            'frac'    => $frac,
            'since'   => $started[$current] ?? null,
            'began'   => $run->started_at?->timestamp ?? $run->created_at?->timestamp,
            'now'     => time(),
            'steps'   => $steps,
        ];
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
