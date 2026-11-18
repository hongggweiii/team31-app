#!/bin/bash
# Builds the handover zip from team31-app (for team31 maintainers; SenseiGigz
# receive the result). Run from anywhere:
#
#     handover/make-package.sh [output.zip]
#
# The zip holds only these documents (the handover/ folder as committed, minus
# this script): the code reaches SenseiGigz through the GitHub repositories the
# documents point to. Build it from a clean, committed checkout, and push that
# commit, so the documents and the code they describe match.
#
# Layout of the zip:
#     handover/   README.md, the guides, seed/, sql/, openapi/, testing/, the .env examples
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$(cd "$(dirname "${1:-$ROOT/handover.zip}")" && pwd)/$(basename "${1:-handover.zip}")"
STAGE="$(mktemp -d)"
cd "$ROOT"

# Every file git doesn't ignore in handover/ (tracked or new), so local junk
# (.venv, __pycache__, fixtures_output.json) stays out.
git ls-files -z --cached --others --exclude-standard handover | grep -zv '^handover/make-package.sh$' \
  | rsync -a --from0 --files-from=- ./ "$STAGE/"

rm -f "$OUT"
(cd "$STAGE" && zip -qr "$OUT" handover -x '*/__pycache__/*' '*.DS_Store')
rm -rf "$STAGE"
echo "Wrote $OUT ($(du -h "$OUT" | cut -f1))"
