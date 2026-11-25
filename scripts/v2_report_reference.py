#!/usr/bin/env python3
"""
Reference fields from the written pathology reports, for checking what the V2
model writes about a slide against what the pathologist wrote about the case.

Inputs
  --reports  TCGA_Reports.csv (Kefeli et al. 2024, "TCGA-Reports: a machine-
             readable pathology report resource"; OCR text of the TCGA
             pathology report PDFs, one row per patient, columns
             patient_filename, text) — public, not kept in this repository.
  --cases    JSON list exported from the archive: one entry per patient with
             its TCGA project and the GDC diagnosis fields (primary_diagnosis,
             morphology, tumor_grade, primary/secondary Gleason).
Output
  --out      JSON keyed by patient barcode (TCGA-XX-XXXX). Every field holds
             the value and the sentence of the report it was read from, so a
             reader can see the evidence next to the comparison.

Fields, chosen because a single H&E slide can show them:
  type      the histological type (from the GDC record, reviewed for TCGA)
  grade     breast: Nottingham 1-3; lung: differentiation 1-3 (well, moderate,
            poor); brain: CNS WHO grade 2-4; prostate: Grade Group 1-5
  gleason   prostate: primary + secondary pattern (from the GDC record)
  in_situ   breast: carcinoma in situ present or absent
  lvi       lymphovascular invasion present or absent

A value the report does not state is left out, never guessed. Where a report
gives two values (two tumours, "moderately to poorly differentiated") the
field holds both.

    python3 scripts/v2_report_reference.py --reports TCGA_Reports.csv \
        --cases cases.json --out resources/reference/v2_written_reports.json
"""
import argparse
import csv
import json
import re
import sys

csv.field_size_limit(10 ** 8)

ROMAN = {'i': 1, 'ii': 2, 'iii': 3, 'iv': 4, 'l': 1, 'il': 2, 'll': 2, 'li': 2, 'lll': 3, 'iil': 3, 'ill': 3}


def num(tok):
    """1-4 from a grade token: digits, Roman numerals, or OCR'd Roman (Il, lll)."""
    t = tok.strip().lower().rstrip('.')
    if t.isdigit():
        return int(t)
    return ROMAN.get(t)


def sentences(text):
    """Report text split into short clauses: OCR lines end in '. ', synoptic items start with '- '."""
    parts = re.split(r'(?<=[.;])\s+|\s+-\s+|\n', text)
    return [p.strip() for p in parts if p.strip()]


def first(text, limit=220):
    t = re.sub(r'\s+', ' ', text).strip()
    return t if len(t) <= limit else t[:limit].rsplit(' ', 1)[0] + '…'


# ── Breast and lung grade ──────────────────────────────────────────────────

DIFF = [(r'\bwell[\s-]+to[\s-]+moderately[\s-]+differentiated\b', (1, 2)),
        (r'\bmoderately[\s-]+to[\s-]+poorly[\s-]+differentiated\b', (2, 3)),
        (r'\bwell[\s-]+differentiated\b', (1,)), (r'\bmoderately[\s-]+differentiated\b', (2,)),
        (r'\bpoorly[\s-]+differentiated\b', (3,)), (r'\bundifferentiated\b', (3,))]
IN_SITU_CLAUSE = re.compile(r'in[\s-]*situ|\bDCIS\b|\bLCIS\b|intraductal|\bPIN\b|comedo|fuhrman', re.I)
# A nuclear grade (of DCIS, or Black's nuclear grade) is not the histological
# grade, and "(1, 2, 3)" is the scale, not a value: both are cut out first.
NOISE = re.compile(r"(?:modified\s+)?(?:black.s\s+)?nuclear\s+(?:grade|score|pleomorphism(?:\s+score)?)\s*[:=]?\s*(?:\d|I{1,3}|NA)\b(?:\s*/\s*3)?"
                   r"|\(\s*1\s*,\s*2\s*,\s*3\s*\)|\(\s*1\s*-\s*3\s*\)|cassettes?[^.;]*|section code[^.;]*", re.I)
