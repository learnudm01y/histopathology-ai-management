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
  - **Follow-up (2026-10-03):** the case record is adenocarcinoma NOS, stage IB,
    peripheral, with a consistent pathology review, so the label is right for
    the case. All 73 tiles plus nine 20× regions across both pieces show
    lymphoid aggregates, eosinophils, fibrosis, histiocytoid cells and native
    bronchioles, with scattered large epithelioid cells that H&E cannot place,
    and no gland, papilla or lepidic growth. The model's error was not the
    description but the leap to a rare entity at 0.55 without stains. Rules:
    inflammation can hide carcinoma (SUSP with keratin/TTF-1), and rare or
    non-neoplastic specific diagnoses need the same 0.65 floor as carcinoma.
    The right answer for this slide is SUSP, which scores partial: the slide
    cannot give more.
  - **Re-run with those rules: LCH (0.68)**, above the floor, with grooved
    nuclei, no nucleoli and eosinophils cited, and keratin/TTF-1 named to
    exclude a carcinoma. The patient was a smoker (heavy anthracosis), and
    pulmonary LCH is a smoking-related lesion that can lie next to a lung
    carcinoma, so this block most likely holds the LCH, not the tumour. The
    model reads the slide, and the label describes the case. The slide is
    listed in `label_review` (config): its runs show "label under review",
    counted neither right nor wrong, until a pathologist settles it. Pushing
    the model towards "adenocarcinoma" here would teach it to answer the
    label, not the tissue.

Technical: #62 read all 16 contact sheets; its "contact sheets failed to
display" remark is not supported by its session (59 images returned, no
error, no compaction). It finished 243 tiles in 4 minutes, and #83 opened 4
tiles in 110 s: thin readings, which survey now partly guards against.

## Re-runs

A re-run keeps its id (`php artisan v2:rerun <ids>`, or Re-run on the page).
The previous attempt's folder stays as `<id>.attemptN`, and its answer is
written into the stage log. #53 and #54 were opened as new runs before this
existed.

## Speed (2026-11-25)

Measured on 4 slides (42–444 tiles), each analysed by several variants of
the instructions, in a harness that used the runner's own CLI flags.

- The model's time is spent generating text (about 100 tokens a second), not
  waiting between steps. Writing result.json took 30–50% of an analysis,
  because `patches` listed every tile as JSON and every region carried a
  polygon. The compact `tiles` form, with the polygon optional, plus batched
  reads cut the analysis from 132–439 s to 82–163 s. Every diagnosis stayed
  correct.
- **Never tell the model the run is timed.** With that sentence it opened
  fewer tiles, and on the 444-tile prostate it marked tumour tiles as normal
  without opening them (Gleason 3+3 against 3+4). The Pace section says that
  batching changes the speed of the reading, never its amount.
- **`--effort low` is rejected.** It skipped views and survey crops and
  called a GBM an LGG.
- **Parallel sheet-reader subagents are rejected.** They were no faster on
  the large slide, because merging their reports cost more than reading.
- **The baseline is not reproducible on large prostate slides.** Two runs of
  the same 444-tile slide with the same instructions gave 30 and 10 tumour
  tiles (IoU 0.21), and Grade Group 2 and 1. Compare any change against this
  spread, not against a single run.
- **Tiling.** Tiles are now stored at the view scale, read from the pyramid
  level nearest it, and zoom/survey read full resolution from the slide.
  This is 7–11x faster on 40x slides. View images differ from the old ones
  by about 2–6 grey levels, and the density grids correlate at 0.99.

## The written answer against the pathology reports (2026-11-25)

Until now only `diagnosis_code` was scored, against the slide's recorded
class. The text — grade, in-situ component, lymphovascular invasion — was
never checked. `php artisan v2:written-check` now compares it, field by field,
with the case's pathology report: the TCGA reports (OCR text, Kefeli et al.
2024) and the GDC record, read into `resources/reference/v2_written_reports.json`
by `scripts/v2_report_reference.py`. Every reference value carries the
sentence it was read from. The extraction was audited by hand on the 88
evaluated patients; the other ~600 breast patients are extracted the same way
but not audited.

- **The report describes the case; a run reads one slide.** A feature the
  report found and the slide does not show is "not on this slide", not an
  error. Normal-tissue blocks (TCGA sample type 11, "-11A") are not compared at
  all: the report describes the tumour, not that block. The breast runs
  #5–#9 and #48–#54 are on such blocks, and were rightly read as benign.
- **First measurement, runs #1–#129, 93 tumour slides with a report:**
  type 83 agree, 6 close, 4 disagree. Grade 49 agree, 10 close (a written
  range that includes the report's grade), 20 one step off, 4 further off,
  4 not stated. In situ (breast) 17 agree, 3 written as possible, 4 not on the
  slide, none written present when the report has none. LVI was not stated in
  29 of 44: the instructions never asked for it.
- **Prostate**: all three Gleason 3+3 cases were written 3+4, and pattern 5
  was missed in three (4+5 → 4+3 twice, 5+3 → 3+3). The view images are about
  10×, where tangentially cut pattern-3 glands look poorly formed. Rule: every
  reported pattern must be seen on a zoom image, small separate glands with
  lumens are pattern 3, and pattern 5 is looked for in the most cellular areas.
  The tissue was not re-read at 40× for these cases: the rule is the ISUP
  definition, guards both directions, and must be proved on other slides.
  The report's Gleason covers the whole gland, so one slide can differ.
- **Glioma**: two anaplastic oligodendrogliomas were written grade 2
  "favoured" with mitoses not counted; two anaplastic astrocytomas were
  written grade 4 on microvascular proliferation; two GBMs grade 3. Rule:
  count mitoses on at least five zooms of the most cellular areas and write
  the count before choosing grade 2 or 3.
- **Lung**: the answers used IASLC grades, the reports differentiation
  (well / moderately / poorly), so the two could not be compared; two
  well-differentiated squamous carcinomas were written moderately. Rule: write
  the differentiation as a report does, plus the IASLC grade for adenocarcinoma.
- **Breast**: grades were often hedged ("high grade", "grade 2–3"). Rule: score
  the three Nottingham components and write one grade, saying which component
  was estimated.
- **Format**: `report` in result.json (grade, grade_basis, gleason, in_situ,
  lvi) is required by the validator, kept in final.json and view.json, and is
  what `v2:written-check` reads first. Line 4 of the summary states the same.
- **Not yet proved.** Every prostate, brain and lung slide in the archive had
  already been run, so the new rules for them can only be re-run on their own
  tuning slides. Unseen slides exist only for breast:
  `php artisan v2:evaluate --queue --written --classes=IDC,ILC --per-class=N`.

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
