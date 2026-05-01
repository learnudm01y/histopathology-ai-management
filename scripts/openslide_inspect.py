#!/usr/bin/env python3
"""
WSI inspection helper for the Laravel slide-verification pipeline.

Reads a single WSI file (SVS / NDPI / TIFF / MRXS / SCN / VSI / etc.) using
OpenSlide and prints a JSON summary that the PHP SlideVerificationService
consumes to populate WSI-only verification fields:

  - open_slide_status        ('passed' | 'failed')
  - file_integrity_status    ('passed' | 'failed')
  - read_test_status         ('passed' | 'failed')
  - level_count              (int)
  - slide_width / slide_height (int, level-0 dimensions)
  - mpp_x / mpp_y            (float, microns per pixel)
  - magnification_power      (float, objective power, e.g. 20.0 / 40.0)
  - tissue_area_percent      (float, rough Otsu-based estimate at level 2+)
  - background_ratio         (float, 1 - tissue_area_percent / 100)

Usage:
    python openslide_inspect.py <path-to-wsi-file>

Exit codes:
    0  Success — JSON written to stdout.
    1  OpenSlide / Pillow not installed — JSON error written to stdout.
    2  File not found / cannot be opened — JSON with open_slide_status=failed.

Install requirements (one-time, Windows):
    1. Download OpenSlide binaries: https://openslide.org/download/
       Add the bin/ folder to PATH.
    2. pip install openslide-python pillow numpy

The script is intentionally defensive: any failure is reported in JSON
rather than raised, so the PHP caller can surface the result cleanly.
"""

from __future__ import annotations

import json
import os
import sys
from typing import Any

# Default response — overwritten as we go.
result: dict[str, Any] = {
    "open_slide_status": "not_checked",
    "file_integrity_status": "not_checked",
    "read_test_status": "not_checked",
    "level_count": None,
    "slide_width": None,
    "slide_height": None,
    "mpp_x": None,
    "mpp_y": None,
    "magnification_power": None,
    "tissue_area_percent": None,
    "background_ratio": None,
    "tissue_patch_count": None,
    "blur_score": None,
    "artifact_score": None,
    "stain_raw": None,
    "stain_normalized": None,
    "scanner_vendor": None,
    "scanner_model": None,
    "error": None,
}


def _emit(code: int) -> None:
    print(json.dumps(result, ensure_ascii=False))
    sys.exit(code)


