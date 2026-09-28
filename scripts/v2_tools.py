#!/usr/bin/env python3
"""
V2 Diagnose — the deterministic half of the pipeline.

The model reads the tiles and answers in coordinates; everything around that is
here, so that nothing numeric depends on a language model doing arithmetic:

    tile     RUN SLIDE        the whole slide, every tile with tissue; measures coverage
    prepare  RUN              view images, contact sheets, nuclear-density grids, manifest
    density  RUN              rebuild density.json and masks/ for an existing run
    show     RUN P007         print one tile's density grid (0-9) — for the model
    contours RUN P007 [--level 0.55]
                              density iso-contours of one tile in view px — for the model
    validate RUN              check RUN/result.json against the contract; exit 1 on errors
    finalize RUN [--sam-ckpt ...]
                              validate, (optionally) refine with SAM, convert to level-0
                              slide pixels, compose the heatmap -> RUN/final.json

Coordinate spaces
    view px   : the 1000x1000 image the model is shown (RUN/view/P007.jpg), origin top-left
    tile px   : the full-resolution tile (e.g. 1904x1904)
    level-0   : SVS level-0 pixels. x_l0 = tile.x + view_x * tile.size_l0 / view_px

Dependencies: numpy, opencv-python-headless, Pillow (the same as patch_extract.py).
Every command prints one JSON line last on stdout.
"""

from __future__ import annotations

import argparse
import csv
import json
import math
import os
import re
import sys

import cv2
import numpy as np
from PIL import Image

VIEW_PX = 1000
GRID = 25
TUMOUR_LABELS = {"tumour", "in_situ", "lymphovascular_invasion"}
REGION_LABELS = TUMOUR_LABELS | {"necrosis", "lymphoid", "normal", "other"}
TISSUE_KINDS = {"tumour", "stroma", "fat", "lymphoid", "necrosis", "normal", "mixed", "other", "background"}
MAX_SUMMARY_LINES = 5
CODE_RE = re.compile(r"^[A-Za-z][A-Za-z0-9-]{1,11}$")
ARABIC_RE = re.compile(r"[؀-ۿݐ-ݿﭐ-﷿ﹰ-﻿]")
MAX_BOX_FRACTION = 0.85   # a region is lesional tissue, not the tile it sits on
MASK_PX = 250             # fine density map per tile, 4 view px per cell (~3.8 um)
HEAT_GRID = 50            # heat cells per tile side when built slide-wide (~19 um)
MASK_LEVEL = 0.30         # default cut on the fine map for a tumour mask (chosen on TCGA-AN-A046)
NEG_DROP_MAX_PX = 60_000  # a negative point removes a dense patch whole up to ~6% of the tile ...
NEG_CARVE_PX = 32         # ... and only a ~30 um hole in anything larger,
NEG_FLOOD_PX = 160        # plus whatever is continuous with it within ~150 um
NEG_FLOOD_TOL = 0.12      # and within this much of its density


def out(obj: dict, code: int = 0) -> None:
    print(json.dumps(obj, ensure_ascii=False))
    sys.exit(code)


def load(path: str):
    with open(path, encoding="utf-8") as fh:
        return json.load(fh)


def save(path: str, obj) -> None:
    tmp = path + ".tmp"
    with open(tmp, "w", encoding="utf-8") as fh:
        json.dump(obj, fh, ensure_ascii=False, separators=(",", ":"))
    os.replace(tmp, path)


# ═════════════════════════════════════════════════════════════════════════════
# Nuclear density (lymphocyte-suppressed) — the measurement behind the heatmap
# ═════════════════════════════════════════════════════════════════════════════
# Same method as the reference svs_heatmap_smooth.py, per tile and without
# scikit-image: hematoxylin by colour deconvolution, nuclei by Otsu, small round
# dark nuclei treated as lymphocytes and suppressed.

_RGB_FROM_HED = np.array([[0.65, 0.70, 0.29], [0.07, 0.99, 0.11], [0.27, 0.57, 0.78]])
_HED_FROM_RGB = np.linalg.inv(_RGB_FROM_HED)


def hematoxylin(rgb: np.ndarray) -> np.ndarray:
    """skimage.color.rgb2hed(...)[..., 0], reimplemented."""
    x = np.maximum(rgb.astype(np.float32) / 255.0, 1e-6)
    stains = (np.log(x) / np.log(1e-6)) @ _HED_FROM_RGB.astype(np.float32)
    return np.maximum(stains[..., 0], 0)


def tissue_mask(rgb: np.ndarray) -> np.ndarray:
    hsv = cv2.cvtColor(rgb, cv2.COLOR_RGB2HSV)
    gray = cv2.cvtColor(rgb, cv2.COLOR_RGB2GRAY)
    return (hsv[..., 1] > 28) & (gray < 228)


def otsu(values: np.ndarray) -> float:
    if values.size == 0:
        return 1.0
    # A handful of extreme pixels (a black tick mark, a pen line, a JPEG fringe)
    # would otherwise stretch the histogram until all real tissue sits in one bin.
    hist, edges = np.histogram(values, bins=256, range=(float(values.min()), float(np.percentile(values, 99.5))))
    centers = (edges[:-1] + edges[1:]) / 2
    w0 = np.cumsum(hist).astype(np.float64)
    w1 = w0[-1] - w0
    m = np.cumsum(hist * centers)
    mu0 = m / np.maximum(w0, 1)
    mu1 = (m[-1] - m) / np.maximum(w1, 1)
    between = w0 * w1 * (mu0 - mu1) ** 2
    return float(centers[int(np.argmax(between[:-1]))])


