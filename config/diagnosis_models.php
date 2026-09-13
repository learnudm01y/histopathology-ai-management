<?php

/*
|--------------------------------------------------------------------------
| Deployed diagnosis models
|--------------------------------------------------------------------------
|
| One entry per model that may be run from the AI workflow page. Adding a
| model is adding an entry here — no controller or view changes — which is
| what keeps the page useful as more models are trained.
|
| `requires` is enforced before a slide is scored. A model trained on TITAN
| features at 224px cannot read Virchow2 features, and scoring them anyway
| produces a number that looks exactly like a valid one. The workflow refuses
| instead, and says which condition failed.
|
| `runner` picks how the model is executed:
|   sklearn_local — a pickled scikit-learn pipeline, run by a local Python.
|                   Milliseconds on CPU; renting a GPU for it would be theatre.
|   runpod_clam   — a PyTorch checkpoint on a GPU pod, via the existing
|                   inference job. Reserved for models that genuinely need it.
|
*/

return [

    'default' => 'idc_ilc_titan_v1',

    'models' => [

        'idc_ilc_titan_v1' => [
            'label'       => 'IDC vs ILC — TITAN features, linear',
            'description' => 'Invasive ductal against invasive lobular carcinoma on FFPE '
                           . 'diagnostic slides. Trained on 236 TCGA-BRCA slides, 118 per class, '
                           . 'site-matched across 18 laboratories.',
            'runner'      => 'sklearn_local',

            'python'   => env('HISTO_AI_PYTHON', '/opt/histo-venv/bin/python'),
            'script'   => env('HISTO_AI_HOME', '/opt/histo-idc-ilc') . '/10_predict.py',
            'artefact' => env('HISTO_AI_HOME', '/opt/histo-idc-ilc') . '/model/idc_ilc_model.pkl',

            // Checked against the slide before it is scored.
            'requires' => [
                'feature_model' => 'TITAN',
                'patch_px'      => 224,
                'magnification' => '20x',
                'min_patches'   => 100,
            ],

            'classes' => ['IDC', 'ILC'],

            // Shown on the page so the reader sees the model's limits next to
            // its answer, rather than having to go and find them.
            'performance' => [
                'auc_unseen_sites' => 0.954,
                'auc_ci'           => [0.927, 0.977],
                'brier'            => 0.083,
                'calibration_err'  => 0.059,
                'answers_pct'      => 77.5,
                'accuracy_when_answering' => 95.1,
                'trained_on'       => 236,
            ],

            'limits' => [
                'FFPE diagnostic slides only — never frozen sections',
                'Invasive breast carcinoma only — benign, DCIS and lymph nodes are out of scope',
                'Two answers and no third: an out-of-scope slide is flagged, not classified',
                'No external validation exists for this task in any public archive',
                'Research prototype — every call needs a pathologist',
            ],

            'card' => env('HISTO_AI_HOME', '/opt/histo-idc-ilc') . '/MODEL_CARD.md',
        ],

    ],

];
