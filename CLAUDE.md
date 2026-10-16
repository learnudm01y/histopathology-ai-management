# CLAUDE.md

## How to work with the owner

- Always answer the owner in Arabic, even when they write in English. Code,
  commit messages and code comments stay in English.

## Project

A Laravel platform for histopathology slides. The current diagnostic approach
is **V2 Diagnose** (`admin/v2-diagnose`): the whole slide is tiled, Claude Code
runs headless over the tiles and the clinical context, and returns coordinates
that the platform converts and draws on the live slide. Start from
`config/v2_diagnose.php`, `app/Services/V2DiagnoseRunner.php`,
`scripts/v2_tools.py`, `resources/prompts/v2_diagnose.md` and
`resources/prompts/V2_DIAGNOSE_LESSONS.md`.

## Ideas under discussion

- **Dental analysis (2026-10-16):** extend the platform to teeth: an image of
  the teeth goes in, the diseases on each tooth come out. Proposed input:
  dental radiographs (panoramic/OPG for the whole mouth, periapical and
  bitewing for detail), possibly with intraoral photos; oral and odontogenic
  biopsies would fit the existing V2 slide pipeline as they are. Not started.