SCORE9 = re.compile(r'(?:total|nottingham|combined|overall|sbr|histologic)[^.;]{0,40}?score[^0-9]{0,12}([3-9])\s*(?:/|of|out of)\s*9', re.I)
GRADE = re.compile(r'\bgrade\b\s*(?:is|=|:|-)?\s*\(?\s*(I{1,3}|Il|ll|lll|[123])\b(?!\s*(?:/|of)\s*(?:4|IV)\b)(?:\s*(?:/|of)\s*(?:3|III))?', re.I)
GRADE_PAIR = re.compile(r'\bgrade\b\s*(?:is|:)?\s*(I{1,3}|[123])\s*(?:and|-|–|to)\s*(I{1,3}|[123])\b', re.I)
GRADE_OF4 = re.compile(r'\bgrade\s*([1-4]|I{1,3}|IV)\s*\(?\s*of\s*(?:4|IV)\)?', re.I)
G_CODE = re.compile(r'\bG([123])\b')
# Nottingham components without a stated total: "Tubules 3, nuclei 2, mitoses 1".
COMP = [re.compile(p, re.I) for p in (r'(?:tubul\w*|glandular|architectur\w*)[^0-9.]{0,45}?\b([123])\b',
                                       r'nucle\w*[^0-9.]{0,35}?\b([123])\b', r'mito\w*[^0-9.]{0,45}?\b([123])\b')]


def nottingham(score):
    return 1 if score <= 5 else 2 if score <= 7 else 3


def ocr_repair(text):
    # Line breaks the OCR turned into full stops inside a phrase: "WELL. DIFFERENTIATED".
    return re.sub(r'\b(well|moderately|poorly)\.\s+(differentiated)', r'\1 \2', text, flags=re.I)


def components(text):
    """The first place after a grading-system name where all three scores are given."""
    for m in re.finditer(r'(nottingham|bloom|richardson|elston|sbr|scarff)', text, re.I):
        window = text[m.start(): m.start() + 420]
        found = [p.search(window) for p in COMP]
        if all(found):
            vals = [int(c.group(1)) for c in found]
            total = sum(vals)
            return (nottingham(total), f'components {vals[0]}+{vals[1]}+{vals[2]}={total}: ' + first(window, 160))
    return None


def breast_or_lung_grade(text, lung=False):
    """Histological grade of the invasive tumour, with the clause it came from."""
    text = ocr_repair(text)
    explicit, scored, diff = [], [], []
    for raw in sentences(text):
        in_situ = IN_SITU_CLAUSE.search(raw) and not re.search(r'invasive|infiltrat', raw, re.I)
        s = NOISE.sub(' ', raw)
        if not in_situ:
            if lung:
                m = GRADE_OF4.search(s)          # "Grade 3 (of 4) adenocarcinoma": a four-tier scale
                if m:
                    g = num(m.group(1))
                    if g:
                        explicit.append((min(3, g), raw))
                        continue
            pairs = list(GRADE_PAIR.finditer(s))
            for m in pairs:
                a, b = num(m.group(1)), num(m.group(2))
                if a and b and a <= 3 and b <= 3:
                    explicit += [(a, raw), (b, raw)]
            if not pairs:
                for m in GRADE.finditer(s):
                    g = num(m.group(1))
                    if g and g <= 3:
                        explicit.append((g, raw))
            if re.search(r'\bgrade\b', s, re.I) or re.search(r'carcinoma[;,]\s*G[123]\b', s, re.I):
                for m in G_CODE.finditer(s):
                    explicit.append((int(m.group(1)), raw))
            for m in SCORE9.finditer(s):
                scored.append((nottingham(int(m.group(1))), raw))
            for pat, gs in DIFF:
                if re.search(pat, s, re.I):
                    diff += [(g, raw) for g in gs]
                    break
    if not explicit and not scored:
        c = components(text)
        if c:
            scored.append(c)
    for found in (explicit, scored, diff):
        if found:
            vals = sorted({g for g, _ in found})
            return {'value': vals if len(vals) > 1 else vals[0], 'text': first(found[0][1])}
    return None


# ── Presence features ──────────────────────────────────────────────────────

NEG = re.compile(r'\bnot\s+(?:definitely\s+)?(?:identified|present|seen|detected|evident|noted|observed|found|appreciated)\b'
                 r'|\babsent\b|\bnone\b|\bnegative\b|\bno\b|\bwithout\b|\bfree\b|\(-\)|\bnil\b', re.I)
POS = re.compile(r'\b(?:present|identified|seen|noted|positive|yes|suggested|suspicious|permeation|focal|extensive)\b|\(\+\)', re.I)
UNSTATED = re.compile(r'^\W{0,3}(?:not\s+(?:specified|applicable|assessed|reported|evaluated)|n/?a\b|cannot be (?:assessed|determined|evaluated)'
                      r'|indeterminate|unknown|equivocal)', re.I)
