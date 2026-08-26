#!/usr/bin/env python3
"""
patch_extract.py — Production-ready WSI patch extraction pipeline.

Pipeline
--------
1. Open WSI with OpenSlide.
2. Detect tissue regions using HSV saturation thresholding + morphological ops.
3. Slide a strided grid over the thumbnail mask to identify patch candidates.
4. For each candidate: read the actual region at the requested level, filter
   white / black / low-tissue patches, save accepted patches.
5. Optionally generate an overview PNG showing patch locations.
6. Write a CSV / JSON file with patch coordinates.
7. Print a JSON summary to stdout (consumed by the Laravel job).

Usage
-----
    python patch_extract.py \
        --input          /path/to/slide.svs \
        --output_dir     /path/to/patches/ \
        --patch_size     256 \
        --target_mpp     0.5 \
        --max_patches    3000 \
        --overlap        0 \
        --format         png \
        --tissue_threshold 0.5 \
        --workers        4 \
        --save_coords \
        --overview

Scale
-----
Patches are defined by PHYSICAL size via --target_mpp, not by a pyramid level.
256 px at 0.5 mpp covers 128 um of tissue on every slide, whether the scanner
recorded it at 20x (~0.5 mpp) or 40x (~0.25 mpp). The pyramid level is chosen
automatically as the deepest one that needs no upsampling, and the region is then
downscaled to --patch_size. Slides that declare no MPP are rejected unless
--allow_missing_mpp is passed.

Output JSON (stdout)
--------------------
{
    "patches_extracted": <int>,
    "patches_skipped":   <int>,
    "patch_size":        <int>,
    "level":             <int>,
    "slide_width":       <int>,
    "slide_height":      <int>,
    "output_dir":        "<str>",
    "coords_file":       "<str | null>",
    "overview_file":     "<str | null>"
}
"""

from __future__ import annotations

import argparse
import csv
import json
import logging
import math
import os
import random
import sys
from multiprocessing import Pool, cpu_count
from pathlib import Path
from typing import List, Optional, Tuple

# ── Dependency checks ─────────────────────────────────────────────────────────

def _missing(pkg: str, install: str) -> None:
    print(json.dumps({"error": f"{pkg} is not installed. Run: pip install {install}"}))
    sys.exit(1)

try:
    import openslide
    from openslide import OpenSlide
except ImportError:
    _missing("openslide-python", "openslide-python")

try:
    import cv2
except ImportError:
    _missing("OpenCV", "opencv-python-headless")

try:
    import numpy as np
except ImportError:
    _missing("numpy", "numpy")

try:
    from PIL import Image
except ImportError:
    _missing("Pillow", "Pillow")

# ── Logging ───────────────────────────────────────────────────────────────────

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
    handlers=[logging.StreamHandler(sys.stderr)],
)
log = logging.getLogger("patch_extract")

# ── Types ─────────────────────────────────────────────────────────────────────

Coord = Tuple[int, int, int, int]  # (x, y, w, h) at level-0 coordinates

# Tissue masks are built at a fixed physical resolution rather than at
# "whatever the last pyramid level happens to be", so mask granularity — and
# therefore the tissue-fraction test that accepts or rejects a patch — is the
# same on every slide.
MASK_MPP = 32.0  # microns per pixel for the tissue mask


# ═════════════════════════════════════════════════════════════════════════════
# Physical scale
# ═════════════════════════════════════════════════════════════════════════════

def read_base_mpp(slide: OpenSlide) -> Optional[float]:
    """
    Level-0 microns per pixel, or None when the slide does not declare it.

    This is the value that makes a patch mean the same thing on every scanner: a
    256 px patch covers 64 um on a 0.25 mpp slide but 128 um on a 0.5 mpp one.
    """
    for key in (openslide.PROPERTY_NAME_MPP_X, "openslide.mpp-x", "aperio.MPP"):
        raw = slide.properties.get(key)
        if raw:
            try:
                mpp = float(raw)
                if mpp > 0:
                    return mpp
            except (TypeError, ValueError):
                continue
    return None


def pick_level_for_scale(slide: OpenSlide, scale: float) -> int:
    """
    Highest pyramid level whose downsample does not exceed *scale*.

    Reading from that level and then downscaling to the requested patch size
    avoids ever upsampling, which would invent detail that is not in the slide.
    """
    best = 0
    for lvl, ds in enumerate(slide.level_downsamples):
        if ds <= scale + 1e-6:
            best = lvl
    return best


