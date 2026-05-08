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
        --level          0 \
        --overlap        0 \
        --format         png \
        --tissue_threshold 0.5 \
        --workers        4 \
        --save_coords \
        --overview

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


# ═════════════════════════════════════════════════════════════════════════════
# Tissue detection
# ═════════════════════════════════════════════════════════════════════════════

def build_tissue_mask(
    slide: OpenSlide,
    thumb_level: int = -1,
    sat_threshold: int = 20,
    blur_ksize: int = 7,
    morph_ksize: int = 15,
    min_contour_area: float = 500.0,
) -> Tuple[np.ndarray, float]:
    """
    Return a binary tissue mask at thumbnail resolution and the
    downsampling factor (level0_pixels / thumbnail_pixels).

    Pipeline:
        RGB → HSV → saturation channel → median blur → Otsu/threshold
        → morphological closing → contour filtering
    """
    if thumb_level < 0:
        thumb_level = slide.level_count - 1

    thumb_size = slide.level_dimensions[thumb_level]
    thumb_img  = np.array(slide.get_thumbnail(thumb_size))

    # Downsampling factor from level-0 to thumbnail
    ds_x = slide.level_dimensions[0][0] / thumb_size[0]
    ds_y = slide.level_dimensions[0][1] / thumb_size[1]
    ds   = (ds_x + ds_y) / 2.0

    # RGB → HSV, use saturation
    hsv = cv2.cvtColor(thumb_img, cv2.COLOR_RGB2HSV)
    sat = hsv[:, :, 1]

    # Median blur to remove noise
    if blur_ksize % 2 == 0:
        blur_ksize += 1
    blurred = cv2.medianBlur(sat, blur_ksize)

    # Threshold: use Otsu if tissue_threshold==0, else fixed
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
        "Tissue mask built at level %d (%dx%d), downsample=%.1f, "
        "tissue pixels=%d / total=%d (%.1f%%)",
        thumb_level,
        thumb_size[0], thumb_size[1],
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
    level: int,
    overlap: int,
    tissue_threshold: float,
) -> List[Coord]:
    """
    Slide a grid over the slide at the given level and return coordinates
    (at level-0) of patches whose tissue mask coverage exceeds the threshold.
    """
    level_ds      = slide.level_downsamples[level]
    # Effective stride at level-0
    stride        = patch_size - overlap                  # patch pixels at requested level
    stride_l0     = int(stride  * level_ds)
    patch_size_l0 = int(patch_size * level_ds)

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

    log.info("Grid generated: %d candidate patches (tissue≥%.0f%%)", len(candidates), tissue_threshold * 100)
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
        # Convert level-0 coordinates to level-N size
        pw = max(1, int(round(w0 / level_ds)))
        ph = max(1, int(round(h0 / level_ds)))

        # Never load the full WSI; read only the required region
        pil_patch = slide.read_region((x0, y0), level, (pw, ph))
        patch     = np.array(pil_patch.convert("RGB"))
        slide.close()

        # Resize to exact patch_size if necessary (rounding artefacts)
        if patch.shape[0] != patch_size or patch.shape[1] != patch_size:
            patch = cv2.resize(patch, (patch_size, patch_size), interpolation=cv2.INTER_LINEAR)

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
    level: int = 0,
    overlap: int = 0,
    fmt: str = "png",
    tissue_threshold: float = 0.5,
    workers: int = 1,
    save_coords_flag: bool = False,
    overview_flag: bool = False,
) -> dict:
    """
    Full patch extraction pipeline.

    Returns the summary dict that will be printed as JSON to stdout.
    """
    os.makedirs(output_dir, exist_ok=True)

    slide = OpenSlide(slide_path)
    W0, H0 = slide.level_dimensions[0]
    n_levels = slide.level_count

    log.info(
        "Slide opened: %s | %dx%d | %d level(s) | vendor=%s",
        os.path.basename(slide_path),
        W0, H0,
        n_levels,
        slide.properties.get("openslide.vendor", "unknown"),
    )

    # Clamp requested level
    if level >= n_levels:
        log.warning("Requested level %d >= level count %d; clamping to %d", level, n_levels, n_levels - 1)
        level = n_levels - 1

    # ── 1. Tissue mask ──────────────────────────────────────────────────────
    mask, ds = build_tissue_mask(slide)

    # ── 2. Candidate grid ───────────────────────────────────────────────────
    candidates = generate_candidates(
        slide, mask, ds, patch_size, level, overlap, tissue_threshold
    )
    slide.close()

    if not candidates:
        log.warning("No tissue candidates found. Output directory is empty.")
        return {
            "patches_extracted": 0,
            "patches_skipped":   0,
            "patch_size":        patch_size,
            "level":             level,
            "slide_width":       W0,
            "slide_height":      H0,
            "output_dir":        output_dir,
            "coords_file":       None,
            "overview_file":     None,
        }

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
    p.add_argument("--patch_size",        type=int, default=256,  help="Patch width = height (pixels at requested level)")
    p.add_argument("--level",             type=int, default=0,    help="OpenSlide pyramid level (0 = highest resolution)")
    p.add_argument("--overlap",           type=int, default=0,    help="Overlap between adjacent patches (pixels)")
    p.add_argument("--format",            default="png", choices=["png", "jpg", "jpeg"], help="Output image format")
    p.add_argument("--tissue_threshold",  type=float, default=0.5, help="Minimum tissue fraction (0‒1) to accept a patch")
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
        )
        print(json.dumps(result))
    except Exception as exc:
        log.exception("Unhandled exception")
        print(json.dumps({"error": str(exc)}))
        sys.exit(1)


if __name__ == "__main__":
    main()