def density_raw(rgb: np.ndarray, thr: float, mpp: float) -> tuple[np.ndarray, np.ndarray]:
    """Return (value, tissue) on a GRID x GRID lattice, value not yet normalised."""
    tis = tissue_mask(rgb)
    H = hematoxylin(rgb)
    nuc = ((H > thr) & tis).astype(np.uint8)
    nuc = cv2.morphologyEx(nuc, cv2.MORPH_OPEN, np.ones((2, 2), np.uint8))

    n, lab, stats, _ = cv2.connectedComponentsWithStats(nuc, connectivity=8)
    area = stats[:, cv2.CC_STAT_AREA].astype(np.float64)
    bw = stats[:, cv2.CC_STAT_WIDTH].astype(np.float64)
    bh = stats[:, cv2.CC_STAT_HEIGHT].astype(np.float64)
    aspect = np.maximum(bw, bh) / np.maximum(np.minimum(bw, bh), 1)
    mean_h = np.bincount(lab.ravel(), weights=H.ravel(), minlength=n) / np.maximum(area, 1)
    vals = H[nuc > 0]
    p70 = float(np.percentile(vals, 70)) if vals.size else 1.0

    nuc_min = 12.0 / mpp ** 2      # ~12 um^2: smaller is debris
    lym_max = 40.0 / mpp ** 2      # ~40 um^2: small, round, dark = lymphocyte
    ok = area >= nuc_min
    lym_c = ok & (area < lym_max) & (aspect <= 1.6) & (mean_h > p70)
    big_c = ok & ~lym_c
    lym_c[0] = big_c[0] = False    # label 0 is background

    big = big_c[lab].astype(np.float32)
    lym = lym_c[lab].astype(np.float32)
    tisf = tis.astype(np.float32)

    def smoothed(px: int, sigma_um: float) -> tuple[np.ndarray, np.ndarray]:
        um_per = rgb.shape[0] * mpp / px
        sig = max(0.5, sigma_um / um_per)
        a_big, a_lym, a_tis = (cv2.resize(m, (px, px), interpolation=cv2.INTER_AREA) for m in (big, lym, tisf))
        t_s = np.maximum(cv2.GaussianBlur(a_tis, (0, 0), sig), 0.7)
        d_big = cv2.GaussianBlur(a_big, (0, 0), sig) / t_s
        d_lym = cv2.GaussianBlur(a_lym, (0, 0), sig) / t_s
        return d_big * np.clip(1 - (d_lym - 0.06) / 0.10, 0, 1), a_tis

    # The heat grid: ~10 um cells smoothed over ~25 um, then down to 25 x 25.
    v, a_tis = smoothed(100, 25.0)
    v = cv2.resize(v, (GRID, GRID), interpolation=cv2.INTER_AREA)
    t = cv2.resize(a_tis, (GRID, GRID), interpolation=cv2.INTER_AREA)
    # For the mask: the raw fractions at ~4 um cells, NOT smoothed here. They
    # are stitched into one slide-wide map before any blur, so no tile border
    # ever shows in the tumour outline (see slide_mask).
    fine = np.stack([cv2.resize(m, (MASK_PX, MASK_PX), interpolation=cv2.INTER_AREA)
                     for m in (big, lym, tisf)], axis=-1)
    return v, t, fine


# ═════════════════════════════════════════════════════════════════════════════
# prepare
# ═════════════════════════════════════════════════════════════════════════════

def draw_ticks(img: np.ndarray) -> None:
    """Short marks every 100 view px on all four edges, so positions can be read off."""
    s = img.shape[0]
    for p in range(100, s, 100):
        for (a, b) in (((p, 0), (p, 12)), ((p, s - 13), (p, s - 1)), ((0, p), (12, p)), ((s - 13, p), (s - 1, p))):
            cv2.line(img, a, b, (255, 255, 255), 4)
            cv2.line(img, a, b, (0, 0, 0), 2)


def write_density(run: str, smalls, thr: float, mpp_work: float) -> dict:
    """density.json (25x25 heat grids, normalised across the slide) and
    masks/raw_P001.png (the unsmoothed large-nucleus, lymphocyte and tissue
    fractions at MASK_PX, as the R, G and B channels) for the slide-wide mask.

    *smalls* yields (tile id, analysis image) one at a time: a whole slide is
    several hundred tiles, and only each tile's small grids are kept, never
    its pixels. Returns each tile's tissue fraction."""
    os.makedirs(os.path.join(run, "masks"), exist_ok=True)
    raw = {}
    for pid, small in smalls:
        v, t, fine = density_raw(small, thr, mpp_work)
        Image.fromarray(np.round(np.clip(fine, 0, 1) * 255).astype(np.uint8)).save(
            os.path.join(run, "masks", f"raw_{pid}.png"))
        raw[pid] = (v, t)
    vals = np.concatenate([v[t > 0.3] for v, t in raw.values()] or [np.zeros(1)])
    vmax = max(float(np.percentile(vals, 99)) if vals.size else 1.0, 1e-4)
    save(os.path.join(run, "density.json"), {
        "grid": GRID, "method": "lymphocyte-suppressed large-nucleus density, normalised to slide p99",
        "hematoxylin_threshold": round(thr, 4),
        "tiles": {pid: np.round((np.clip(v / vmax, 0, 1) * (t > 0.3)).astype(np.float64), 3).tolist()
                  for pid, (v, t) in raw.items()},
    })
    return {pid: round(float(t.mean()), 3) for pid, (_, t) in raw.items()}


# ═════════════════════════════════════════════════════════════════════════════
# tile — the whole slide, every tile with tissue in it
# ═════════════════════════════════════════════════════════════════════════════
# V2 does not use patch_extract.py. That script samples patches for training:
# it keeps tiles that are at least half tissue, drops mostly-white ones, caps
# the count at random, and never reaches the right and bottom edge strips. For
# a diagnosis, each of those silently removes tissue from the reading — on the
# first production runs only 22-31% of the tissue on large slides was read.
# Here the lattice covers the slide to its last pixel, a tile is kept if it
# holds any tissue at all, nothing is sampled, and the coverage is measured.

TILE_MASK_MPP = 8.0        # tissue mask resolution, um per pixel
MIN_TISSUE = 0.01          # a tile is read if at least 1% of it is tissue

_slide = None


def _open_slide(path: str) -> None:
    global _slide
    import openslide
    _slide = openslide.OpenSlide(path)


def _read_tile(job: tuple) -> str:
    """Read one tile at the analysis scale and write it as JPEG (worker)."""
    x0, y0, size_l0, level, patch_px, out_path = job
    ds = _slide.level_downsamples[level]
    n = int(math.ceil(size_l0 / ds))
    region = _slide.read_region((x0, y0), level, (n, n))      # beyond the slide: transparent
    tile = Image.new("RGB", region.size, (255, 255, 255))
    tile.paste(region, mask=region.split()[3])
    tile = tile.resize((patch_px, patch_px), Image.LANCZOS)
    tile.save(out_path, "JPEG", quality=90)
    return out_path


