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
