# V2 Diagnose — wrong answers, their causes, and what stops them

Every wrong answer that was studied, what the tissue actually showed, why the
model got it wrong, and the change it produced. Read this before changing
`resources/prompts/v2_diagnose.md`, `scripts/v2_tools.py` or the isolation in
`app/Services/V2DiagnoseRunner.php`.

## The rules of the process

1. **Look at the tissue before blaming the model.** Read the regions the model
   based its call on at full resolution from the original slide (40×,
   `openslide read_region` at level 0), and decide whether the archive label or
   the model is wrong. A label can be wrong, and BRACS has no class for lobular
   neoplasia.
2. **A rule is general pathology, never a case.** No slide name, tile id or
   answer goes into the prompt. A rule must also guard against the opposite
   error (a lobular rule must not make every tumour lobular).
3. **A slide a rule was written from is a tuning sample.** Add its sample id to
   `config/v2_diagnose.php` → `tuning_samples`. A correct answer on it after
   the rule is expected, not evidence.
4. **A rule is proved on slides it was not written from.**
   `php artisan v2:evaluate --queue --per-class=5` runs unseen slides blind;
   `php artisan v2:evaluate` scores them. Accuracy is counted per prompt
   version (`prompt_version` in each run folder), held-out apart from tuning.
5. **A voided run is not an answer.** A run failed by the integrity check is
   reported as VOID. Its draft result.json is never counted, however right it
   looks.

## Cases

### Run #25 — BRACS_1408, recorded PB, answered LCIS (0.55)

- **Tissue at 40×:** a lobulocentric nodule of crowded small acini, most with
  **open lumens** holding secretion, cells with abundant clear or vacuolated
  cytoplasm lining the lumens, a flat myoepithelial rim, units not expanded.
  This is adenosis with clear-cell or pseudolactational change, benign as
  recorded.
- **Why wrong:** the model saw it at about 1 µm/px (a 10× objective), where
  vacuolated cells around small lumens merge into "filled acini". It had no
  code for doubt (ALH, SUSP), and no rule stopped a carcinoma-in-situ code at
  low confidence.
- **Changes:** the `zoom` helper (0.5 µm/px crops) and a duty to zoom before
  any in-situ, atypia or lobular call; written criteria for LCIS/ALH,
  DCIS/ADH and invasion; the list of benign mimics to rule out by name; a 0.65
  confidence floor for carcinoma codes, SUSP below it; codes ALH, ADH, FEA,
  SUSP, SA, PAP. (562ba5e)
- **After:** run #32, SA (0.55), correct. Tuning sample, not evidence.

### Run #21 — TCGA-AC-A2FO, recorded ILC, answered IDC (0.80)

- **Tissue at 40×:** single cells and single files in dense collagen, loosely
  cohesive cords of cells with abundant pale or foamy cytoplasm and vesicular
  nuclei with nucleoli, rounded lumen-less nests (alveolar pattern), **no
  tubules anywhere**. This is pleomorphic or histiocytoid ILC with alveolar
  areas, consistent with the label (E-cadherin would confirm).
- **Why wrong:** the model took large, atypical nuclei as evidence against a
  lobular type, and read the alveolar nests as NST nests or vascular invasion.
  It noted "no tubules" and a lobular differential, yet answered IDC at 0.80.
- **Changes:** the type is decided by growth pattern and cohesion, not by
  nuclear size. The prompt lists the ILC variants, the features that point
  each way, MIXED when both are substantial, and that artefactual discohesion
  does not count. (d024924)
- **After:** run #31, ILC (0.70), correct. Tuning sample, not evidence.

### Run #36 — TCGA-E9-A1R4, recorded IDC, answered ILC (0.70), held-out

- **Tissue at 40×:** cohesive cords and trabeculae with **true small lumens
  lined by polarised tumour cells** (tubule formation), next to solid sheets of
  large polygonal cells with distinct borders and plasmacytoid nuclei. This is
  high-grade IDC NST; the solid areas resemble pleomorphic ILC only at a
  glance.
- **Why wrong:** an overcorrection by the #21 rule. The model anchored on the
  solid plasmacytoid sheets, noted "rosette-like lumina" but did not zoom into
  them, and declared "no convincing tubules".
