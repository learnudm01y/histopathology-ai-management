#!/usr/bin/env python3
"""
WSI Preview Generator & Deep Inspection Script.

Runs all three critical health checks AND generates a thumbnail:

  open_slide_status      — can OpenSlide open the file without error?
  file_integrity_status  — are dimensions / metadata intact and readable?
  read_test_status       — can we read random + corner regions without failure?
  checksum_md5           — MD5 hash of the raw file bytes (streaming, memory-safe)

Also collects: level_count, slide_width/height, mpp_x/y, magnification_power,
               tissue_area_percent, background_ratio.

Usage:
    python wsi_preview_generate.py <wsi_path> <output_dir>

Outputs:
    <output_dir>/thumbnail.jpg   preview image, max 2048 px on longest side
    <output_dir>/results.json    all check results (also written to stdout)

Read-test strategy
──────────────────
A superficial "read one tile" test misses many real-world corruption modes
(e.g. truncated pyramid levels, bad tiles in specific regions).
We therefore sample systematically:

  Level 0 (full resolution):
    • 4 corners (inward by tile_size to avoid OOB padding artefacts)
    • exact centre
    • 10 random positions (seed=42 for reproducibility)

  Every additional level:
    • centre (coordinates converted back to level-0 space as required by
      OpenSlide's read_region API)

Any single read failure immediately marks both read_test_status AND
file_integrity_status as 'failed'.

MD5 / duplicate detection
──────────────────────────
The PHP controller compares the returned MD5 against other slides.
  • No match   → stores the hash in checksum_md5  (check PASSES – 'present')
  • Duplicate  → PHP sets checksum_md5 = null     (check FAILS  – 'present'=null)

Exit codes:
    0  Success (results written to output_dir/results.json)
    1  OpenSlide / Pillow / NumPy not installed
    2  File not found
"""

from __future__ import annotations

import hashlib
import json
import os
import random
import sys
from pathlib import Path
from typing import Any

# ---------------------------------------------------------------------------
# Default result template — fields are filled in progressively.
# ---------------------------------------------------------------------------
result: dict[str, Any] = {
    "open_slide_status":     "not_checked",
    "file_integrity_status": "not_checked",
    "read_test_status":      "not_checked",
    "checksum_md5":          None,
    "level_count":           None,
    "slide_width":           None,
    "slide_height":          None,
    "mpp_x":                 None,
    "mpp_y":                 None,
    "magnification_power":   None,
    "tissue_area_percent":   None,
    "tissue_patch_count":    None,
    "background_ratio":      None,
    "artifact_score":        None,
    "blur_score":            None,
    "thumbnail_path":        None,
    "error":                 None,
}


def _save_and_exit(code: int, output_dir: str) -> None:
    """Persist results.json and print to stdout, then exit."""
    out = Path(output_dir) / "results.json"
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps(result, ensure_ascii=False))
    sys.exit(code)


# ---------------------------------------------------------------------------
# MD5 — computed by streaming in 8 MB chunks to avoid loading GB into RAM.
# ---------------------------------------------------------------------------
def _compute_md5(path: str) -> str:
    h = hashlib.md5()
    with open(path, "rb") as fh:
        for chunk in iter(lambda: fh.read(8_388_608), b""):
            h.update(chunk)
    return h.hexdigest()


