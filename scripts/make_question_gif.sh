#!/usr/bin/env bash
set -euo pipefail

# Create a subtle animated GIF from a static PNG/SVG using ImageMagick.
#
# Usage:
#   ./scripts/make_question_gif.sh assets/images/source.png assets/images/question-bang.gif
#
# Requires:
#   - ImageMagick `convert` available in PATH

if [[ $# -lt 2 ]]; then
  echo "Usage: $0 <input-image> <output-gif>" >&2
  exit 1
fi

in="$1"
out="$2"

if ! command -v convert >/dev/null 2>&1; then
  echo "Error: ImageMagick 'convert' not found" >&2
  exit 2
fi

# Build two-frame gentle pulse (brightness/contrast) and loop.
convert \
  "$in" -write mpr:base +delete \
  \( mpr:base -brightness-contrast 6x10 \) \
  \( mpr:base -brightness-contrast 0x0 \) \
  -set delay 70 -loop 0 -layers Optimize "$out"

echo "Wrote $out"