- **Changes:** before ILC, zoom into at least three separate tumour areas
  looking for lumens; true glands make pure ILC unlikely; solid sheets of large
  plasmacytoid cells occur in high-grade IDC and are not lobular on their own.

- **Re-run with the lumen rule: still ILC (0.72).** The model zoomed ten areas
  as told, including P075, but at its top (500, 400); the glands sit at its
  bottom (480, 860). The rule was right, but the model chose where to look,
  and it looked where its first impression pointed.
- **Tried and rejected: an automatic gland-lumen detector** (round empty
  spaces ringed by nuclei). On ILC tiles it found up to 160 "lumens" per tile
  (pale cytoplasm and intracytoplasmic vacuoles), against about 50 on the IDC
  tile, so it would have pushed ILC towards IDC. It was not shipped.
- **Changes:** `survey` — the tool, not the model, picks 12 places, one per
  region of the dense tissue across the whole slide (k-means over density
  cells, the densest cell of each region), and cuts full-resolution crops of
  them. The model must read all of them before settling the diagnosis. The
  pick favours no type.
- **Re-run with survey (d41c5d1): IDC (0.80), correct.** It cited the "focal
  true tubules (P075)" and kept pleomorphic ILC as the differential. The P075
  crop was the model's own zoom, not one of the 12 survey crops, so this run
  does not show that survey itself found the glands. Its session held nothing
  from earlier attempts (a fresh prompt, no project memory, no resume).

### Run #7 — TCGA-GM-A2D9, recorded Normal, answered IDC (0.45), before the rules

- **Tissue at 40×:** a frozen section (TCGA `-TS`), fragmented and crushed,
  with elongated cords of bland oval, streaming nuclei in sclerotic stroma,
  some with small lumens. These are compressed benign ducts, vessels and
  nerves, with no convincing atypia.
- **Why wrong:** crush and freezing artefact read as infiltration, and IDC
  given at 0.45. The calibration floor (0.65 → SUSP) added for #25 already
  forbids this answer.
- **Changes:** a note that cords of bland cells in crushed or frozen tissue
  are usually benign structures and need atypia on zoom.

### Runs #6, #8, #51, #53 — recorded Normal, answered NONDX (partial)

- **Tissue:** every TCGA "Normal" slide is a frozen section of non-tumour
  tissue (`-11A…-TS/BS`). These four hold fat, stroma, vessels, fibrin or
  mounting medium and **no ducts or lobules**. Morphologically, NONDX was
  defensible.
- **Cause:** a definitional mismatch. "Normal" in the archive means
  non-tumour tissue, not breast parenchyma seen. A standard sign-out for such
  tissue is "benign fibroadipose tissue, no epithelium sampled", not
  non-diagnostic.
- **Changes:** NORMAL for non-lesional tissue without epithelium, with a line
  5 note that no epithelium was sampled; NONDX only for tissue too damaged or
  scant to exclude a lesion. **This rule moves answers towards the scoring
  rule: judge it only on held-out normal slides.**
- **Confound to remember:** in this archive, Normal equals frozen section and
  tumour mostly equals FFPE `DX`. Preparation alone separates the classes.

### Run #11 — BRACS_1511, recorded PB, answered NORMAL (partial), before the rules

- **Tissue:** mostly fat; a narrow fibrous band holds a few lobules, one with
  crowded acini and mild hyperplasia, which the model itself flagged. The
  benign lesion is minor, and PB versus normal here is borderline. Tissue
  masking skips most of the fat (pale), which does not affect epithelial
  lesions.
- **Changes:** none. Not used for a rule.

### Technical failures

- **#10, #14 (BRACS_1338, BRACS_1370):** OpenSlide cannot open the files; they
  are truncated on Drive. They need a fresh download from the BRACS source; a
  re-run cannot fix this.
- **#17 (TCGA-OL-A5RY):** the slide declares no scale. Fixed in c0953a2 (scale
  estimated from nuclear size); #27 on the same slide was correct.
- **#48, #50:** voided by the integrity check for a refused command writing to
  `/dev/null`. This was a false positive, fixed in f44543c.

## Brain, lung, prostate batch (runs #56–#115, 2026-10-01)

53 correct, 4 partial and 3 wrong of 60 held-out slides; PRAD 20/20. Studied:

