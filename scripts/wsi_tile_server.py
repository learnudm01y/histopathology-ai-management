#!/usr/bin/env python3
"""
WSI On-Demand Tile Server
─────────────────────────
Serves DeepZoom tiles directly from WSI files without any pre-generation.
OpenSlide reads tiles on-demand, exactly like QuPath does internally.

Runs on 127.0.0.1:8001 — accessible only to Laravel on the same machine.
Laravel's WsiPreviewController proxies tile requests here.

Install:
    pip3 install flask

Run (managed by Supervisor):
    python3 /var/www/html/histopathology-ai-management/scripts/wsi_tile_server.py

Supervisor config (/etc/supervisor/conf.d/wsi-tile-server.conf):
    [program:wsi-tile-server]
    command=python3 /var/www/html/histopathology-ai-management/scripts/wsi_tile_server.py
    autostart=true
    autorestart=true
    user=www-data
    stdout_logfile=/var/www/html/histopathology-ai-management/storage/logs/tile-server.log
    stderr_logfile=/var/www/html/histopathology-ai-management/storage/logs/tile-server-err.log
"""

from __future__ import annotations

import io
import os
import sys
import threading

from flask import Flask, Response, abort, request

app = Flask(__name__)

# ── Slide cache ───────────────────────────────────────────────────────────────
# Keep a small number of WSI files open to avoid repeated openslide.OpenSlide()
# calls (each open costs ~50–200 ms for large SVS files).

_lock  = threading.Lock()
_cache: dict[str, dict] = {}   # {wsi_path: {"slide": ..., "dz": ..., "hits": int}}
_MAX_OPEN = 4                   # max simultaneous open WSI files


def _get_dz(wsi_path: str):
    """Return cached DeepZoomGenerator for *wsi_path*, opening if needed."""
    with _lock:
        if wsi_path in _cache:
            _cache[wsi_path]["hits"] += 1
            return _cache[wsi_path]["dz"]

        # Evict least-recently-used entry when at capacity
        if len(_cache) >= _MAX_OPEN:
            lru_key = min(_cache, key=lambda k: _cache[k]["hits"])
            try:
                _cache[lru_key]["slide"].close()
            except Exception:
                pass
            del _cache[lru_key]

        import openslide
        from openslide.deepzoom import DeepZoomGenerator

        slide = openslide.OpenSlide(wsi_path)
        dz    = DeepZoomGenerator(slide, tile_size=512, overlap=1, limit_bounds=False)
        _cache[wsi_path] = {"slide": slide, "dz": dz, "hits": 1}
        return dz


# ── Routes ────────────────────────────────────────────────────────────────────

@app.route("/health")
def health() -> Response:
    return Response("OK", mimetype="text/plain")


@app.route("/tile/<int:sample_id>/<int:level>/<int:col>/<int:row>")
def serve_tile(sample_id: int, level: int, col: int, row: int) -> Response:
    """
    Return a single DeepZoom tile as JPEG.

    Called by Laravel's WsiPreviewController which passes:
        ?wsi_path=/absolute/path/to/slide.svs
    """
    wsi_path = request.args.get("wsi_path", "")

    # Basic validation — wsi_path comes from Laravel cache (server-side), not user input.
    if not wsi_path or not os.path.isfile(wsi_path):
        abort(404)

    try:
        from PIL import Image as _PIL

        dz   = _get_dz(wsi_path)
        tile = dz.get_tile(level, (col, row))

        # Composite RGBA → RGB on white background.
        # OpenSlide returns RGBA; transparent background pixels become black
        # if you simply drop the alpha channel.
        if tile.mode == "RGBA":
            bg = _PIL.new("RGB", tile.size, (255, 255, 255))
            bg.paste(tile, mask=tile.split()[3])
            tile = bg
        elif tile.mode != "RGB":
            tile = tile.convert("RGB")

        buf = io.BytesIO()
        tile.save(buf, "JPEG", quality=85, optimize=True)
        return Response(buf.getvalue(), mimetype="image/jpeg")

    except Exception:
        abort(404)


# ── Entry point ───────────────────────────────────────────────────────────────

if __name__ == "__main__":
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 8001
    print(f"WSI Tile Server starting on 127.0.0.1:{port}", flush=True)
    # threaded=True → each tile request runs in its own thread.
    # OpenSlide's read_region is thread-safe for concurrent reads.
    app.run(host="127.0.0.1", port=port, threaded=True)