# ---------------------------------------------------------------------------
# Main pipeline
# ---------------------------------------------------------------------------
def main() -> None:
    if len(sys.argv) < 3:
        result["error"] = "usage: wsi_preview_generate.py <wsi_path> <output_dir>"
        _save_and_exit(2, sys.argv[2] if len(sys.argv) > 2 else "/tmp")

    wsi_path   = sys.argv[1]
    output_dir = sys.argv[2]

    # ── File existence ───────────────────────────────────────────────────────
    if not os.path.isfile(wsi_path):
        result["error"]                 = f"file not found: {wsi_path}"
        result["open_slide_status"]     = "failed"
        result["file_integrity_status"] = "failed"
        result["read_test_status"]      = "failed"
        _save_and_exit(2, output_dir)

    # ── MD5 (streaming) ──────────────────────────────────────────────────────
    try:
        result["checksum_md5"] = _compute_md5(wsi_path)
    except Exception as exc:
        result["error"] = f"MD5 computation failed: {exc}"
        # Not fatal — continue with the rest of the checks.

    # ── Import dependencies ──────────────────────────────────────────────────
    try:
        import openslide
        from openslide import OpenSlide
    except Exception as exc:
        result["error"] = f"openslide-python not installed: {exc}"
        _save_and_exit(1, output_dir)

    # ── Open the slide ───────────────────────────────────────────────────────
    try:
        slide = OpenSlide(wsi_path)
    except Exception as exc:
        result["open_slide_status"]     = "failed"
        result["file_integrity_status"] = "failed"
        result["read_test_status"]      = "failed"
        result["error"]                 = f"OpenSlide could not open the file: {exc}"
        _save_and_exit(0, output_dir)

    result["open_slide_status"] = "passed"

    # ── Dimensions & pyramid levels ──────────────────────────────────────────
    try:
        result["level_count"] = int(slide.level_count)
        w0, h0 = slide.level_dimensions[0]
        result["slide_width"]  = int(w0)
        result["slide_height"] = int(h0)
    except Exception as exc:
        result["file_integrity_status"] = "failed"
        result["error"] = f"failed to read pyramid dimensions: {exc}"
        slide.close()
        _save_and_exit(0, output_dir)

    # ── MPP & objective magnification (best-effort) ──────────────────────────
    props = slide.properties
    try:
        mpp_x = props.get(openslide.PROPERTY_NAME_MPP_X)
        mpp_y = props.get(openslide.PROPERTY_NAME_MPP_Y)
        if mpp_x is not None:
            result["mpp_x"] = float(mpp_x)
        if mpp_y is not None:
            result["mpp_y"] = float(mpp_y)

        obj = (props.get(openslide.PROPERTY_NAME_OBJECTIVE_POWER)
               or props.get("aperio.AppMag"))
        if obj is not None:
            result["magnification_power"] = float(obj)
    except Exception:
        pass  # non-fatal

    # ── Enhanced read test ───────────────────────────────────────────────────
    #
    # Goal: catch truncated files, bad tiles, and partial corruption that a
    # simple "open + read one tile" test would miss.
    #
    # All read_region calls use LEVEL-0 COORDINATES (x, y) even when reading
    # from a higher pyramid level — that is the OpenSlide API contract.
    #
    TILE = 256
    w0   = result["slide_width"]
    h0   = result["slide_height"]

    def _read(level: int, x0: int, y0: int) -> None:
        """Read a TILE×TILE region at (x0,y0) in level-0 coords from `level`."""
        tile = slide.read_region((x0, y0), level, (TILE, TILE))
        tile.load()   # force decompression — catches deferred I/O errors
        tile.close()

    try:
        # 1. Level-0 corners (clamped so region stays within slide bounds)
        corners = [
            (0,                        0),
            (max(0, w0 - TILE),        0),
            (0,                        max(0, h0 - TILE)),
            (max(0, w0 - TILE),        max(0, h0 - TILE)),
        ]
        for cx, cy in corners:
            _read(0, cx, cy)

        # 2. Level-0 centre
        _read(0, max(0, w0 // 2 - TILE // 2), max(0, h0 // 2 - TILE // 2))

        # 3. Ten reproducible random positions at level 0
        rng = random.Random(42)
        for _ in range(10):
            rx = rng.randint(0, max(0, w0 - TILE))
            ry = rng.randint(0, max(0, h0 - TILE))
            _read(0, rx, ry)

        # 4. Centre of every additional pyramid level
        for lvl in range(1, slide.level_count):
            lw, lh = slide.level_dimensions[lvl]
            ds = slide.level_downsamples[lvl]
            # Centre in level-lvl pixels → convert to level-0 coordinates
            cx0 = int((lw // 2) * ds)
            cy0 = int((lh // 2) * ds)
            _read(lvl, cx0, cy0)

        result["read_test_status"]      = "passed"
        result["file_integrity_status"] = "passed"

    except Exception as exc:
        result["read_test_status"]      = "failed"
        result["file_integrity_status"] = "failed"
        result["error"]                 = f"read test failed: {exc}"
        slide.close()
        _save_and_exit(0, output_dir)

    # ── Thumbnail generation ─────────────────────────────────────────────────
    try:
        from PIL import Image as _PIL_Image

        MAX_DIM = 4096  # high-quality thumbnail — long edge ≤ 4096 px

        w0_full = result["slide_width"]
        h0_full = result["slide_height"]

        # Find the smallest pyramid level that is still ≥ MAX_DIM on any
        # side (or level 0 if every level is smaller than MAX_DIM).
        # We read from that level and downsample with Lanczos — this avoids
        # decompressing the full-resolution scan (which can be GBs in RAM)
        # while still producing a much sharper result than using level 0.
        best_level = 0
        for lvl in range(slide.level_count - 1, -1, -1):
            lw, lh = slide.level_dimensions[lvl]
            if max(lw, lh) >= MAX_DIM:
                # Safety: skip levels whose uncompressed RGB would exceed
                # ~600 MB (200 MP × 3 channels)
                if lw * lh <= 200_000_000:
                    best_level = lvl
                break

        lw_lvl, lh_lvl = slide.level_dimensions[best_level]
        region = slide.read_region((0, 0), best_level, (lw_lvl, lh_lvl))
        img    = region.convert("RGB")

        # Scale down to MAX_DIM if needed, using Lanczos for best sharpness
        ratio = min(MAX_DIM / lw_lvl, MAX_DIM / lh_lvl, 1.0)
        if ratio < 1.0:
            tw = max(1, int(round(lw_lvl * ratio)))
            th = max(1, int(round(lh_lvl * ratio)))
            img = img.resize((tw, th), _PIL_Image.LANCZOS)

        thumb_path = str(Path(output_dir) / "thumbnail.jpg")
        # quality=95 + subsampling=0 preserves colour fidelity of stains
        img.save(thumb_path, "JPEG", quality=95, subsampling=0)
        result["thumbnail_path"] = thumb_path
    except Exception as exc:
        # Thumbnail failure is non-fatal — the verification checks still pass.
        prev = result.get("error") or ""
        result["error"] = (prev + f"; thumbnail failed: {exc}").lstrip("; ")

    # ── Tissue / background estimation (Otsu on low-res level) ──────────────
    try:
        import numpy as np

        # Pick the highest-detail level whose largest dimension is ≤ 2048.
        target_lvl = slide.level_count - 1
        for lvl in range(slide.level_count):
            lw, lh = slide.level_dimensions[lvl]
            if max(lw, lh) <= 2048:
                target_lvl = lvl
                break

        lw, lh = slide.level_dimensions[target_lvl]
        gray   = slide.read_region((0, 0), target_lvl, (lw, lh)).convert("L")
        arr    = np.asarray(gray, dtype=np.uint8)

        # Manual Otsu threshold (avoids scikit-image dependency)
        hist        = np.bincount(arr.flatten(), minlength=256).astype(np.float64)
        total       = hist.sum()
        sum_total   = (np.arange(256) * hist).sum()
        sum_b = w_b = max_var = 0.0
        threshold   = 127
        for t in range(256):
            w_b += hist[t]
            if w_b == 0:
                continue
            w_f = total - w_b
            if w_f == 0:
                break
            sum_b += t * hist[t]
            m_b = sum_b / w_b
            m_f = (sum_total - sum_b) / w_f
            var = w_b * w_f * (m_b - m_f) ** 2
            if var > max_var:
                max_var   = var
                threshold = t

        tissue_px  = int((arr < threshold).sum())
        tissue_pct = (tissue_px / total) * 100.0
        result["tissue_area_percent"] = round(tissue_pct, 2)
        result["background_ratio"]    = round(1.0 - (tissue_pct / 100.0), 4)

        # ── Advanced tissue quality metrics ──────────────────────────────────
        # tissue_patch_count, blur_score, artifact_score
        # All computed best-effort — failures do NOT abort the verification.
        try:
            PATCH_SIZE     = 256   # logical patch size in level-0 pixels
            MIN_TISSUE_FRAC = 0.20  # patch must have ≥20% tissue to count

            # Corresponding patch size at the analysis level
            ds    = float(slide.level_downsamples[target_lvl])
            P_LVL = max(8, int(round(PATCH_SIZE / ds)))
            nh_p  = lh // P_LVL
            nw_p  = lw // P_LVL

            tissue_fracs = None
            if nh_p > 0 and nw_p > 0:
                arr_c = arr[:nh_p * P_LVL, :nw_p * P_LVL]
                pats  = arr_c.reshape(nh_p, P_LVL, nw_p, P_LVL)
                tissue_fracs = (pats < threshold).mean(axis=(1, 3))  # (nh_p, nw_p)

                # ── 1. tissue_patch_count ─────────────────────────────────────
                # Estimate # of 256×256 tissue patches at full resolution.
                # fraction_tissue × (full_res_W//256) × (full_res_H//256)
                frac_tissue  = float((tissue_fracs >= MIN_TISSUE_FRAC).mean())
                full_nx      = max(1, result["slide_width"]  // PATCH_SIZE)
                full_ny      = max(1, result["slide_height"] // PATCH_SIZE)
                result["tissue_patch_count"] = int(frac_tissue * full_nx * full_ny)

            # ── 2. blur_score ─────────────────────────────────────────────────
            # Read several 512×512 regions at level 0 from tissue areas and
            # compute Laplacian variance (higher = sharper).
            # Mapped to [0, 1] where 0 = sharp, 1 = very blurry.
            W0, H0 = result["slide_width"], result["slide_height"]
            sample_regions = []
            # Always sample the centre
            sample_regions.append((
                max(0, W0 // 2 - 256), max(0, H0 // 2 - 256)
            ))
            # Add a few more positions spread across the slide
            for fx, fy in [(0.25, 0.25), (0.75, 0.25), (0.25, 0.75), (0.75, 0.75),
                           (0.50, 0.25), (0.50, 0.75), (0.25, 0.50), (0.75, 0.50)]:
                sample_regions.append((
                    max(0, min(int(fx * W0) - 256, W0 - 512)),
                    max(0, min(int(fy * H0) - 256, H0 - 512)),
                ))

            lap_vars = []
            for sx, sy in sample_regions[:8]:  # max 8 samples
                try:
                    tile = slide.read_region((sx, sy), 0, (512, 512)).convert("L")
                    ta   = np.asarray(tile, dtype=np.float32)
                    # Discrete Laplacian: 4-connected
                    lap = (
                        -4.0 * ta[1:-1, 1:-1]
                        + ta[:-2, 1:-1] + ta[2:, 1:-1]
                        + ta[1:-1, :-2] + ta[1:-1, 2:]
                    )
                    lap_vars.append(float(np.var(lap)))
                except Exception:
                    pass

            if lap_vars:
                mean_var = float(np.mean(lap_vars))
                # Sharp H&E slides typically: var ≈ 500–8000
                # Blurry: var < 100; excellent: var > 5000
                # Mapping: blur_score = 1 / (1 + mean_var / 300)
                result["blur_score"] = round(1.0 / (1.0 + mean_var / 300.0), 4)

            # ── 3. artifact_score ─────────────────────────────────────────────
            # Detect pen marks (high saturation, non-H&E hue) and
            # tissue folds (very dark regions within tissue).
            # artifact_score = fraction of tissue pixels flagged as artefact.
            region_rgb = slide.read_region((0, 0), target_lvl, (lw, lh)).convert("RGB")
            arr_rgb    = np.asarray(region_rgb, dtype=np.uint8).astype(np.float32) / 255.0
            R_ch = arr_rgb[:, :, 0]
            G_ch = arr_rgb[:, :, 1]
            B_ch = arr_rgb[:, :, 2]

            Cmax  = np.maximum(np.maximum(R_ch, G_ch), B_ch)
            Cmin  = np.minimum(np.minimum(R_ch, G_ch), B_ch)
            delta = Cmax - Cmin
            eps   = 1e-8

            # Saturation (HSV)
            S_ch = np.where(Cmax > eps, delta / (Cmax + eps), 0.0)
            # Value
            V_ch = Cmax
            # Hue (0–360°)
            H_ch = np.zeros_like(R_ch)
            mr = (Cmax == R_ch) & (delta > eps)
            mg = (Cmax == G_ch) & (delta > eps)
            mb = (Cmax == B_ch) & (delta > eps)
            H_ch[mr] = (60.0 * ((G_ch[mr] - B_ch[mr]) / (delta[mr] + eps))) % 360.0
            H_ch[mg] =  60.0 * ((B_ch[mg] - R_ch[mg]) / (delta[mg] + eps)) + 120.0
            H_ch[mb] =  60.0 * ((R_ch[mb] - G_ch[mb]) / (delta[mb] + eps)) + 240.0

            tissue_mask = arr < threshold  # True = tissue pixel
            tissue_total = float(tissue_mask.sum())

            # H&E hue ranges: pink/magenta (eosin) ≈ 300–360° & 0–30°
            #                 purple/blue (hematoxylin)              ≈ 200–270°
            he_hue_mask = ((H_ch >= 300) | (H_ch <= 30) |
                           ((H_ch >= 200) & (H_ch <= 270)))

            # Pen marks: highly saturated non-H&E colour within tissue
            pen_marks     = tissue_mask & (S_ch > 0.45) & ~he_hue_mask
            # Folds / haemorrhage: very dark tissue (V < 0.12)
            dark_artifacts = tissue_mask & (V_ch < 0.12)

            artifact_px = float((pen_marks | dark_artifacts).sum())
            result["artifact_score"] = round(
                artifact_px / (tissue_total + 1.0), 4
            )

        except Exception as exc_qm:
            prev = result.get("error") or ""
            result["error"] = (prev + f"; quality metrics failed: {exc_qm}").lstrip("; ")

    except Exception:
        pass  # non-fatal

    slide.close()
    _save_and_exit(0, output_dir)


if __name__ == "__main__":
    main()
