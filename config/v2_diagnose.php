<?php

/*
|--------------------------------------------------------------------------
| V2 Diagnose — Claude reads the tiles, the platform draws the answer
|--------------------------------------------------------------------------
|
| A slide is tiled at a fixed physical scale, the tiles and the clinical
| context are handed to the Claude Code CLI in headless mode, and what comes
| back is coordinates — never pictures. Those coordinates are converted to
| level-0 slide pixels here and drawn on the live SVS in the viewer.
|
| Tiles are 1904 px at 0.5 um/px, the scale of the reference results, and
| the whole slide is tiled: every tile that holds any tissue, with no cap
| (a 100-tile cap once left most of a large slide unread). Change the scale
| and every stored coordinate stays valid, because each run records it.
*/

return [

    // ── Claude Code CLI ─────────────────────────────────────────────────
    'claude' => [
        // Absolute path is safest under a queue worker, whose PATH is not yours.
        'binary'    => env('V2_CLAUDE_BIN', 'claude'),
        'model'     => env('V2_CLAUDE_MODEL', 'claude-opus-5-5'),
        // A ceiling on how much one call may consume, measured in the CLI's
        // API-price estimate (it applies on a subscription login too, where
        // nothing is billed: there it simply stops a run that goes on too long).
        // The first full run used ~$6-equivalent.
        'max_budget_usd' => (float) env('V2_CLAUDE_MAX_BUDGET_USD', 40),
        // One run reads every tile; an hour is generous, two is the ceiling.
        'timeout'   => (int) env('V2_CLAUDE_TIMEOUT', 5400),
        // How many times a run whose result fails validation is sent back to
        // the same Claude session with the errors, before the run is failed.
        'repair_attempts' => (int) env('V2_CLAUDE_REPAIR_ATTEMPTS', 2),
        // HOME for the CLI when the worker user is not the one logged in to
        // Claude (e.g. www-data). Leave empty to inherit the worker's own.
        'home'      => env('V2_CLAUDE_HOME'),
    ],

    // ── Python (the same interpreter patch extraction uses) ─────────────
    'python' => env('V2_PYTHON', env('PYTHON_PATH', 'python3')),

    // ── Tiling ──────────────────────────────────────────────────────────
    'tiling' => [
        'patch_size'       => (int) env('V2_PATCH_SIZE', 1904),
        'target_mpp'       => (float) env('V2_TARGET_MPP', 0.5),
        // No cap and no tissue threshold: every tile with any tissue is read
        // (scripts/v2_tools.py tile), and the coverage is measured per run.
        'workers'          => (int) env('V2_PATCH_WORKERS', 2),
    ],

    // Edge of the image Claude actually sees. The Read tool shrinks anything
    // above ~1.15 megapixels, and a coordinate given on a shrunk image is off
    // by the shrink factor — so Claude is shown exactly this size and answers
    // in exactly this space.
    'view_px' => 1000,

    // Heat grid per tile: 25 x 25 cells of 40 view px (~38 um at 0.5 mpp).
    'grid' => 25,

    // Where runs live. Full-resolution tiles are deleted once a run finishes
    // (hundreds of MB each); the view images Claude read are kept as evidence.
    'runs_dir'          => env('V2_RUNS_DIR', storage_path('app/v2_diagnose')),
    'keep_full_patches' => (bool) env('V2_KEEP_FULL_PATCHES', false),

    // Optional SAM refinement of Claude's prompts. Left empty, Claude's own
    // polygons are drawn and its SAM prompts are shown as points and boxes.
    'sam' => [
        'checkpoint' => env('V2_SAM_CHECKPOINT'),
        'model'      => env('V2_SAM_MODEL', 'vit_b'),
        'device'     => env('V2_SAM_DEVICE', 'cpu'),
    ],

    // Where the live viewer gets slide tiles.
    //   nginx  : /wsi/{sample}.dzi, the tile service the AI workflow viewer uses (production)
    //   direct : straight from scripts/wsi_tile_server.py at tile_server_url — for a local
    //            machine without nginx. It puts the slide's file path in the page, so it
    //            is for development only.
    'tile_source'     => env('V2_TILE_SOURCE', 'nginx'),
    'tile_server_url' => env('V2_TILE_SERVER_URL', 'http://127.0.0.1:8001'),

    // Queue: a run holds one worker for up to the Claude timeout, so it gets a
    // connection whose retry_after is longer than that (see config/queue.php)
    // and a queue of its own, so it never blocks patch extraction.
    'queue_connection' => env('V2_QUEUE_CONNECTION', 'database_long'),
    'queue'            => env('V2_QUEUE', 'v2'),

    // ── Evaluation (php artisan v2:evaluate) ─────────────────────────────
    // Slides whose errors were studied to write the rules in the prompt
    // (resources/prompts/V2_DIAGNOSE_LESSONS.md). A correct answer on them is expected,
    // not evidence, so the report counts them apart from unseen slides.
    // Add a slide here whenever a rule is written from it.
    'tuning_samples' => [
        1062,   // BRACS_1408 (PB): LCIS called on clear-cell adenosis, run #25
        1138,   // TCGA-AC-A2FO (ILC): called IDC, run #21
        938,    // TCGA-E9-A1R4 (IDC): called ILC despite true lumens, run #36
        222,    // TCGA-GM-A2D9 (Normal): IDC called on crushed frozen tissue, run #7
        85,     // TCGA-E9-A1RF (Normal): NONDX on fat and stroma only, run #6
        125,    // TCGA-E9-A1NF (Normal): NONDX on scant frozen fragments, run #8
        299,    // TCGA-BH-A1EW (Normal): NONDX on stroma only, run #51
        475,    // TCGA-E2-A1IG (Normal): NONDX on scant frozen fragments, runs #48/#53
    ],
];
