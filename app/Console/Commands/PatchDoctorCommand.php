<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Diagnostic command for the patch-extraction pipeline.
 *
 *   php artisan patch:doctor
 *
 * Verifies that:
 *   1. PYTHON_PATH (or python3) resolves to a real interpreter.
 *   2. The required Python packages are importable
 *      (openslide, cv2, numpy, PIL).
 *   3. scripts/patch_extract.py is readable.
 *   4. rclone is on PATH.
 *
 * Use this on production whenever PatchExtractionJob fails with
 * "patch_extract.py failed (exit 1)".
 */
class PatchDoctorCommand extends Command
{
    protected $signature   = 'patch:doctor';
    protected $description = 'Diagnose the patch-extraction pipeline (Python deps, rclone, script paths).';

    public function handle(): int
    {
        $this->info('=== Patch Extraction Pipeline Doctor ===');

        $ok = true;

        // 1) Python interpreter
        $python = (string) env('PYTHON_PATH', 'python3');
        $this->line("PYTHON_PATH = {$python}");

        // Cross-platform interpreter check: just try to run `<python> --version`.
        try {
            $ver = new Process([$python, '--version']);
            $ver->setTimeout(15);
            $ver->run();
            $verOk  = $ver->isSuccessful();
            $verOut = trim($ver->getOutput() . $ver->getErrorOutput());
        } catch (\Throwable $e) {
            $verOk  = false;
            $verOut = $e->getMessage();
        }

        if (!$verOk) {
            $this->error("  ✗ Python interpreter not runnable.");
            if ($verOut !== '') {
                $this->line('    ' . $verOut);
            }
            $this->warn("    Set PYTHON_PATH in .env to an absolute path, e.g.");
            $this->warn("      PYTHON_PATH=/home/uXXX/venv/bin/python");
            $ok = false;
        } else {
            $this->info("  ✓ Runs: {$verOut}");
        }

        // 2) Python packages
        $this->line('');
        $this->info('Checking Python packages:');
        $packages = [
            'openslide' => 'openslide-python',
            'cv2'       => 'opencv-python-headless',
            'numpy'     => 'numpy',
            'PIL'       => 'Pillow',
        ];

        foreach ($packages as $module => $pip) {
            $check = new Process([$python, '-c', "import {$module}; print({$module}.__name__)"]);
            $check->setEnv(['PYTHONUNBUFFERED' => '1']);
            $check->run();

            if ($check->isSuccessful()) {
                $this->info("  ✓ {$module} OK");
            } else {
                $this->error("  ✗ {$module} MISSING — install with: pip install {$pip}");
                $err = trim($check->getErrorOutput());
                if ($err !== '') {
                    $this->line("    " . substr($err, 0, 300));
                }
                $ok = false;
            }
        }

        // 3) Script file
        $this->line('');
        $script = base_path('scripts/patch_extract.py');
        $this->line("Script: {$script}");
        if (is_file($script) && is_readable($script)) {
            $this->info('  ✓ Readable');
        } else {
            $this->error('  ✗ Missing or not readable');
            $ok = false;
        }

        // 4) rclone (cross-platform: try `rclone version`)
        $this->line('');
        try {
            $rcloneVer = new Process(['rclone', 'version']);
            $rcloneVer->setTimeout(10);
            $rcloneVer->run();
            $rcloneOk = $rcloneVer->isSuccessful();
        } catch (\Throwable $e) {
            $rcloneOk = false;
        }
        if ($rcloneOk) {
            $firstLine = strtok(trim($rcloneVer->getOutput()), "\n");
            $this->info("rclone:   ✓ {$firstLine}");
        } else {
            $this->error('rclone:   ✗ Not on PATH (Google Drive download will fail on production)');
            $ok = false;
        }

        // 5) Quick smoke test — run the script with --help
        if ($ok) {
            $this->line('');
            $this->info('Smoke-testing patch_extract.py --help …');
            $smoke = new Process([$python, $script, '--help']);
            $smoke->setEnv(['PYTHONUNBUFFERED' => '1']);
            $smoke->setTimeout(30);
            $smoke->run();
            if ($smoke->isSuccessful()) {
                $this->info('  ✓ Script runs cleanly.');
            } else {
                $this->error('  ✗ Script crashed:');
                $this->line(trim($smoke->getErrorOutput()));
                $this->line(trim($smoke->getOutput()));
                $ok = false;
            }
        }

        $this->line('');
        if ($ok) {
            $this->info('All checks passed ✓');
            return self::SUCCESS;
        }

        $this->error('One or more checks FAILED — fix the items above before re-running PatchExtractionJob.');
        return self::FAILURE;
    }
}