def main() -> None:
    if len(sys.argv) < 2:
        result["error"] = "usage: openslide_inspect.py <path-to-wsi-file>"
        _emit(2)

    wsi_path = sys.argv[1]
    if not os.path.isfile(wsi_path):
        result["error"] = f"file not found: {wsi_path}"
        result["open_slide_status"] = "failed"
        result["file_integrity_status"] = "failed"
        result["read_test_status"] = "failed"
        _emit(2)

    # Lazy import — keeps the script runnable even without dependencies
    # (in which case we exit cleanly with a clear error).
    try:
        import openslide
        from openslide import OpenSlide
    except Exception as exc:  # pragma: no cover - environment specific
        result["error"] = f"openslide-python not installed: {exc}"
        _emit(1)

    # ── Open the slide ──────────────────────────────────────────────────
    try:
        slide = OpenSlide(wsi_path)
    except Exception as exc:
        result["open_slide_status"] = "failed"
        result["file_integrity_status"] = "failed"
        result["read_test_status"] = "failed"
        result["error"] = f"OpenSlide could not open the file: {exc}"
        _emit(0)

    result["open_slide_status"] = "passed"

    # ── Pyramid + dimensions ────────────────────────────────────────────
    try:
        result["level_count"] = int(slide.level_count)
        w, h = slide.level_dimensions[0]
        result["slide_width"] = int(w)
        result["slide_height"] = int(h)
    except Exception as exc:
        result["file_integrity_status"] = "failed"
        result["error"] = f"failed to read dimensions: {exc}"
        slide.close()
        _emit(0)

    # ── MPP + magnification ─────────────────────────────────────────────
    props = slide.properties
    try:
        mpp_x = props.get(openslide.PROPERTY_NAME_MPP_X)
        mpp_y = props.get(openslide.PROPERTY_NAME_MPP_Y)
        if mpp_x is not None:
            result["mpp_x"] = float(mpp_x)
        if mpp_y is not None:
            result["mpp_y"] = float(mpp_y)

        obj = props.get(openslide.PROPERTY_NAME_OBJECTIVE_POWER) \
            or props.get("aperio.AppMag")
        if obj is not None:
            result["magnification_power"] = float(obj)
    except Exception:
        # MPP / magnification are nice-to-have — never fatal.
        pass

    # ── Read test: sample a region from each level ──────────────────────
    try:
        levels_to_test = list(range(min(result["level_count"], 3)))
        for lvl in levels_to_test:
            lw, lh = slide.level_dimensions[lvl]
            # Read a small 256×256 patch from the centre of the level.
            x = max(0, (lw // 2) - 128)
            y = max(0, (lh // 2) - 128)
            tile = slide.read_region((x, y), lvl, (256, 256))
            tile.load()
            tile.close()
        result["read_test_status"] = "passed"
        result["file_integrity_status"] = "passed"
    except Exception as exc:
        result["read_test_status"] = "failed"
        result["file_integrity_status"] = "failed"
        result["error"] = f"failed during read test: {exc}"
        slide.close()
        _emit(0)

    # ── Rough tissue / background estimation (Otsu on a low-res thumb) ──
    try:
        import numpy as np
        from PIL import Image  # noqa: F401  (used through OpenSlide thumbs)

        # Pick a level whose largest dimension is ≤ 2048 for fast analysis.
        target_lvl = result["level_count"] - 1
        for lvl in range(result["level_count"]):
            lw, lh = slide.level_dimensions[lvl]
            if max(lw, lh) <= 2048:
                target_lvl = lvl
                break

        lw, lh = slide.level_dimensions[target_lvl]
        thumb = slide.read_region((0, 0), target_lvl, (lw, lh)).convert("L")
        arr = np.asarray(thumb, dtype=np.uint8)

        # Otsu threshold (manual, to avoid scikit-image dependency).
        hist = np.bincount(arr.flatten(), minlength=256).astype(np.float64)
        total = hist.sum()
        if total > 0:
            sum_total = (np.arange(256) * hist).sum()
            sum_b = 0.0
            w_b = 0.0
            max_var = 0.0
            threshold = 127
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
                    max_var = var
                    threshold = t

            tissue_pixels = int((arr < threshold).sum())
            tissue_pct = (tissue_pixels / total) * 100.0
            result["tissue_area_percent"] = round(tissue_pct, 2)
            result["background_ratio"] = round(1.0 - (tissue_pct / 100.0), 4)

            # ── Advanced tissue quality metrics ───────────────────────────────
            # tissue_patch_count, blur_score, artifact_score
            # All computed best-effort — any failure leaves the value as None.
            try:
                PATCH_SIZE      = 256
                MIN_TISSUE_FRAC = 0.20
                ds    = float(slide.level_downsamples[target_lvl])
                P_LVL = max(8, int(round(PATCH_SIZE / ds)))
                nh_p  = lh // P_LVL
                nw_p  = lw // P_LVL

                tissue_fracs = None
                if nh_p > 0 and nw_p > 0:
                    arr_c = arr[:nh_p * P_LVL, :nw_p * P_LVL]
                    pats  = arr_c.reshape(nh_p, P_LVL, nw_p, P_LVL)
                    tissue_fracs = (pats < threshold).mean(axis=(1, 3))

                    # ── 1. tissue_patch_count ─────────────────────────────────
                    frac_tissue = float((tissue_fracs >= MIN_TISSUE_FRAC).mean())
                    full_nx     = max(1, result["slide_width"]  // PATCH_SIZE)
                    full_ny     = max(1, result["slide_height"] // PATCH_SIZE)
                    result["tissue_patch_count"] = int(frac_tissue * full_nx * full_ny)

                # ── 2. blur_score ─────────────────────────────────────────────
                # Laplacian variance on tissue regions; 0 = sharp, 1 = blurry.
                W0, H0 = result["slide_width"], result["slide_height"]
                tissue_positions: list = []
                if tissue_fracs is not None:
                    tissue_idx = np.argwhere(tissue_fracs >= MIN_TISSUE_FRAC)
                    if len(tissue_idx) > 0:
                        rng = np.random.default_rng(seed=42)
                        chosen = tissue_idx if len(tissue_idx) <= 12 else tissue_idx[
                            rng.choice(len(tissue_idx), size=12, replace=False)]
                        for pi, pj in chosen:
                            cx0 = int((pj + 0.5) * P_LVL * ds)
                            cy0 = int((pi + 0.5) * P_LVL * ds)
                            sx  = int(max(0, min(cx0 - 256, W0 - 512)))
                            sy  = int(max(0, min(cy0 - 256, H0 - 512)))
                            tissue_positions.append((sx, sy))
                if not tissue_positions:
                    tissue_positions = [(max(0, W0 // 2 - 256), max(0, H0 // 2 - 256))]

                lap_vars: list = []
                for sx, sy in tissue_positions[:10]:
                    try:
                        tile = slide.read_region((sx, sy), 0, (512, 512)).convert("L")
                        ta   = np.asarray(tile, dtype=np.float32)
                        lap  = (
                            -4.0 * ta[1:-1, 1:-1]
                            + ta[:-2, 1:-1] + ta[2:, 1:-1]
                            + ta[1:-1, :-2] + ta[1:-1, 2:]
                        )
                        lap_vars.append(float(np.var(lap)))
                    except Exception:
                        pass

                if lap_vars:
                    mean_var = float(np.mean(lap_vars))
                    # 0 = sharp, 1 = blurry; threshold in model is <= 0.65.
                    # Sharp 20x H&E:  var >= 200  → score <= 0.60
                    # Blurry slide:   var <  100  → score >= 0.75
                    result["blur_score"] = round(1.0 / (1.0 + mean_var / 300.0), 4)

                # ── 3. artifact_score ─────────────────────────────────────────
                # Detect pen marks (high saturation, non-H&E hue) and very dark
                # folds. artifact_score = fraction of tissue pixels flagged.
                region_rgb = slide.read_region((0, 0), target_lvl, (lw, lh)).convert("RGB")
                arr_rgb    = np.asarray(region_rgb, dtype=np.uint8).astype(np.float32) / 255.0
                R_ch = arr_rgb[:, :, 0]
                G_ch = arr_rgb[:, :, 1]
                B_ch = arr_rgb[:, :, 2]
                Cmax  = np.maximum(np.maximum(R_ch, G_ch), B_ch)
                Cmin  = np.minimum(np.minimum(R_ch, G_ch), B_ch)
                delta = Cmax - Cmin
                eps   = 1e-8
                S_ch  = np.where(Cmax > eps, delta / (Cmax + eps), 0.0)
                V_ch  = Cmax
                H_ch  = np.zeros_like(R_ch)
                mr = (Cmax == R_ch) & (delta > eps)
                mg = (Cmax == G_ch) & (delta > eps)
                mb = (Cmax == B_ch) & (delta > eps)
                H_ch[mr] = (60.0 * ((G_ch[mr] - B_ch[mr]) / (delta[mr] + eps))) % 360.0
                H_ch[mg] =  60.0 * ((B_ch[mg] - R_ch[mg]) / (delta[mg] + eps)) + 120.0
                H_ch[mb] =  60.0 * ((R_ch[mb] - G_ch[mb]) / (delta[mb] + eps)) + 240.0
                tissue_mask  = arr < threshold
                tissue_total = float(tissue_mask.sum())
                he_hue_mask  = ((H_ch >= 300) | (H_ch <= 30) |
                                ((H_ch >= 200) & (H_ch <= 270)))
                pen_marks      = tissue_mask & (S_ch > 0.45) & ~he_hue_mask
                dark_artifacts = tissue_mask & (V_ch < 0.12)
                artifact_px    = float((pen_marks | dark_artifacts).sum())
                result["artifact_score"] = round(
                    artifact_px / (tissue_total + 1.0), 4
                )
            except Exception as exc_qm:
                prev = result.get("error") or ""
                result["error"] = (prev + f"; quality metrics failed: {exc_qm}").lstrip("; ")

    except Exception:
        # Non-fatal: leave as None so the PHP layer treats it as not_checked.
        pass

    # ── Stain & scanner metadata ────────────────────────────────────────
    try:
        desc = (
            props.get("aperio.ImageDescription", "")
            or props.get("openslide.comment", "")
            or ""
        )

        # Aperio SVS: |Stain=Hematoxylin and Eosin| or |Stain=IHC|
        stain_raw: str | None = None
        import re
        m = re.search(r"[|;]\s*Stain\s*=\s*([^|;\r\n]+)", desc, re.IGNORECASE)
        if m:
            stain_raw = m.group(1).strip()
        else:
            # Fallback: check other known property keys
            for key in ("tiff.Software", "leica.staining", "hamamatsu.SourceLens"):
                val = props.get(key)
                if val and any(s in val.lower() for s in ("hematoxylin", "eosin", "h&e", "ihc", "stain")):
                    stain_raw = val.strip()
                    break

        if stain_raw:
            result["stain_raw"] = stain_raw
            low = stain_raw.lower()
            if any(k in low for k in ("hematoxylin", "eosin", "h&e", "h & e", "he ", "h+e")):
                result["stain_normalized"] = "H&E"
            elif any(k in low for k in ("ihc", "immunohistochem", "immunohistochemistry")):
                result["stain_normalized"] = "IHC"
            elif any(k in low for k in ("pas", "periodic acid")):
                result["stain_normalized"] = "PAS"
            elif any(k in low for k in ("masson", "trichrome")):
                result["stain_normalized"] = "Masson Trichrome"
            elif any(k in low for k in ("giemsa",)):
                result["stain_normalized"] = "Giemsa"
            elif any(k in low for k in ("alcian",)):
                result["stain_normalized"] = "Alcian Blue"
            else:
                result["stain_normalized"] = stain_raw

        # Scanner vendor/model
        result["scanner_vendor"] = (
            props.get("aperio.ScanScope ID")
            or props.get("hamamatsu.ProductVersion")
            or props.get("openslide.vendor")
        )
        result["scanner_model"] = (
            props.get("aperio.AppMag")  # reuse if no dedicated field
            and None  # don't overwrite magnification
        ) or props.get("tiff.Model")
    except Exception:
        pass

    slide.close()
    _emit(0)


if __name__ == "__main__":
    main()