NEG_BEFORE = re.compile(r'\b(?:no|without|negative for|free of|absence of)\b[^.;:]{0,25}$', re.I)
POS_BEFORE = re.compile(r'\b(?:there is|there are|with|shows?|demonstrat\w*|presence of|evidence of|focal|foci of|areas of|present in'
                        r'|positive for|component of|consistent with|associated with|involv\w* by|admixed with|accompanied by'
                        r'|within)\b[^.;:]{0,25}$', re.I)
# A clause that opens with the feature is a diagnosis line: "- Lobular carcinoma in situ (LCIS)."
LINE_START = re.compile(r'^[\s\W]*(?:[A-Z]\.|\d+[.)]|[a-z]\))?[\s\W]*$')


def presence(text, subject, skip=None):
    """
    present / absent for one feature, read clause by clause. Within a clause
    the first word after the feature decides ("present, but no tumour in the
    dermis" is present); a form field left empty, or "not specified", says
    nothing. An affirming clause anywhere wins over a negating one.
    """
    pos, neg = [], []
    for s in sentences(text):
        if skip and skip.search(s):
            continue
        for m in subject.finditer(s):
            before, after = s[:m.start()], s[m.end():m.end() + 70]
            after = re.sub(r'^\s*\([^)]{0,14}\)', '', after)       # "(LCIS)", "(DCIS)": the name again
            if UNSTATED.search(after.lstrip(' :.-')):
                continue
            if NEG_BEFORE.search(before):
                neg.append(s)
                continue
            n, p = NEG.search(after), POS.search(after)
            if n and (not p or n.start() <= p.start()):
                neg.append(s)
            elif p:
                pos.append(s)
            elif POS_BEFORE.search(before):
                pos.append(s)
            elif LINE_START.match(before) and not after.lstrip().startswith(':'):
                pos.append(s)
            # otherwise a label with no value: nothing is said
    if pos:
        return {'value': 'present', 'text': first(pos[0])}
    if neg:
        return {'value': 'absent', 'text': first(neg[0])}
    return None


IN_SITU = re.compile(r'ductal carcinoma[\s-]*in[\s-]*situ|\bDCIS\b|intraductal carcinoma|lobular carcinoma[\s-]*in[\s-]*situ'
                     r'|\bLCIS\b|in[\s-]*situ component|carcinoma in[\s-]*situ', re.I)
IN_SITU_SKIP = re.compile(r'margin|nipple|extensive intraductal|\bEIC\b|distance', re.I)
LVI = re.compile(r'lympho[\s-]*vascular|angio[\s-]*lymphatic|lymphatic(?:\s*/\s*vascular)?\s+invasion|vascular(?:\s*/\s*lymphatic)?\s+(?:space\s+)?invasion'
                 r'|venous\s*/\s*lymphatic|\bLVI\b|vessel invasion|lymphatic/vascular', re.I)
LVI_SKIP = re.compile(r'perineural', re.I)


def lvi_from_tnm(text):
    m = re.search(r'\bL([01])\b[^.]{0,20}\bV([012])\b', text)
    if m:
        return {'value': 'present' if m.group(1) == '1' or m.group(2) != '0' else 'absent', 'text': first(m.group(0))}
    return None


# ── Types from the GDC record ──────────────────────────────────────────────

BREAST_TYPES = {'8500': 'IDC', '8520': 'ILC', '8522': 'MIXED', '8575': 'MBC', '8480': 'MUC', '8211': 'TUB',
                '8510': 'MED', '8503': 'PAP', '8507': 'MPC', '8310': 'CLEAR', '8524': 'MIXED', '8523': 'MIXED'}


def type_of(case):
    pd = (case.get('primary_diagnosis') or '').strip()
    morph = (case.get('morphology') or '').split('/')[0]
    proj = case.get('project') or ''
    code = None
    if proj == 'TCGA-BRCA':
        code = BREAST_TYPES.get(morph)
    elif proj in ('TCGA-LUAD', 'TCGA-LUSC'):
        code = 'LUSC' if re.search(r'squamous', pd, re.I) else 'LUAD' if re.search(r'adenocarcinoma|acinar|papillary|mucinous|bronchiolo', pd, re.I) else None
    elif proj in ('TCGA-GBM', 'TCGA-LGG'):
        code = ('GBM' if re.search(r'glioblastoma', pd, re.I) else 'OLIGO' if re.search(r'^oligodendroglioma', pd, re.I)
                else 'OA' if re.search(r'mixed glioma|oligoastrocytoma', pd, re.I) else 'ASTRO' if re.search(r'astrocytoma', pd, re.I) else None)
    elif proj == 'TCGA-PRAD':
        code = 'PRAD'
    return {'value': code, 'text': pd + (f' ({case.get("morphology")})' if case.get('morphology') else ''), 'source': 'GDC'} if code and pd else None


