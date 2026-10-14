#!/usr/bin/env python3
"""Build the animated grain film strip from the static tile.

The first frame is public/assets/img/grain.png. The following frames use the
same six grey levels and the same opacity, so the moving grain matches the
static one. Output is a vertical strip, one tile per frame, like reichi.com.
"""

import random
from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parents[1]
TILE = ROOT / "public" / "assets" / "img" / "grain.png"
OUT = ROOT / "public" / "assets" / "img" / "grain-frames.png"
FRAMES = 8
LEVELS = (0, 60, 120, 180, 230, 255)
ALPHA = 18


def main() -> None:
    base = Image.open(TILE).convert("RGBA")
    width, height = base.size
    if width != height:
        raise SystemExit("grain tile must be square")
    sheet = Image.new("RGBA", (width, height * FRAMES))
    sheet.paste(base, (0, 0))
    rng = random.Random(20261014)
    for frame in range(1, FRAMES):
        tile = Image.new("RGBA", (width, height))
        pixels = [
            (level, level, level, ALPHA)
            for level in (rng.choice(LEVELS) for _ in range(width * height))
        ]
        tile.putdata(pixels)
        sheet.paste(tile, (0, frame * height))
    sheet.save(OUT, optimize=True)
    print(f"{OUT.relative_to(ROOT)} {sheet.size[0]}x{sheet.size[1]}")


if __name__ == "__main__":
    main()