def slide_tissue_mask(slide, mpp0: float) -> tuple[np.ndarray, float, np.ndarray]:
    """Tissue mask of the whole slide at ~8 um/px, with its level-0 px per mask px
    and the thumbnail it was cut from. Saturation with a per-slide Otsu cut,
    as patch_extract does, so both pipelines agree on what tissue is."""
    W0, H0 = slide.dimensions
    k = max(1.0, TILE_MASK_MPP / mpp0)
    thumb = np.array(slide.get_thumbnail((int(W0 / k), int(H0 / k))).convert("RGB"))
    ds = W0 / thumb.shape[1]
    sat = cv2.medianBlur(cv2.cvtColor(thumb, cv2.COLOR_RGB2HSV)[..., 1], 7)
    t, m = cv2.threshold(sat, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
    if t < 5 or t > 200:
        _, m = cv2.threshold(sat, 20, 255, cv2.THRESH_BINARY)
    m = cv2.morphologyEx(m, cv2.MORPH_CLOSE, cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (15, 15)))
    cs, _ = cv2.findContours(m, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
    clean = np.zeros_like(m)
    for c in cs:
        if cv2.contourArea(c) >= 500:
            cv2.drawContours(clean, [c], -1, 255, cv2.FILLED)
    return clean > 0, ds, thumb


def cmd_tile(a) -> None:
    import openslide
    from multiprocessing import Pool

    run, pdir = a.run, os.path.join(a.run, "patches")
    os.makedirs(pdir, exist_ok=True)
    slide = openslide.OpenSlide(a.slide)
    W0, H0 = slide.dimensions
    mpp0 = None
    for key in (openslide.PROPERTY_NAME_MPP_X, "aperio.MPP"):
        try:
            mpp0 = float(slide.properties.get(key) or 0) or None
        except ValueError:
            mpp0 = None
        if mpp0:
            break
    if not mpp0:
        out({"error": "The slide declares no microns-per-pixel, so it cannot be tiled at a known scale."}, 1)

    scale = a.mpp / mpp0                              # level-0 px per output px
    size_l0 = int(round(a.patch * scale))
    level = 0
    for i, d in enumerate(slide.level_downsamples):   # deepest level that needs no upsampling
        if d <= scale + 1e-6:
            level = i

    mask, ds, thumb = slide_tissue_mask(slide, mpp0)
    cols, rows = math.ceil(W0 / size_l0), math.ceil(H0 / size_l0)
    covered = np.zeros_like(mask)
    jobs, coords = [], []
    for r in range(rows):
        for c in range(cols):
            x0, y0 = c * size_l0, r * size_l0
            my0, my1 = int(y0 / ds), min(mask.shape[0], int(math.ceil((y0 + size_l0) / ds)))
            mx0, mx1 = int(x0 / ds), min(mask.shape[1], int(math.ceil((x0 + size_l0) / ds)))
            cell = mask[my0:my1, mx0:mx1]
            if cell.size == 0 or cell.sum() < MIN_TISSUE * (size_l0 / ds) ** 2:
                continue
            covered[my0:my1, mx0:mx1] = cell
            name = f"patch_{len(jobs):05d}_x{x0}_y{y0}.jpg"
            jobs.append((x0, y0, size_l0, level, a.patch, os.path.join(pdir, name)))
            coords.append((name, x0, y0))
    slide.close()
    if not jobs:
        out({"error": "No tissue was found on the slide."}, 1)

    with Pool(max(1, a.workers), initializer=_open_slide, initargs=(a.slide,)) as pool:
        for _ in pool.imap_unordered(_read_tile, jobs, chunksize=4):
            pass

    with open(os.path.join(pdir, "patch_coords.csv"), "w", newline="") as fh:
        w = csv.writer(fh)
        w.writerow(["file", "x", "y", "w", "h", "level"])
        for name, x0, y0 in coords:
            w.writerow([name, x0, y0, size_l0, size_l0, level])

    tissue_px = int(mask.sum())
    coverage = float(covered.sum()) / tissue_px if tissue_px else 1.0

    # The audit image: tissue read in green, tissue left out in red, tile grid.
    k = min(1.0, 2400 / thumb.shape[1])
    img = cv2.resize(thumb, (int(thumb.shape[1] * k), int(thumb.shape[0] * k)), interpolation=cv2.INTER_AREA)
    m_s = cv2.resize(mask.astype(np.uint8), img.shape[1::-1], interpolation=cv2.INTER_NEAREST) > 0
    c_s = cv2.resize(covered.astype(np.uint8), img.shape[1::-1], interpolation=cv2.INTER_NEAREST) > 0
    tint = img.copy()
    tint[m_s & c_s] = (0.6 * tint[m_s & c_s] + 0.4 * np.array([40, 190, 90])).astype(np.uint8)
    tint[m_s & ~c_s] = (0.4 * tint[m_s & ~c_s] + 0.6 * np.array([230, 40, 40])).astype(np.uint8)
    f = k / ds
    for _, x0, y0 in coords:
        cv2.rectangle(tint, (int(x0 * f), int(y0 * f)), (int((x0 + size_l0) * f), int((y0 + size_l0) * f)), (30, 30, 30), 1)
    Image.fromarray(tint).save(os.path.join(run, "coverage.png"))
    Image.fromarray(img).save(os.path.join(run, "thumb.jpg"), quality=88)

    out({"patches_extracted": len(jobs), "candidates_total": cols * rows, "patches_skipped": 0,
         "patch_size": a.patch, "level": level, "base_mpp": mpp0, "target_mpp": a.mpp,
         "scale_l0_px_per_out_px": round(scale, 6), "slide_width": W0, "slide_height": H0,
         "max_patches": None, "min_tissue": MIN_TISSUE, "grid": [cols, rows],
         "coverage": round(coverage, 4), "tissue_px_at_8um": tissue_px,
         "thumb_file": os.path.join(run, "thumb.jpg"), "thumb_scale": round(ds / k, 4)})


def cmd_density(a) -> None:
    """Rebuild density.json and the fine mask maps of an existing run.

    From the full-resolution tiles when they are still there, otherwise from
    the 1000 px view images (whose edge ticks are too thin to count as nuclei).
    """
    run = a.run
    man = load(os.path.join(run, "manifest.json"))
    tiling = load(os.path.join(run, "tiling.json"))
    work_px = 952
    mpp_work = float(tiling.get("target_mpp") or 0.5) * int(tiling["patch_size"]) / work_px

    def small_of(t):
        full = os.path.join(run, "patches", t["file"])
        src = full if os.path.isfile(full) else os.path.join(run, t["view"])
        return cv2.resize(np.array(Image.open(src).convert("RGB")), (work_px, work_px), interpolation=cv2.INTER_AREA)

    samples = []
    for t in man["tiles"]:
        small = small_of(t)
        h = hematoxylin(small)[tissue_mask(small)]
        if h.size:
            samples.append(h[:: max(1, h.size // 5000)])
    thr = otsu(np.concatenate(samples)) if samples else 1.0
    write_density(run, ((t["id"], small_of(t)) for t in man["tiles"]), thr, mpp_work)
    out({"ok": True, "tiles": len(man["tiles"]), "hematoxylin_threshold": round(thr, 4)})


def cmd_prepare(a) -> None:
    run = a.run
    tiling = load(os.path.join(run, "tiling.json"))
    pdir = os.path.join(run, "patches")
    rows = list(csv.DictReader(open(os.path.join(pdir, "patch_coords.csv"), newline="")))
    if not rows:
        out({"error": "no patches in patch_coords.csv"}, 1)
    rows.sort(key=lambda r: (int(r["y"]), int(r["x"])))

    for d in ("view", "sheets"):
        os.makedirs(os.path.join(run, d), exist_ok=True)

    mpp_tile = float(tiling.get("target_mpp") or 0.5)
    work_px = 952                                          # analysis resolution (~1 um/px at 0.5 mpp tiles)
    mpp_work = mpp_tile * int(tiling["patch_size"]) / work_px

    # Pass 1, one tile at a time (a whole slide can be several hundred): the
    # view image the model reads, a thumbnail for the contact sheets, and a
    # sample of hematoxylin for one threshold across the whole slide. Only the
    # thumbnails stay in memory.
    per, cell = 16, 245
    samples, thumbs, ids = [], {}, {}
    pad = max(3, len(str(len(rows))))
    for i, r in enumerate(rows):
        pid = f"P{i + 1:0{pad}d}"
        ids[r["file"]] = pid
        rgb = np.array(Image.open(os.path.join(pdir, r["file"])).convert("RGB"))
        small = cv2.resize(rgb, (work_px, work_px), interpolation=cv2.INTER_AREA)
        view = cv2.resize(rgb, (VIEW_PX, VIEW_PX), interpolation=cv2.INTER_AREA)
        del rgb
        draw_ticks(view)
        Image.fromarray(view).save(os.path.join(run, "view", f"{pid}.jpg"), quality=88)
        thumbs[pid] = cv2.resize(small, (cell - 6, cell - 6), interpolation=cv2.INTER_AREA)
        h = hematoxylin(small)[tissue_mask(small)]
        if h.size:
            samples.append(h[:: max(1, h.size // 5000)])
    thr = otsu(np.concatenate(samples)) if samples else 1.0

    # Pass 2: the density maps, from the tiles again rather than from memory.
    def smalls():
        for r in rows:
            rgb = np.array(Image.open(os.path.join(pdir, r["file"])).convert("RGB"))
            yield ids[r["file"]], cv2.resize(rgb, (work_px, work_px), interpolation=cv2.INTER_AREA)

    fractions = write_density(run, smalls(), thr, mpp_work)
    manifest = [{
        "id": ids[r["file"]], "file": r["file"], "view": f"view/{ids[r['file']]}.jpg",
        "x": int(r["x"]), "y": int(r["y"]), "size_l0": int(r["w"]),
        "tissue_fraction": fractions[ids[r["file"]]],
    } for r in rows]

    # Contact sheets: 16 tiles per sheet, labelled, for the first look.
    for s in range(0, len(manifest), per):
        sheet = np.full((4 * cell + 20, 4 * cell + 20, 3), 255, np.uint8)
        for k, m in enumerate(manifest[s:s + per]):
            y0, x0 = 10 + (k // 4) * cell, 10 + (k % 4) * cell
            sheet[y0:y0 + cell - 6, x0:x0 + cell - 6] = thumbs[m["id"]]
            for col, w in (((0, 0, 0), 5), ((255, 255, 255), 2)):
                cv2.putText(sheet, m["id"], (x0 + 6, y0 + 28), cv2.FONT_HERSHEY_SIMPLEX, 0.8, col, w, cv2.LINE_AA)
        Image.fromarray(sheet).save(os.path.join(run, "sheets", f"sheet_{s // per + 1:02d}.jpg"), quality=85)

    # The overview: the slide with every tile outlined and labelled, so a tile
    # id can be placed on the slide at a glance.
    tfile = tiling.get("thumb_file")
    if tfile and os.path.isfile(tfile):
        ov = np.array(Image.open(tfile).convert("RGB"))
        f = 1.0 / float(tiling["thumb_scale"])
        fs = max(0.3, min(0.9, manifest[0]["size_l0"] * f / 90))
        for m in manifest:
            x0, y0 = int(m["x"] * f), int(m["y"] * f)
            x1, y1 = int((m["x"] + m["size_l0"]) * f), int((m["y"] + m["size_l0"]) * f)
            cv2.rectangle(ov, (x0, y0), (x1, y1), (20, 90, 200), 1)
            for col, w in (((255, 255, 255), 3), ((0, 0, 0), 1)):
                cv2.putText(ov, m["id"], (x0 + 3, y0 + int(14 * fs / 0.5)), cv2.FONT_HERSHEY_SIMPLEX, fs, col, w, cv2.LINE_AA)
        Image.fromarray(ov).save(os.path.join(run, "overview.png"))

    save(os.path.join(run, "manifest.json"), {
        "view_px": VIEW_PX, "grid": GRID, "patch_size": int(tiling["patch_size"]),
        "target_mpp": tiling.get("target_mpp"), "slide_width": tiling["slide_width"],
        "slide_height": tiling["slide_height"], "tiles": manifest,
    })
    out({"ok": True, "tiles": len(manifest), "sheets": math.ceil(len(manifest) / per),
         "hematoxylin_threshold": round(thr, 4)})


# ═════════════════════════════════════════════════════════════════════════════
# show / contours — helpers the model may call
# ═════════════════════════════════════════════════════════════════════════════

def tile_density(run: str, pid: str) -> np.ndarray:
    d = load(os.path.join(run, "density.json"))["tiles"]
    if pid not in d:
        out({"error": f"unknown tile {pid}"}, 1)
    return np.array(d[pid], np.float32)


def cmd_show(a) -> None:
    g = tile_density(a.run, a.tile)
    print(f"{a.tile} density, {GRID}x{GRID} cells of {VIEW_PX // GRID} view px; 0=none 9=highest")
    for row in g:
        print("".join(str(min(9, int(v * 10))) for v in row))
    out({"ok": True})


def contours_of(mask: np.ndarray, min_area: float, eps: float) -> list:
    cs, _ = cv2.findContours(mask.astype(np.uint8), cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
    polys = []
    for c in cs:
        if cv2.contourArea(c) < min_area:
            continue
        p = cv2.approxPolyDP(c, eps, True).reshape(-1, 2).tolist()
        if len(p) >= 3:
            polys.append(p)
    return polys


def cmd_contours(a) -> None:
    g = tile_density(a.run, a.tile)
    up = cv2.resize(g, (VIEW_PX, VIEW_PX), interpolation=cv2.INTER_CUBIC)
    out({"tile": a.tile, "level": a.level, "space": "view_px",
         "polygons": contours_of(up >= a.level, 400, 4.0)})


# ═════════════════════════════════════════════════════════════════════════════
# validate
# ═════════════════════════════════════════════════════════════════════════════

def _num(v) -> bool:
    return isinstance(v, (int, float)) and not isinstance(v, bool) and math.isfinite(v)


def _pt(p) -> bool:
    return isinstance(p, (list, tuple)) and len(p) == 2 and all(_num(c) and -1 <= c <= VIEW_PX + 1 for c in p)


def validate(run: str, box_rule: bool = True) -> tuple[dict | None, list, list]:
    """Return (result, errors, warnings)."""
    errors, warnings = [], []
    path = os.path.join(run, "result.json")
    if not os.path.isfile(path):
        return None, ["result.json was not written"], warnings
    try:
        r = load(path)
    except Exception as e:  # noqa: BLE001
        return None, [f"result.json is not valid JSON: {e}"], warnings
    if not isinstance(r, dict):
        return None, ["result.json must be a JSON object"], warnings

    ids = {t["id"] for t in load(os.path.join(run, "manifest.json"))["tiles"]}

    s = r.get("summary")
    if not isinstance(s, str) or not s.strip():
        errors.append("summary: required, a non-empty string")
    else:
        lines = [ln for ln in s.strip().splitlines() if ln.strip()]
        if len(lines) > MAX_SUMMARY_LINES:
            errors.append(f"summary: {len(lines)} lines; at most {MAX_SUMMARY_LINES} are allowed")
    if not isinstance(r.get("diagnosis"), str) or not r["diagnosis"].strip():
        errors.append("diagnosis: required, a non-empty string")
    if not (isinstance(r.get("diagnosis_code"), str) and CODE_RE.match(r["diagnosis_code"])):
        errors.append("diagnosis_code: required, the standard abbreviation of the diagnosis "
                      "(e.g. IDC, ILC, DCIS), 2-12 letters/digits")
    for field in ("summary", "diagnosis"):
        if isinstance(r.get(field), str) and ARABIC_RE.search(r[field]):
            errors.append(f"{field}: must be written in English")
    if not _num(r.get("confidence")) or not 0 <= r["confidence"] <= 1:
        errors.append("confidence: required, a number 0..1")

    seen = set()
    for i, p in enumerate(r.get("patches") or []):
        where = f"patches[{i}]"
        if not isinstance(p, dict) or p.get("id") not in ids:
            errors.append(f"{where}: id must be one of the tile ids in manifest.json")
            continue
        seen.add(p["id"])
        if p.get("tissue") not in TISSUE_KINDS:
            errors.append(f"{where} ({p['id']}): tissue must be one of {sorted(TISSUE_KINDS)}")
        if not _num(p.get("tumour_score")) or not 0 <= p["tumour_score"] <= 1:
            errors.append(f"{where} ({p['id']}): tumour_score must be a number 0..1")
    missing = sorted(ids - seen)
    if missing:
        errors.append(f"patches: every tile needs an entry; missing {', '.join(missing[:20])}"
                      + (" ..." if len(missing) > 20 else ""))

    rids = set()
    for i, g in enumerate(r.get("regions") or []):
        where = f"regions[{i}]"
        if not isinstance(g, dict):
            errors.append(f"{where}: must be an object")
            continue
        if not isinstance(g.get("id"), str) or g["id"] in rids:
            errors.append(f"{where}: id must be a unique string")
        else:
            rids.add(g["id"])
        if g.get("patch") not in ids:
            errors.append(f"{where}: patch must be a tile id")
        if g.get("label") not in REGION_LABELS:
            errors.append(f"{where}: label must be one of {sorted(REGION_LABELS)}")
        if not _num(g.get("confidence")) or not 0 <= g["confidence"] <= 1:
            errors.append(f"{where}: confidence must be a number 0..1")
        b = g.get("box")
        if not (isinstance(b, list) and len(b) == 4 and all(_num(c) and 0 <= c <= VIEW_PX for c in b)
                and b[0] < b[2] and b[1] < b[3]):
            errors.append(f"{where}: box must be [x0,y0,x1,y1] in view px, 0..{VIEW_PX}, x0<x1, y0<y1")
        elif box_rule and (b[2] - b[0]) * (b[3] - b[1]) > MAX_BOX_FRACTION * VIEW_PX * VIEW_PX:
            errors.append(f"{where} ({g.get('patch')}): box covers "
                          f"{(b[2] - b[0]) * (b[3] - b[1]) / VIEW_PX ** 2:.0%} of the tile; at most "
                          f"{MAX_BOX_FRACTION:.0%} is allowed — outline the nests, not the tile")
        for k in ("positive_points", "negative_points"):
            pts = g.get(k, [])
            if not isinstance(pts, list) or not all(_pt(p) for p in pts):
                errors.append(f"{where}: {k} must be a list of [x,y] in view px")
        if not g.get("positive_points"):
            errors.append(f"{where}: at least one positive point is required (it is SAM's prompt)")
        if "mask_level" in g and not (_num(g["mask_level"]) and 0.1 <= g["mask_level"] <= 0.9):
            errors.append(f"{where}: mask_level must be a number 0.1..0.9")
        poly = g.get("polygon")
        if not (isinstance(poly, list) and len(poly) >= 3 and all(_pt(p) for p in poly)):
            errors.append(f"{where}: polygon must be >= 3 [x,y] points in view px")

    for pid, grid in (r.get("heatmap") or {}).items():
        if pid not in ids:
            errors.append(f"heatmap.{pid}: not a tile id")
        elif not (isinstance(grid, list) and len(grid) == GRID
                  and all(isinstance(row, list) and len(row) == GRID and all(_num(v) and 0 <= v <= 1 for v in row)
                          for row in grid)):
            errors.append(f"heatmap.{pid}: must be {GRID}x{GRID} numbers 0..1")

    if not r.get("regions"):
        warnings.append("no regions were returned")
    return r, errors, warnings


def cmd_validate(a) -> None:
    _, errors, warnings = validate(a.run)
    out({"ok": not errors, "errors": errors, "warnings": warnings}, 0 if not errors else 1)


# ═════════════════════════════════════════════════════════════════════════════
# finalize
# ═════════════════════════════════════════════════════════════════════════════

def sam_refine(run: str, result: dict, tiles: dict, ckpt: str, model: str, device: str) -> tuple[dict, str | None]:
    """SAM on the full-resolution tile, prompted with the model's box and points. Returns polygons by region id."""
    try:
        from segment_anything import SamPredictor, sam_model_registry  # type: ignore
    except Exception as e:  # noqa: BLE001
        return {}, f"SAM not run: segment_anything is not importable ({e})"
    if not os.path.isfile(ckpt):
        return {}, f"SAM not run: checkpoint not found at {ckpt}"

    pred = SamPredictor(sam_model_registry[model](checkpoint=ckpt).to(device))
    polys, by_tile = {}, {}
    for g in result.get("regions") or []:
        by_tile.setdefault(g["patch"], []).append(g)
    for pid, regions in by_tile.items():
        path = os.path.join(run, "patches", tiles[pid]["file"])
        if not os.path.isfile(path):
            continue
        img = np.array(Image.open(path).convert("RGB"))
        k = img.shape[0] / VIEW_PX
        pred.set_image(img)
        for g in regions:
            pts = [*g["positive_points"], *g.get("negative_points", [])]
            lbl = [1] * len(g["positive_points"]) + [0] * len(g.get("negative_points", []))
            box = np.array(g["box"], np.float32) * k
            masks, scores, _ = pred.predict(point_coords=np.array(pts, np.float32) * k,
                                            point_labels=np.array(lbl, np.int32),
                                            box=box, multimask_output=True)
            m = masks[int(np.argmax(scores))].astype(np.uint8)
            x0, y0, x1, y1 = (int(round(c)) for c in box)
            clip = np.zeros_like(m)
            clip[y0:y1 + 1, x0:x1 + 1] = 1
            ps = contours_of(m & clip, 200 * k * k, 2.0 * k)
            if ps:
                polys[g["id"]] = [[[x / k, y / k] for x, y in p] for p in ps]
    return polys, None


def _tile_grid(tiles: dict) -> tuple[float, float, float, dict]:
    """Tiles sit on a regular lattice (patch_extract cuts them edge to edge):
    return the lattice step and origin, and each tile's (column, row)."""
    first = next(iter(tiles.values()))
    size = float(first["size_l0"])
    ox = first["x"] - round(first["x"] / size) * size
    oy = first["y"] - round(first["y"] / size) * size
    return size, ox, oy, {pid: (round((t["x"] - ox) / size), round((t["y"] - oy) / size))
                          for pid, t in tiles.items()}


def _clusters(cells: dict) -> list:
    """Groups of tiles that touch (8-neighbourhood): one per piece of tissue."""
    where = {v: k for k, v in cells.items()}
    seen, groups = set(), []
    for start in cells.values():
        if start in seen:
            continue
        stack, group = [start], []
        seen.add(start)
        while stack:
            c, r = stack.pop()
            group.append(where[(c, r)])
            for dc in (-1, 0, 1):
                for dr in (-1, 0, 1):
                    nb = (c + dc, r + dr)
                    if nb in where and nb not in seen:
                        seen.add(nb)
                        stack.append(nb)
        groups.append(group)
    return groups


def slide_mask(run: str, tiles: dict, regions: list, um_per_view_px: float,
               implicit: set | None = None) -> tuple[list, dict, dict]:
    """The tumour mask of the whole slide, cut in one piece.

    Each tile's raw fractions are laid side by side into one canvas per piece
    of tissue and only then smoothed, thresholded and traced, so the outline
    runs across tile borders exactly as the tissue does: no seams, no gaps,
    no edges drawn along the side of a tile.

    The model decides what is tumour. The boxes of its tumour regions say where
    a mask may exist (and at which mask_level); every tile it scored as tumour
    without drawing a region (*implicit*) counts as one whole-tile box, so the
    mask reaches all the tumour on the slide and not only the tiles it opened.
    Its non-tumour regions are cut out, and its negative points remove what
    they sit on. The border itself comes from the pixels.

    The heat density is taken from the same stitched map (smoothed over
    ~25 um instead of ~8), so it too runs across tile borders unbroken.

    A whole slide can be several hundred tiles on a server with a few GB of
    memory, so each piece is built twice (once for the slide-wide scale, once
    to be cut) rather than every piece being held at once.

    Returns (polygons in level-0 px, each [outer ring, *holes]; per-tile mask
    coverage and per-tile density, both HEAT_GRID x HEAT_GRID).
    """
    size, ox, oy, cells = _tile_grid(tiles)
    R = MASK_PX
    um_per_raw = um_per_view_px * VIEW_PX / R
    sig = max(0.5, 8.0 / um_per_raw)          # ~8 um: follows a nest border
    sig_heat = max(0.5, 25.0 / um_per_raw)    # ~25 um: the heat, as before
    implicit = implicit or set()

    by_tile = {}
    for g in regions:
        by_tile.setdefault(g["patch"], []).append(g)
    groups = []
    for group in _clusters(cells):
        cs = [cells[p][0] for p in group]
        rs = [cells[p][1] for p in group]
        groups.append((group, min(cs), min(rs), max(cs) - min(cs) + 1, max(rs) - min(rs) + 1))

    def build(group, c0, r0, nc, nr):
        raw = np.zeros((nr * R, nc * R, 3), np.uint8)
        for pid in group:
            path = os.path.join(run, "masks", f"raw_{pid}.png")
            if os.path.isfile(path):
                c, r = cells[pid]
                raw[(r - r0) * R:(r - r0 + 1) * R, (c - c0) * R:(c - c0 + 1) * R] = np.asarray(Image.open(path))
        big, lym, tis = (raw[..., i].astype(np.float32) / 255.0 for i in range(3))
        del raw

        def dens(sg):
            t_s = np.maximum(cv2.GaussianBlur(tis, (0, 0), sg), 0.7)
            d_lym = cv2.GaussianBlur(lym, (0, 0), sg) / t_s
            return cv2.GaussianBlur(big, (0, 0), sg) / t_s * np.clip(1 - (d_lym - 0.06) / 0.10, 0, 1)
        return dens(sig), tis, dens(sig_heat)

    # Pass 1: the slide-wide scale, from a sample of every piece.
    vs, hs = [], []
    for gr in groups:
        v, tis, vh = build(*gr)
        sel = tis > 0.3
        step = max(1, int(sel.sum()) // 200_000)
        vs.append(v[sel][::step])
        hs.append(vh[sel][::step])
        del v, tis, vh

    def p99(parts):
        x = np.concatenate(parts or [np.zeros(1)])
        return max(float(np.percentile(x, 99)) if x.size else 1.0, 1e-4)
    vmax, hmax = p99(vs), p99(hs)
    del vs, hs

    rings, cover, dens_out = [], {}, {}
    for group, c0, r0, nc, nr in groups:
        v, tis, vh = build(group, c0, r0, nc, nr)
        # Cut at 2 view px per pixel where the piece is small enough, at the
        # raw 4 (~3.8 um, still below a cell) where a doubled canvas would not fit.
        UP = 2 if nc * nr <= 120 else 1
        RU = R * UP
        k_view = RU / VIEW_PX                 # view px -> canvas px within a tile
        a = k_view ** 2                       # view px^2 -> canvas px^2
        H, W = nr * RU, nc * RU
        up = np.clip(v / vmax, 0, 1)
        if UP > 1:
            up = cv2.resize(up, (W, H), interpolation=cv2.INTER_CUBIC)
            tis_c = cv2.resize(tis, (W, H), interpolation=cv2.INTER_LINEAR)
        else:
            tis_c = tis
        del v

        def to_canvas(pid, p, c0=c0, r0=r0, RU=RU, k_view=k_view):
            c, r = cells[pid]
            return ((c - c0) * RU + p[0] * k_view, (r - r0) * RU + p[1] * k_view)

        level = np.full((H, W), np.inf, np.float32)   # inf: no tumour region here
        exclude = np.zeros((H, W), np.uint8)
        negatives = []
        for pid in group:
            if pid in implicit:
                x0, y0 = to_canvas(pid, (0, 0))
                sl = level[int(y0):int(y0) + RU, int(x0):int(x0) + RU]
                np.minimum(sl, MASK_LEVEL, out=sl)
            for g in by_tile.get(pid, []):
                if g["label"] in TUMOUR_LABELS:
                    x0, y0 = to_canvas(pid, g["box"][:2])
                    x1, y1 = to_canvas(pid, g["box"][2:])
                    sl = level[int(y0):int(np.ceil(y1)), int(x0):int(np.ceil(x1))]
                    np.minimum(sl, float(g.get("mask_level", MASK_LEVEL)), out=sl)
                    negatives += [to_canvas(pid, p) for p in g.get("negative_points", [])]
                else:
                    poly = np.array([to_canvas(pid, p) for p in g["polygon"]], np.int32)
                    cv2.fillPoly(exclude, [poly], 1)

        m = ((up >= level) & (tis_c > 0.3) & (exclude == 0)).astype(np.uint8)
        del level, exclude, tis_c
        kern = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (5, 5))
        m = cv2.morphologyEx(cv2.morphologyEx(m, cv2.MORPH_OPEN, kern), cv2.MORPH_CLOSE, kern)

        # Negative points: a compact dense patch goes whole; inside a large
        # confluent one, only what is continuous with the point and alike in
        # density (flood-filled within ~150 um), plus a small hole around it.
        n, lab, stats, _ = cv2.connectedComponentsWithStats(m, connectivity=8)
        drop = np.zeros(n, bool)
        for x, y in negatives:
            xi, yi = int(np.clip(x, 0, W - 1)), int(np.clip(y, 0, H - 1))
            hit = lab[max(0, yi - 3):yi + 4, max(0, xi - 3):xi + 4]
            for c in np.unique(hit[hit > 0]):
                if stats[c, cv2.CC_STAT_AREA] <= NEG_DROP_MAX_PX * a:
                    drop[c] = True
                    continue
                rw = int(20 * k_view)
                y0w, x0w = max(0, yi - rw), max(0, xi - rw)
                win = up[y0w:yi + rw + 1, x0w:xi + rw + 1]
                sy, sx = np.unravel_index(int(np.argmax(win)), win.shape)
                sx, sy = x0w + int(sx), y0w + int(sy)
                fr = int(NEG_FLOOD_PX * k_view)
                wy0, wx0 = max(0, sy - fr - 1), max(0, sx - fr - 1)
                wy1, wx1 = min(H, sy + fr + 2), min(W, sx + fr + 2)
                sub = np.ascontiguousarray(up[wy0:wy1, wx0:wx1])
                ff = np.ones((wy1 - wy0 + 2, wx1 - wx0 + 2), np.uint8)
                cv2.circle(ff, (sx - wx0 + 1, sy - wy0 + 1), fr, 0, -1)
                cv2.floodFill(sub, ff, (sx - wx0, sy - wy0), 0, NEG_FLOOD_TOL, NEG_FLOOD_TOL,
                              4 | cv2.FLOODFILL_FIXED_RANGE | cv2.FLOODFILL_MASK_ONLY | (2 << 8))
                m[wy0:wy1, wx0:wx1][ff[1:-1, 1:-1] == 2] = 0
                cv2.circle(m, (xi, yi), int(NEG_CARVE_PX * k_view), 0, -1)
        m[drop[lab]] = 0
        n, lab, stats, _ = cv2.connectedComponentsWithStats(m, connectivity=8)
        keep = stats[:, cv2.CC_STAT_AREA] >= 300 * a
        keep[0] = False
        m = keep[lab].astype(np.uint8)
        del lab, up

        l0_per_px = size / RU
        bx, by = ox + c0 * size, oy + r0 * size
        # Each polygon is its outer ring followed by its holes, kept together
        # so a hole is never drawn apart from the ring it belongs to.
        found, hier = cv2.findContours(m, cv2.RETR_CCOMP, cv2.CHAIN_APPROX_SIMPLE)

        def ring(cnt, bx=bx, by=by, l0_per_px=l0_per_px):
            p = cv2.approxPolyDP(cnt, 0.75, True).reshape(-1, 2)
            return [[round(bx + float(x) * l0_per_px, 1), round(by + float(y) * l0_per_px, 1)]
                    for x, y in p] if len(p) >= 3 else None

        for i, cnt in enumerate(found):
            if hier[0][i][3] != -1 or cv2.contourArea(cnt) < 150 * a:
                continue                          # holes are gathered under their parent
            outer = ring(cnt)
            if not outer:
                continue
            poly, j = [outer], hier[0][i][2]
            while j != -1:
                if cv2.contourArea(found[j]) >= 40 * a:
                    h = ring(found[j])
                    if h:
                        poly.append(h)
                j = hier[0][j][0]
            rings.append(poly)
        for pid in group:
            c, r = cells[pid]
            cover[pid] = cv2.resize(m[(r - r0) * RU:(r - r0 + 1) * RU, (c - c0) * RU:(c - c0 + 1) * RU]
                                    .astype(np.float32), (HEAT_GRID, HEAT_GRID), interpolation=cv2.INTER_AREA)
            sl = (slice((r - r0) * R, (r - r0 + 1) * R), slice((c - c0) * R, (c - c0 + 1) * R))
            d = cv2.resize(np.clip(vh[sl] / hmax, 0, 1), (HEAT_GRID, HEAT_GRID), interpolation=cv2.INTER_AREA)
            t = cv2.resize(tis[sl], (HEAT_GRID, HEAT_GRID), interpolation=cv2.INTER_AREA)
            dens_out[pid] = d * (t > 0.3)
        del m, tis, vh
    return rings, cover, dens_out


def with_code(code: str, text: str) -> str:
    """'IDC — Invasive carcinoma of no special type ...': the abbreviation first,
    once, however the text itself begins."""
    code, text = code.strip(), text.strip()
    if text.upper().startswith(code.upper()):
        text = text[len(code):].lstrip(" -—:,")
    return f"{code} — {text}"[:255]


def point_in_poly(x: float, y: float, poly: list) -> bool:
    return cv2.pointPolygonTest(np.array(poly, np.float32).reshape(-1, 1, 2), (float(x), float(y)), False) >= 0


def cmd_finalize(a) -> None:
    run = a.run
    # --legacy re-finalizes a run made before the box-size rule existed.
    result, errors, warnings = validate(run, box_rule=not a.legacy)
    if errors:
        out({"ok": False, "errors": errors, "warnings": warnings}, 1)

    man = load(os.path.join(run, "manifest.json"))
    tiles = {t["id"]: t for t in man["tiles"]}
    density = load(os.path.join(run, "density.json"))["tiles"]

    def l0(pid: str, p) -> list:
        t = tiles[pid]
        k = t["size_l0"] / VIEW_PX
        return [round(t["x"] + p[0] * k, 1), round(t["y"] + p[1] * k, 1)]

    sam_polys, sam_note = {}, None
    if a.sam_ckpt:
        sam_polys, sam_note = sam_refine(run, result, tiles, a.sam_ckpt, a.sam_model, a.sam_device)
        if sam_note:
            warnings.append(sam_note)

    # One tumour mask for the whole slide, cut across tile borders (slide_mask).
    has_raw = os.path.isfile(os.path.join(run, "masks", f"raw_{next(iter(tiles))}.png"))
    um_per_view_px = float(man.get("target_mpp") or 0.5) * int(man["patch_size"]) / VIEW_PX
    # Tiles scored as tumour where no tumour region was drawn: the mask is cut
    # on them as whole-tile regions, so it covers every tumour tile read.
    drawn = {g["patch"] for g in result.get("regions") or [] if g["label"] in TUMOUR_LABELS}
    implicit = {p["id"] for p in result["patches"]
                if float(p.get("tumour_score", 0)) >= 0.5 and p["id"] not in drawn}
    mask_rings_l0, tile_cover, tile_dens = (slide_mask(run, tiles, result.get("regions") or [], um_per_view_px,
                                                       implicit) if has_raw else ([], {}, {}))
    # The heat grid: the slide-wide one when the raw maps exist, else the
    # per-tile density.json of older runs.
    hg = HEAT_GRID if has_raw else GRID
    if has_raw:
        density = {pid: np.round(d.astype(np.float64), 3).tolist() for pid, d in tile_dens.items()}

    regions = []
    for g in result.get("regions") or []:
        pid = g["patch"]
        b0, b1 = l0(pid, g["box"][:2]), l0(pid, g["box"][2:])
        regions.append({
            "mask_level": float(g.get("mask_level", MASK_LEVEL)),
            "id": g["id"], "patch": pid, "label": g["label"], "confidence": g["confidence"],
            "note": str(g.get("note", ""))[:300],
            "box": [*b0, *b1],
            "positive_points": [l0(pid, p) for p in g["positive_points"]],
            "negative_points": [l0(pid, p) for p in g.get("negative_points", [])],
            "polygon": [l0(pid, p) for p in g["polygon"]],
            "sam_polygons": [[l0(pid, p) for p in poly] for poly in sam_polys.get(g["id"], [])],
        })

    # Heatmap per tile: the model's own grid where it gave one; otherwise the
    # measured density, kept in full inside the tumour it outlined and damped
    # elsewhere by its tile-level tumour score.
    scores = {p["id"]: p for p in result["patches"]}
    step = VIEW_PX / hg
    heat, method = {}, {}
    for pid in tiles:
        if pid in (result.get("heatmap") or {}):
            own = np.array(result["heatmap"][pid], np.float32)          # the model's own 25 x 25
            heat[pid] = np.round(cv2.resize(own, (hg, hg), interpolation=cv2.INTER_LINEAR)
                                 .astype(np.float64), 3).tolist()
            method[pid] = "model"
            continue
        d = np.array(density[pid], np.float32)
        base = 0.25 * float(scores[pid]["tumour_score"])
        if pid in tile_cover:
            # The share of each heat cell the tumour mask covers: full density
            # inside the tumour, damped outside it, graded along its border.
            heat[pid] = np.round((d * (base + (1 - base) * tile_cover[pid])).astype(np.float64), 3).tolist()
            method[pid] = "density_x_mask"
            continue
        polys = [g["polygon"] for g in result.get("regions") or [] if g["patch"] == pid and g["label"] in TUMOUR_LABELS]
        polys += [p for g in result.get("regions") or [] if g["patch"] == pid and g["label"] in TUMOUR_LABELS
                  for p in sam_polys.get(g["id"], [])]
        w = np.full((hg, hg), 0.25 * float(scores[pid]["tumour_score"]), np.float32)
        for r_ in range(hg):
            for c in range(hg):
                if any(point_in_poly((c + 0.5) * step, (r_ + 0.5) * step, p) for p in polys):
                    w[r_, c] = 1.0
        heat[pid] = np.round((d * w).astype(np.float64), 3).tolist()
        method[pid] = "density_x_regions"

    # How much of the slide's tissue was read at all — measured at tiling.
    tiling = load(os.path.join(run, "tiling.json")) if os.path.isfile(os.path.join(run, "tiling.json")) else {}
    coverage = {"tissue_read": tiling.get("coverage"), "tiles": len(tiles),
                "tumour_tiles": sum(1 for p in result["patches"] if float(p.get("tumour_score", 0)) >= 0.5),
                "tiles_opened_as_regions": len({g["patch"] for g in result.get("regions") or []}),
                "tumour_tiles_masked_without_region": len(implicit)}
    if tiling.get("coverage") is None:
        warnings.append("Tissue coverage was not measured for this run (tiled before full-slide tiling existed).")
    elif tiling["coverage"] < 0.99:
        warnings.append(f"Only {tiling['coverage']:.0%} of the tissue was tiled; the rest was not read.")

    summary_lines = [ln.rstrip() for ln in result["summary"].strip().splitlines() if ln.strip()][:MAX_SUMMARY_LINES]
    final = {
        "coordinate_space": "svs_level0_pixels",
        "slide_width": man["slide_width"], "slide_height": man["slide_height"],
        "grid": hg, "view_px": VIEW_PX,
        "summary": "\n".join(summary_lines),
        "diagnosis_code": result["diagnosis_code"].strip(),
        "diagnosis": with_code(result["diagnosis_code"], result["diagnosis"]),
        "confidence": result["confidence"],
        "tiles": [{**tiles[pid], "tissue": scores[pid]["tissue"], "tumour_score": scores[pid]["tumour_score"],
                   "note": str(scores[pid].get("note", ""))[:300]} for pid in tiles],
        "regions": regions,
        "coverage": coverage,
        "tumour_mask": {"polygons": mask_rings_l0, "method": "slide-wide nuclear-density mask inside "
                        "the tumour regions, minus non-tumour regions and negative points",
                        "default_level": MASK_LEVEL},
        "heatmap": {"method": method, "tiles": heat},
        "density": density,
        "sam_refined": bool(sam_polys),
        "warnings": warnings,
    }
    save(os.path.join(run, "final.json"), final)
    out({"ok": True, "regions": len(regions), "sam_refined": bool(sam_polys), "warnings": warnings,
         "summary": final["summary"], "diagnosis": final["diagnosis"], "diagnosis_code": final["diagnosis_code"], "confidence": final["confidence"]})


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = ap.add_subparsers(dest="cmd", required=True)
    p = sub.add_parser("prepare"); p.add_argument("run")
    p = sub.add_parser("tile"); p.add_argument("run"); p.add_argument("slide")
    p.add_argument("--patch", type=int, default=1904); p.add_argument("--mpp", type=float, default=0.5)
    p.add_argument("--workers", type=int, default=2)
    p = sub.add_parser("density"); p.add_argument("run")
    p = sub.add_parser("show"); p.add_argument("run"); p.add_argument("tile")
    p = sub.add_parser("contours"); p.add_argument("run"); p.add_argument("tile")
    p.add_argument("--level", type=float, default=0.55)
    p = sub.add_parser("validate"); p.add_argument("run")
    p = sub.add_parser("finalize"); p.add_argument("run")
    p.add_argument("--sam-ckpt", default=""); p.add_argument("--sam-model", default="vit_b")
    p.add_argument("--sam-device", default="cpu")
    p.add_argument("--legacy", action="store_true", help="skip the box-size rule (runs made before it)")
    a = ap.parse_args()
    try:
        {"tile": cmd_tile, "prepare": cmd_prepare, "density": cmd_density, "show": cmd_show, "contours": cmd_contours,
         "validate": cmd_validate, "finalize": cmd_finalize}[a.cmd](a)
    except SystemExit:
        raise
    except Exception as e:  # noqa: BLE001
        out({"error": f"{type(e).__name__}: {e}"}, 1)


if __name__ == "__main__":
    main()