def gleason_of(case):
    p = re.search(r'(\d)', str(case.get('primary_gleason') or ''))
    s = re.search(r'(\d)', str(case.get('secondary_gleason') or ''))
    if not (p and s):
        return None
    a, b = int(p.group(1)), int(s.group(1))
    total = a + b
    group = 1 if total <= 6 else (2 if a == 3 else 3) if total == 7 else 4 if total == 8 else 5
    return {'primary': a, 'secondary': b, 'score': total, 'group': group,
            'text': f'Gleason {a}+{b}={total} (GDC record, consistent pathology review)', 'source': 'GDC'}


def who_grade(case, text):
    pd = (case.get('primary_diagnosis') or '').lower()
    if 'glioblastoma' in pd:
        return {'value': 4, 'text': case.get('primary_diagnosis'), 'source': 'GDC'}
    tg = re.search(r'G([234])', str(case.get('tumor_grade') or ''))
    if tg:
        return {'value': int(tg.group(1)), 'text': f'tumor_grade {case.get("tumor_grade")} ({case.get("primary_diagnosis")})', 'source': 'GDC'}
    m = re.search(r'WHO[^.]{0,20}grade\s*(I{1,3}V?|IV|[234])|grade\s*(I{1,3}V?|IV|[234])[^.]{0,12}WHO', text or '', re.I)
    if m:
        g = num(m.group(1) or m.group(2))
        if g:
            return {'value': g, 'text': first(m.group(0)), 'source': 'report'}
    if 'anaplastic' in pd:
        return {'value': 3, 'text': case.get('primary_diagnosis'), 'source': 'GDC'}
    return None


def reference(case, text):
    proj = case.get('project') or ''
    ref = {'project': proj}
    t = type_of(case)
    if t:
        ref['type'] = t
    if proj == 'TCGA-BRCA' and text:
        g = breast_or_lung_grade(text)
        if g:
            ref['grade'] = g | {'system': 'Nottingham', 'source': 'report'}
        s = presence(text, IN_SITU, IN_SITU_SKIP)
        if s:
            ref['in_situ'] = s | {'source': 'report'}
    elif proj in ('TCGA-LUAD', 'TCGA-LUSC') and text:
        g = breast_or_lung_grade(text, lung=True)
        if g:
            ref['grade'] = g | {'system': 'differentiation (1 well, 2 moderate, 3 poor)', 'source': 'report'}
    elif proj in ('TCGA-GBM', 'TCGA-LGG'):
        g = who_grade(case, text)
        if g:
            ref['grade'] = g | {'system': 'CNS WHO'}
    elif proj == 'TCGA-PRAD':
        gl = gleason_of(case)
        if gl:
            ref['gleason'] = gl
            ref['grade'] = {'value': gl['group'], 'text': gl['text'], 'system': 'Grade Group', 'source': 'GDC'}
    if text and proj in ('TCGA-BRCA', 'TCGA-LUAD', 'TCGA-LUSC', 'TCGA-PRAD'):
        lv = presence(text, LVI, LVI_SKIP) or lvi_from_tnm(text)
        if lv:
            ref['lvi'] = lv | {'source': 'report'}
    if text:
        ref['report_chars'] = len(text)
    return ref


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--reports', required=True)
    ap.add_argument('--cases', required=True)
    ap.add_argument('--out', required=True)
    a = ap.parse_args()

    texts = {}
    with open(a.reports, encoding='utf-8') as f:
        for row in csv.DictReader(f):
            texts.setdefault(row['patient_filename'][:12], row['text'])
    cases = json.load(open(a.cases, encoding='utf-8'))
    out = {}
    for c in cases:
        pid = c['patient']
        ref = reference(c, texts.get(pid))
        if len(ref) > 1:
            out[pid] = ref
    json.dump(out, open(a.out, 'w', encoding='utf-8'), ensure_ascii=False, indent=1, sort_keys=True)
    with_report = sum(1 for r in out.values() if 'report_chars' in r)
    print(json.dumps({'patients': len(out), 'with_report_text': with_report}), file=sys.stderr)


if __name__ == '__main__':
    main()
