#!/bin/bash
# Builds the handover zip from team31-app (for team31 maintainers; SenseiGigz
# receive the result). Run from anywhere:
#
#     handover/make-package.sh [output.zip]
#
# Layout of the zip:
#     senseigigz-engine/
#       README.md            points to handover/README.md
#       handover/            these docs, sql/, openapi/, testing/
#       embedding-service/   the service: files git doesn't ignore (no .venv, caches)
#       matcher/             the service and its pinned ranker copy, likewise
#       reference/           team31-app's PHP that the integration guide cites
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$(cd "$(dirname "${1:-$ROOT/senseigigz-engine.zip}")" && pwd)/$(basename "${1:-senseigigz-engine.zip}")"
STAGE="$(mktemp -d)/senseigigz-engine"
mkdir -p "$STAGE"
cd "$ROOT"

# The services: every file git doesn't ignore (tracked or new), from the working tree,
# so .venv/, caches and __pycache__ stay out.
git ls-files -z --cached --others --exclude-standard embedding-service matcher | rsync -a --from0 --files-from=- ./ "$STAGE/"

# The docs (MakeFixturesTest.php needs team31-app to run, but is kept so they can see how the fixtures were made).
rsync -a --exclude make-package.sh --exclude .DS_Store --exclude .venv --exclude __pycache__ --exclude 'fixtures_output.json' handover/ "$STAGE/handover/"

# team31-app's reference PHP (CodeIgniter 4): read it, don't run it.
for f in app/Libraries/ProviderEmbedder.php app/Libraries/EmbeddingStore.php app/Libraries/Taxonomy.php \
         app/Libraries/MatcherClient.php app/Libraries/MatcherException.php \
         app/Commands/EmbedAuto.php app/Commands/EmbedBatch.php \
         app/Controllers/Gigs.php app/Models/GigModel.php \
         app/Views/gig-matches.php app/Views/partials/taxonomy-picker.php \
         app/Config/Embedding.php app/Config/Matcher.php; do
  mkdir -p "$STAGE/reference/$(dirname "$f")"
  cp "$f" "$STAGE/reference/$f"
done

cat > "$STAGE/README.md" <<'EOF'
# SenseiGigs recommendation engine

Start with handover/README.md, then follow handover/integration-steps.md.

- embedding-service/, matcher/  the two services you run (see handover/deployment-and-operations.md)
- handover/                     the documentation, SQL, API specs and tests
- reference/                    team31-app's PHP (CodeIgniter 4) that the docs cite, for reading only
EOF

rm -f "$OUT"
(cd "$(dirname "$STAGE")" && zip -qr "$OUT" senseigigz-engine -x '*/__pycache__/*' '*.DS_Store')
rm -rf "$(dirname "$STAGE")"
echo "Wrote $OUT ($(du -h "$OUT" | cut -f1))"
