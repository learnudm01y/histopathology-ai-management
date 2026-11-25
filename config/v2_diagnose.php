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
        // Tiles are stored at the view scale (this edge, ~1 um/px), read from
        // the pyramid level nearest it; zoom and survey read full resolution
        // from the slide only where they cut a crop. On a 40x slide that is
        // 11x faster than storing full-resolution tiles (2026-11-25).
        'stored_px'        => (int) env('V2_STORED_PX', 1000),
    ],

    // Slides prepared once — tiles, view images, sheets, density and the slide
    // copy the crops are read from — and reused by every run on them, so a run
    // on a prepared slide starts at the analysis. Built by the first run, or in
    // advance: on upload for analysis, or `php artisan v2:prepare`. The slides
    // used longest ago are removed above max_gb (0 keeps nothing), and while
    // the disk has less than min_free_gb left (the Drive mount's own cache
    // grows to 20 GB on the same disk).
    'prepared' => [
        'dir'       => env('V2_PREPARED_DIR', storage_path('app/v2_prepared')),
        'max_gb'    => (float) env('V2_PREPARED_MAX_GB', 10),
        'min_free_gb' => (float) env('V2_PREPARED_MIN_FREE_GB', 10),
        'on_upload' => (bool) env('V2_PREPARE_ON_UPLOAD', true),
    ],

    // How a slide is copied off Drive to be prepared: straight from Drive with
    // rclone (through the mount, its cache would keep a second copy of every
    // slide on the same disk — 2026-11-25), through the mount when that fails.
    'fetch' => [
        'rclone'  => env('V2_RCLONE_BIN', '/usr/bin/rclone'),
        'config'  => env('V2_RCLONE_CONFIG', storage_path('app/rclone.conf')),
        'remote'  => env('V2_RCLONE_REMOTE', 'gdrive:'),
        'streams' => (int) env('V2_RCLONE_STREAMS', 8),
    ],

    // Where a run's slide sits during its analysis, outside every run folder
    // (the model's file tools reach only its own folder).
    'slides_dir' => env('V2_SLIDES_DIR', storage_path('app/v2_slides')),

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
    // Slides whose tissue contradicts their recorded diagnosis, with the
    // evidence. Their runs show as "under review", counted neither right nor
    // wrong, until a pathologist settles the slide; then remove the entry.
    // Never add a slide here to improve a score: only with evidence from the
    // slide itself, written down in V2_DIAGNOSE_LESSONS.md.
    'label_review' => [
        1609 => 'TCGA-75-5122 DX1: every tile is an eosinophil-rich histiocytic and lymphoid infiltrate '
              . '(likely smoking-related Langerhans cell histiocytosis) with no recognisable adenocarcinoma; '
              . 'the case is adenocarcinoma, stage IB, so this block may not hold the tumour',
    ],

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
        1581,   // TCGA-02-0001 (GBM): called PXA, run #62
        1594,   // TCGA-DB-5270 (LGG): GBM called on a tissue tear, run #74
        1612,   // TCGA-56-1622 (LUSC): NSCLC despite keratin pearls, run #91
        1609,   // TCGA-75-5122 (LUAD): PLCH called on an inflammation-dominated slide, run #83
        // 2026-11-25, "Grade and written findings": grades checked against the
        // pathology reports (v2:written-check). Report grade → written grade.
        1569,   // TCGA-2A-A8VL (PRAD): Gleason 3+3 → 3+4, run #97
        1570,   // TCGA-XK-AAIR (PRAD): Gleason 4+4 → 4+5, run #98
        1565,   // TCGA-YL-A8HJ (PRAD): Gleason 4+5 → 4+3, run #100
        1567,   // TCGA-J4-8198 (PRAD): Gleason 4+3 → 3+4, run #101
        1560,   // TCGA-HC-7075 (PRAD): Gleason 3+3 → 3+4, run #103
        1566,   // TCGA-CH-5737 (PRAD): Gleason 4+3 → 4+4, run #107
        1577,   // TCGA-TP-A8TT (PRAD): Gleason 4+3 → 4+5, run #108
        1575,   // TCGA-HI-7168 (PRAD): Gleason 4+5 → 4+3, run #110
        1564,   // TCGA-V1-A8MF (PRAD): Gleason 3+3 → 3+4, run #112
        1568,   // TCGA-VP-A872 (PRAD): Gleason 5+3 → 3+3, run #113
        1576,   // TCGA-H9-7775 (PRAD): Gleason 3+4 → 3+3, run #115
        1589,   // TCGA-41-2571 (GBM): CNS WHO grade 4 → 3, run #59
        1593,   // TCGA-FG-5962 (LGG): anaplastic oligodendroglioma, grade 3 → 2, run #67
        1595,   // TCGA-QH-A65R (LGG): anaplastic oligodendroglioma, grade 3 → 2, run #70
        1597,   // TCGA-E1-A7YD (LGG): anaplastic astrocytoma, grade 3 → 4, runs #73/#121
        1601,   // TCGA-44-2655 (LUAD): moderately differentiated → IASLC 3, run #81
        1603,   // TCGA-86-6562 (LUAD): poorly differentiated → IASLC 2, run #85
        1619,   // TCGA-21-1070 (LUSC): poorly → poorly-to-moderately, run #87
        1610,   // TCGA-85-6175 (LUSC): well → moderately differentiated, run #89
        1613,   // TCGA-66-2727 (LUSC): poorly → poorly-to-moderately, run #90
        1618,   // TCGA-18-3406 (LUSC): well → moderately differentiated, run #92
        1505,   // TCGA-MS-A51U (ILC): Nottingham 2 → "high grade", run #3
        1395,   // TCGA-AC-A23C (IDC): Nottingham 1 → 2, run #15
        1396,   // TCGA-AC-A62V (IDC): Nottingham 3 → 2, run #16
        1544,   // TCGA-AN-A0XW (IDC): grade 2 → "high-grade", run #19
    ],
];