# ═════════════════════════════════════════════════════════════════════════════
# Tissue detection
# ═════════════════════════════════════════════════════════════════════════════

def build_tissue_mask(
    slide: OpenSlide,
    base_mpp: Optional[float] = None,
    sat_threshold: int = 20,
    use_otsu: bool = True,
    blur_ksize: int = 7,
    morph_ksize: int = 15,
    min_contour_area: float = 500.0,
) -> Tuple[np.ndarray, float]:
    """
    Return a binary tissue mask and the downsampling factor
    (level0_pixels / mask_pixels).

    Pipeline:
        RGB → HSV → saturation channel → median blur → Otsu/threshold
        → morphological closing → contour filtering
    """
    W0, H0 = slide.level_dimensions[0]

    # Target a fixed physical mask resolution when the slide declares its scale.
    # Falling back to the smallest pyramid level (the previous behaviour) made
    # the mask coarse on slides with many levels and fine on slides with few.
    if base_mpp:
        mask_scale = max(1.0, MASK_MPP / base_mpp)
        thumb_w = max(1, int(round(W0 / mask_scale)))
        thumb_h = max(1, int(round(H0 / mask_scale)))
    else:
        thumb_w, thumb_h = slide.level_dimensions[slide.level_count - 1]

    thumb_img = np.array(slide.get_thumbnail((thumb_w, thumb_h)).convert("RGB"))
    thumb_h_actual, thumb_w_actual = thumb_img.shape[:2]

    # Downsampling factor from level-0 to the mask we actually got back
    ds_x = W0 / float(thumb_w_actual)
    ds_y = H0 / float(thumb_h_actual)
    ds   = (ds_x + ds_y) / 2.0

    # RGB → HSV, use saturation
    hsv = cv2.cvtColor(thumb_img, cv2.COLOR_RGB2HSV)
    sat = hsv[:, :, 1]

    # Median blur to remove noise
    if blur_ksize % 2 == 0:
        blur_ksize += 1
    blurred = cv2.medianBlur(sat, blur_ksize)

    # Otsu adapts the cut to each slide's own staining intensity. The previous
    # code documented an Otsu branch but never implemented one, so a single
    # hard-coded saturation cut of 20 was applied to every slide: weakly stained
    # sections lost real tissue, strongly stained ones let background through.
    if use_otsu:
        otsu_t, mask = cv2.threshold(blurred, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
        # Guard against a degenerate threshold on nearly-empty slides.
        if otsu_t < 5 or otsu_t > 200:
            log.warning("Otsu threshold %.0f looks degenerate; falling back to fixed %d",
                        otsu_t, sat_threshold)
            _, mask = cv2.threshold(blurred, sat_threshold, 255, cv2.THRESH_BINARY)
        else:
            log.info("Otsu saturation threshold: %.0f", otsu_t)
    else:
        _, mask = cv2.threshold(blurred, sat_threshold, 255, cv2.THRESH_BINARY)

    # Morphological closing to fill small holes
    if morph_ksize > 0:
        kernel = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (morph_ksize, morph_ksize))
        mask   = cv2.morphologyEx(mask, cv2.MORPH_CLOSE, kernel)

    # Remove small spurious contours
    contours, _ = cv2.findContours(mask, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
    cleaned = np.zeros_like(mask)
    for cnt in contours:
        if cv2.contourArea(cnt) >= min_contour_area:
            cv2.drawContours(cleaned, [cnt], -1, 255, cv2.FILLED)

    log.info(
        "Tissue mask built %dx%d px (~%.1f um/px), downsample=%.1f, "
        "tissue pixels=%d / total=%d (%.1f%%)",
        thumb_w_actual, thumb_h_actual,
        (base_mpp * ds) if base_mpp else float("nan"),
        ds,
        np.count_nonzero(cleaned),
        cleaned.size,
        100.0 * np.count_nonzero(cleaned) / cleaned.size,
    )
    return cleaned, ds


# ═════════════════════════════════════════════════════════════════════════════
# Candidate grid generation
# ═════════════════════════════════════════════════════════════════════════════

def generate_candidates(
    slide: OpenSlide,
    mask: np.ndarray,
    ds: float,
    patch_size: int,
    overlap: int,
    tissue_threshold: float,
    scale: float,
) -> List[Coord]:
    """
    Slide a grid over the slide and return level-0 coordinates of patches whose
    tissue mask coverage exceeds the threshold.

    *scale* is level-0 pixels per output pixel (target_mpp / base_mpp), so the
    grid is laid out in PHYSICAL units. The grid used to be derived from a fixed
    pyramid level instead, which meant the same patch_size covered twice the
    tissue on a 20x slide as on a 40x one — and both were then fed to a
    foundation model expecting one scale.
    """
    stride        = max(1, patch_size - overlap)           # output pixels
    stride_l0     = max(1, int(round(stride * scale)))     # level-0 pixels
    patch_size_l0 = max(1, int(round(patch_size * scale))) # level-0 pixels

    W0, H0 = slide.level_dimensions[0]

    candidates: List[Coord] = []

    for y0 in range(0, H0 - patch_size_l0 + 1, stride_l0):
        for x0 in range(0, W0 - patch_size_l0 + 1, stride_l0):
            # Map to thumbnail coordinates
            mx1 = int(x0 / ds)
            my1 = int(y0 / ds)
            mx2 = int((x0 + patch_size_l0) / ds)
            my2 = int((y0 + patch_size_l0) / ds)

            mx1 = max(0, mx1); my1 = max(0, my1)
            mx2 = min(mask.shape[1], mx2); my2 = min(mask.shape[0], my2)

            if mx2 <= mx1 or my2 <= my1:
                continue

            roi    = mask[my1:my2, mx1:mx2]
            ratio  = np.count_nonzero(roi) / float(roi.size)
            if ratio >= tissue_threshold:
                candidates.append((x0, y0, patch_size_l0, patch_size_l0))

    log.info(
        "Grid generated: %d candidate patches (tissue>=%.0f%%, %d level-0 px per patch)",
        len(candidates), tissue_threshold * 100, patch_size_l0,
    )
    return candidates


# ═════════════════════════════════════════════════════════════════════════════
# Patch quality filters
# ═════════════════════════════════════════════════════════════════════════════

def is_white_patch(arr: np.ndarray, threshold: int = 220, ratio: float = 0.85) -> bool:
    """True if more than `ratio` of pixels are near-white (background)."""
    gray = arr.mean(axis=2) if arr.ndim == 3 else arr
    return float(np.mean(gray > threshold)) > ratio


def is_black_patch(arr: np.ndarray, threshold: int = 15, ratio: float = 0.50) -> bool:
    """True if more than `ratio` of pixels are near-black (ink / fold artefact)."""
    gray = arr.mean(axis=2) if arr.ndim == 3 else arr
    return float(np.mean(gray < threshold)) > ratio


def has_tissue_center(
    arr: np.ndarray,
    sat_threshold: int = 20,
    center_fraction: float = 0.5,
    required_ratio: float = 0.20,
) -> bool:
    """
    Check that at least `required_ratio` of pixels in the central
    `center_fraction`  `center_fraction` crop have sufficient saturation.
    """
    h, w = arr.shape[:2]
    margin_h = int(h * (1 - center_fraction) / 2)
    margin_w = int(w * (1 - center_fraction) / 2)
    center   = arr[margin_h: h - margin_h, margin_w: w - margin_w]
    if center.size == 0:
        return False
    hsv = cv2.cvtColor(center, cv2.COLOR_RGB2HSV)
    sat = hsv[:, :, 1]
    return float(np.mean(sat > sat_threshold)) >= required_ratio


# ═════════════════════════════════════════════════════════════════════════════
# Worker — extracts and saves a single patch
# ═════════════════════════════════════════════════════════════════════════════

def _extract_worker(args: tuple) -> Optional[dict]:
    """
    Worker function called by multiprocessing.Pool.map().

    Returns a coord dict on success, None if the patch was rejected.
    Must be module-level (not a closure) for pickling to work.
    """
    (slide_path, x0, y0, w0, h0, level, patch_size, fmt, out_dir, idx) = args

    try:
        slide    = OpenSlide(slide_path)
        level_ds = slide.level_downsamples[level]
        # Convert level-0 region size to a read size at the chosen level
        pw = max(1, int(round(w0 / level_ds)))
        ph = max(1, int(round(h0 / level_ds)))

        # Never load the full WSI; read only the required region
        pil_patch = slide.read_region((x0, y0), level, (pw, ph))

        # OpenSlide returns RGBA, where fully transparent means "outside the
        # scanned area". convert("RGB") simply drops alpha, turning those pixels
        # BLACK — which both corrupts edge patches and trips the black-patch
        # filter. Composite onto white, which is what slide background is.
        if pil_patch.mode == "RGBA":
            background = Image.new("RGB", pil_patch.size, (255, 255, 255))
            background.paste(pil_patch, mask=pil_patch.split()[3])
            pil_patch = background
        else:
            pil_patch = pil_patch.convert("RGB")

        patch = np.array(pil_patch)
        slide.close()

        # Downscale to the requested output size. INTER_AREA is the correct
        # filter for shrinking (INTER_LINEAR aliases); INTER_CUBIC for the rare
        # case where the chosen level forces a small upscale.
        if patch.shape[0] != patch_size or patch.shape[1] != patch_size:
            interp = cv2.INTER_AREA if patch.shape[0] > patch_size else cv2.INTER_CUBIC
            patch = cv2.resize(patch, (patch_size, patch_size), interpolation=interp)

        # Quality filters
        if is_white_patch(patch):
            return None
        if is_black_patch(patch):
            return None
        if not has_tissue_center(patch):
            return None

        # Save
        ext      = "jpg" if fmt.lower() in ("jpg", "jpeg") else "png"
        filename = f"patch_{idx:07d}_x{x0}_y{y0}.{ext}"
        out_path = os.path.join(out_dir, filename)

        img_pil = Image.fromarray(patch)
        if ext == "jpg":
            img_pil.save(out_path, "JPEG", quality=95, optimize=True)
        else:
            img_pil.save(out_path, "PNG")

        return {"file": filename, "x": x0, "y": y0, "w": w0, "h": h0, "level": level}

    except Exception as exc:
        log.warning("Patch %d failed: %s", idx, exc)
        return None


# ═════════════════════════════════════════════════════════════════════════════
# Overview image
# ═════════════════════════════════════════════════════════════════════════════

def generate_overview(
    slide: OpenSlide,
    accepted_coords: List[dict],
    output_path: str,
    max_side: int = 2048,
) -> None:
    """Draw accepted patch locations on a downsampled overview image."""
    level   = slide.level_count - 1
    W, H    = slide.level_dimensions[level]
    thumb   = np.array(slide.get_thumbnail((W, H)))
    W0, H0  = slide.level_dimensions[0]
    sx, sy  = W / W0, H / H0

    for coord in accepted_coords:
        tx  = int(coord["x"] * sx)
        ty  = int(coord["y"] * sy)
        tw  = max(1, int(coord["w"] * sx))
        th  = max(1, int(coord["h"] * sy))
        cv2.rectangle(thumb, (tx, ty), (tx + tw, ty + th), (0, 220, 100), 1)

    # Scale down if too large
    h, w = thumb.shape[:2]
    if max(h, w) > max_side:
        scale = max_side / max(h, w)
        thumb = cv2.resize(thumb, (int(w * scale), int(h * scale)), interpolation=cv2.INTER_AREA)

    Image.fromarray(thumb).save(output_path, "PNG")
    log.info("Overview image saved: %s", output_path)


# ═════════════════════════════════════════════════════════════════════════════
# Coordinate persistence
# ═════════════════════════════════════════════════════════════════════════════

def save_coords(coords: List[dict], out_dir: str) -> str:
    """Save patch coordinates as CSV and return the file path."""
    csv_path = os.path.join(out_dir, "patch_coords.csv")
    with open(csv_path, "w", newline="") as fh:
        writer = csv.DictWriter(fh, fieldnames=["file", "x", "y", "w", "h", "level"])
        writer.writeheader()
        writer.writerows(coords)
    log.info("Coordinates saved: %s (%d rows)", csv_path, len(coords))
    return csv_path


# ═════════════════════════════════════════════════════════════════════════════
# Main pipeline
# ═════════════════════════════════════════════════════════════════════════════

def run(
    slide_path: str,
    output_dir: str,
    patch_size: int = 256,
    level: int = -1,
    overlap: int = 0,
    fmt: str = "png",
    tissue_threshold: float = 0.5,
    workers: int = 1,
    save_coords_flag: bool = False,
    overview_flag: bool = False,
    target_mpp: float = 0.5,
    allow_missing_mpp: bool = False,
    max_patches: int = 0,
    seed: int = 42,
    use_otsu: bool = True,
) -> dict:
    """
    Full patch extraction pipeline.

    Returns the summary dict that will be printed as JSON to stdout.
    """
    os.makedirs(output_dir, exist_ok=True)

    slide = OpenSlide(slide_path)
    W0, H0 = slide.level_dimensions[0]
    n_levels = slide.level_count
    base_mpp = read_base_mpp(slide)

    log.info(
        "Slide opened: %s | %dx%d | %d level(s) | vendor=%s | mpp=%s",
        os.path.basename(slide_path),
        W0, H0,
        n_levels,
        slide.properties.get("openslide.vendor", "unknown"),
        f"{base_mpp:.4f}" if base_mpp else "UNKNOWN",
    )

    # ── Resolve physical scale ──────────────────────────────────────────────
    # Extraction is driven by microns-per-pixel, not by a pyramid level. A fixed
    # level meant a 20x slide and a 40x slide produced patches covering different
    # amounts of tissue, and the foundation model saw two different scales.
    if base_mpp is None:
        if not allow_missing_mpp:
            slide.close()
            raise ValueError(
                "Slide does not declare openslide.mpp-x, so patches cannot be extracted at a "
                "known physical scale. Pass --allow_missing_mpp to fall back to level-0 pixels "
                "(the resulting patches will not be comparable with MPP-based ones)."
            )
        log.warning("No MPP in slide metadata — falling back to level-0 pixels (scale=1.0).")
        scale = 1.0
        effective_mpp = None
    else:
        scale = target_mpp / base_mpp
        effective_mpp = target_mpp

    # Read from the deepest level that does not require upsampling, then downscale.
    if level is None or level < 0:
        level = pick_level_for_scale(slide, scale)
    elif level >= n_levels:
        log.warning("Requested level %d >= level count %d; clamping to %d", level, n_levels, n_levels - 1)
        level = n_levels - 1

    log.info(
        "Scale: base_mpp=%s target_mpp=%.4f -> %.4f level-0 px per output px; "
        "reading level %d (downsample %.2f); patch covers %.1f um",
        f"{base_mpp:.4f}" if base_mpp else "n/a",
        target_mpp, scale, level, slide.level_downsamples[level],
        patch_size * (effective_mpp if effective_mpp else (base_mpp or 0.0)),
    )

    # ── 1. Tissue mask ──────────────────────────────────────────────────────
    mask, ds = build_tissue_mask(slide, base_mpp=base_mpp, use_otsu=use_otsu)

    # ── 2. Candidate grid ───────────────────────────────────────────────────
    candidates = generate_candidates(
        slide, mask, ds, patch_size, overlap, tissue_threshold, scale
    )
    slide.close()

    if not candidates:
        log.warning("No tissue candidates found. Output directory is empty.")
        return {
            "patches_extracted": 0,
            "patches_skipped":   0,
            "patch_size":        patch_size,
            "level":             level,
            "base_mpp":          base_mpp,
            "target_mpp":        effective_mpp,
            "slide_width":       W0,
            "slide_height":      H0,
            "output_dir":        output_dir,
            "coords_file":       None,
            "overview_file":     None,
        }

    # ── Cap the number of patches ───────────────────────────────────────────
    # A large 40x slide yields tens of thousands of candidates. Sampling here
    # bounds both extraction time and the per-slide bag the trainer must hold,
    # and it is seeded so the same slide always yields the same subset.
    n_candidates = len(candidates)
    if max_patches and n_candidates > max_patches:
        rng = random.Random(seed)
        candidates = sorted(rng.sample(candidates, max_patches))
        log.info("Capped candidates: %d -> %d (seed=%d)", n_candidates, max_patches, seed)

    # ── 3. Extract patches (parallel) ──────────────────────────────────────
    tasks = [
        (slide_path, x0, y0, w0, h0, level, patch_size, fmt, output_dir, idx)
        for idx, (x0, y0, w0, h0) in enumerate(candidates)
    ]

    log.info("Extracting %d candidates using %d worker(s)…", len(tasks), workers)

    if workers > 1:
        with Pool(processes=min(workers, cpu_count())) as pool:
            results = pool.map(_extract_worker, tasks)
    else:
        results = [_extract_worker(t) for t in tasks]

    accepted = [r for r in results if r is not None]
    skipped  = len(results) - len(accepted)

    log.info(
        "Done — extracted: %d | rejected: %d | total scanned: %d",
        len(accepted), skipped, len(tasks),
    )

    # ── 4. Coordinate file ──────────────────────────────────────────────────
    coords_file: Optional[str] = None
    if save_coords_flag and accepted:
        coords_file = save_coords(accepted, output_dir)

    # ── 5. Overview image ───────────────────────────────────────────────────
    overview_file: Optional[str] = None
    if overview_flag and accepted:
        overview_path = os.path.join(output_dir, "overview.png")
        slide2 = OpenSlide(slide_path)
        generate_overview(slide2, accepted, overview_path)
        slide2.close()
        overview_file = overview_path

    return {
        "patches_extracted": len(accepted),
        "patches_skipped":   skipped,
        "patch_size":        patch_size,
        "level":             level,
        # Physical scale is reported so the caller can store it and later verify
        # that every slide in a training run was extracted the same way.
        "base_mpp":          base_mpp,
        "target_mpp":        effective_mpp,
        "scale_l0_px_per_out_px": round(scale, 6),
        "candidates_total":  n_candidates,
        "max_patches":       max_patches or None,
        "slide_width":       W0,
        "slide_height":      H0,
        "output_dir":        output_dir,
        "coords_file":       coords_file,
        "overview_file":     overview_file,
    }


# ═════════════════════════════════════════════════════════════════════════════
# CLI
# ═════════════════════════════════════════════════════════════════════════════

def parse_args() -> argparse.Namespace:
    p = argparse.ArgumentParser(
        description="WSI patch extraction pipeline (OpenSlide + OpenCV).",
        formatter_class=argparse.ArgumentDefaultsHelpFormatter,
    )
    p.add_argument("--input",             required=True,  help="Path to the WSI file")
    p.add_argument("--output_dir",        required=True,  help="Directory to write patches into")
    p.add_argument("--patch_size",        type=int, default=256,  help="Output patch width = height in pixels")
    p.add_argument("--target_mpp",        type=float, default=0.5,
                   help="Microns per pixel of the OUTPUT patch. This, not --level, defines the "
                        "physical scale: 256 px at 0.5 mpp always covers 128 um, on any scanner.")
    p.add_argument("--level",             type=int, default=-1,
                   help="Pyramid level to read from. -1 (default) picks the deepest level that "
                        "needs no upsampling for --target_mpp. Setting this explicitly overrides "
                        "the automatic choice and reintroduces scale inconsistency across scanners.")
    p.add_argument("--allow_missing_mpp", action="store_true",
                   help="Extract even when the slide declares no MPP (falls back to level-0 "
                        "pixels). Off by default: such patches are not scale-comparable.")
    p.add_argument("--max_patches",       type=int, default=0,
                   help="Cap patches per slide (0 = no cap). Sampling is seeded, so the same "
                        "slide always yields the same subset.")
    p.add_argument("--seed",              type=int, default=42,   help="Seed for the patch-cap sampling")
    p.add_argument("--no_otsu",           action="store_true",
                   help="Use the fixed saturation threshold instead of per-slide Otsu")
    p.add_argument("--overlap",           type=int, default=0,    help="Overlap between adjacent patches (output pixels)")
    p.add_argument("--format",            default="png", choices=["png", "jpg", "jpeg"], help="Output image format")
    p.add_argument("--tissue_threshold",  type=float, default=0.5, help="Minimum tissue fraction (0-1) to accept a patch")
    p.add_argument("--workers",           type=int, default=1,    help="Number of parallel worker processes")
    p.add_argument("--save_coords",       action="store_true",    help="Save patch coordinates to CSV")
    p.add_argument("--overview",          action="store_true",    help="Generate overview PNG with patch locations")
    return p.parse_args()


def main() -> None:
    args = parse_args()

    if not os.path.isfile(args.input):
        print(json.dumps({"error": f"Input file not found: {args.input}"}))
        sys.exit(1)

    try:
        result = run(
            slide_path        = args.input,
            output_dir        = args.output_dir,
            patch_size        = args.patch_size,
            level             = args.level,
            overlap           = args.overlap,
            fmt               = args.format,
            tissue_threshold  = args.tissue_threshold,
            workers           = args.workers,
            save_coords_flag  = args.save_coords,
            overview_flag     = args.overview,
            target_mpp        = args.target_mpp,
            allow_missing_mpp = args.allow_missing_mpp,
            max_patches       = args.max_patches,
            seed              = args.seed,
            use_otsu          = not args.no_otsu,
        )
        print(json.dumps(result))
    except Exception as exc:
        log.exception("Unhandled exception")
        print(json.dumps({"error": str(exc)}))
        sys.exit(1)


if __name__ == "__main__":
    main()
