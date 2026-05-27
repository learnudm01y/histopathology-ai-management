-- ═══════════════════════════════════════════════════════════════════════════
-- ADD CLAM TO SYSTEM: ai_models + servers_names
-- Run on production DB: histo_training
-- ═══════════════════════════════════════════════════════════════════════════

-- ── 1. Insert CLAM into ai_models ─────────────────────────────────────────
--    model_type = 'classification'  (MIL classification head, not a foundation model)
--    level      = 'slide'           (WSI-level inference)
-- --------------------------------------------------------------------------
INSERT INTO ai_models (
    name,
    full_name,
    provider,
    version,
    model_type,
    level,
    paper_url,
    repo_url,
    description,
    notes,
    is_active,
    is_default,
    created_at,
    updated_at
) VALUES (
    'CLAM',
    'Clustering-constrained Attention Multiple Instance Learning',
    'MahmoodLab',
    'v1',
    'classification',
    'slide',
    'https://arxiv.org/abs/2004.09666',
    'https://github.com/mahmoodlab/CLAM',
    'Attention-based MIL framework for WSI classification. Takes pre-extracted patch features (e.g. from TITAN or Virchow2) and trains a slide-level classifier with gated attention pooling.',
    'Two variants: CLAM-SB (single-branch) and CLAM-MB (multi-branch). Includes instance-level clustering loss for interpretability.',
    1,
    0,
    NOW(),
    NOW()
);

-- Verify:
-- SELECT id, name, model_type, level, is_active FROM ai_models ORDER BY id DESC LIMIT 5;


-- ── 2. Generate a unique API key for the CLAM server ─────────────────────
--    Replace <CLAM_API_KEY_HERE> with the output of:
--    python3 -c "import secrets; print(secrets.token_urlsafe(40))"
--    or use any UUID generator.
-- --------------------------------------------------------------------------

-- ── 3. Insert RunPod CLAM Server into servers_names ───────────────────────
--    server_id will be 4 (auto-increment).
--    api_url will be filled in by the pod at boot via self-registration.
-- --------------------------------------------------------------------------
INSERT INTO servers_names (
    name,
    type,
    host,
    api_url,
    api_key,
    runpod_api_key,
    runpod_network_volume_id,
    runpod_template_id,
    description,
    is_active,
    created_at,
    updated_at
) VALUES (
    'RunPod CLAM Server',
    'external',
    'runpod.io',
    NULL,                                                   -- filled by pod at boot
    '85aff342ec82334f17df791b5ade309ecc441b7983381379dcad59f74d9fb822', -- ← CLAM server api_key
    '<RUNPOD_API_KEY_HERE>',                                -- ← your RunPod management key (rpa_...)
    '<NETWORK_VOLUME_ID_HERE>',                             -- network volume
    '9wz7zcfaxb',                                           -- pod template
    'RunPod GPU pod — CLAM MIL training server (port 8002)',
    1,
    NOW(),
    NOW()
);

-- Verify:
-- SELECT id, name, type, is_active FROM servers_names ORDER BY id;


-- ── 4. Confirm resulting server_id ────────────────────────────────────────
-- Expected: server_id=4 for CLAM (after Virchow2=2, TITAN=3)
SELECT id, name, type, is_active FROM servers_names ORDER BY id;
SELECT id, name, model_type, level, is_active FROM ai_models ORDER BY id;
