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
    except Exception:
        # Non-fatal: leave as None so the PHP layer treats it as not_checked.
        pass

    slide.close()
    _emit(0)


if __name__ == "__main__":
    main()