- **#91 LUSC → NSCLC (partial).** The model saw keratin-pearl-like whorls and
  still gave NSCLC. Rule: keratinisation, pearls or intercellular bridges
  make it LUSC; NSCLC only without any differentiation (WHO).
- **#74 LGG → GBM (partial).** Its "necrosis" (P361) is at 20× a cleft with
  degenerating gemistocytic cells, a tear rather than coagulative or
  palisading necrosis, and there is no MVP. Rule: grade 4 needs palisading
  necrosis or definite MVP on zoom; tears and haemorrhage do not count.
- **#62 GBM → PXA (wrong).** Bizarre giant and xanthomatous cells, but the
  model itself saw the tumour infiltrating the brain. Rule: PXA (rare, young)
  only for a circumscribed tumour with eosinophilic granular bodies; an
  infiltrating pleomorphic glioma is giant-cell GBM.
- **#73 LGG → GBM (partial), not changed.** P312 shows genuine glomeruloid
  microvascular proliferation, histologically grade 4. The slide and the
  label disagree; WHO 2021 grading also depends on molecular tests that H&E
  cannot give.
- **#59 GBM → LGG (partial), not changed.** A fragmented biopsy with no
  necrosis or MVP visible; the model said GBM is not excluded. H&E cannot do
  more.
- **#78 LUAD → PSC (wrong → partial by scoring).** At 20× there are fascicles of
  spindle and epithelioid cells with vacuolated cytoplasm, and no glands in the
  zoomed areas: a pleomorphic/sarcomatoid carcinoma, which WHO classifies
  apart from adenocarcinoma even with a glandular component. Scoring now
  counts PSC/PLEO on LUAD or LUSC as a related non-small cell carcinoma.
- **#83 LUAD → PLCH (wrong), label to review.** At 40× the zoomed areas are
  sheets of eosinophils, lymphocytes and histiocytoid cells with anthracotic
  pigment, with no glands or cohesive malignant epithelium. The model
  described the slide accurately. Either this diagnostic slide does not hold
  the tumour, or the tumour is a minor focus. It needs a pathologist's look,
  not a rule.

Technical: #62 read all 16 contact sheets; its "contact sheets failed to
display" remark is not supported by its session (59 images returned, no
error, no compaction). It finished 243 tiles in 4 minutes, and #83 opened 4
tiles in 110 s: thin readings, which survey now partly guards against.

## Re-runs

A re-run keeps its id (`php artisan v2:rerun <ids>`, or Re-run on the page).
The previous attempt's folder stays as `<id>.attemptN`, and its answer is
written into the stage log. #53 and #54 were opened as new runs before this
existed.

## Isolation: how the model is kept from cheating

The run folder sits inside the application, so an unscoped tool reaches .env,
the database credentials and every other run's answer. Since 562ba5e/837f263:

- The CLI rules allow Read and Glob on `./**`, Write and Edit on
  `./result.json`, and Bash only for `show`, `contours`, `zoom` and `validate`
  on `.`. Verified on the host: a read outside the folder, `cat` of a file
  outside it, and a write of any other file are refused.
- `v2_tools.py` refuses other folders and privileged subcommands when
  `V2_SANDBOX` is set. The run's copy is read-only, and its sha256 is checked
  before finalize executes it.
- Every tool call and every refusal is recorded in `tool_calls.jsonl`. A run
  is voided by any call that ran outside the limits, or by any attempt, even a
  refused one, that named something outside the folder. A refused call that
  stayed inside the folder had no effect and does not void the run.
- The case details the model receives are checked for the slide's
  identifiers, its archive and its recorded diagnosis, and the run is refused
  if any appear.
- Evaluation batches run blind: organ and stain only. BRACS slides have no age
  or race and TCGA slides do, so demographics alone would reveal the archive.
- Audit of runs #1–#32, from the CLI transcripts in
  `/var/lib/histo-claude/.claude/projects/`: no access outside a run folder.

## Still open

- Visual archive signature: BRACS and TCGA differ in stain colour, scanner and
  pen marks, and the classes are not balanced across archives. A batch that
  mixes archives within each class is the only defence.
- Moderate confidence on the corrected cases (0.55–0.70) is appropriate:
  their final call needs immunostains.
- The new rules have not yet been measured on held-out slides.
