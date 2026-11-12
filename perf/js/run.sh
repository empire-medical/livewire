#!/usr/bin/env bash
# Client-side A/B: baseline (v4.4.8 worktree dist) vs fork (working-tree dist). Run from the repo root on the host.
#   perf/js/run.sh [--rerecord] [--aa] [--no-build] [--no-profile] [--rounds=3] [--iters=7]
#                  [--cpu-throttle=4] [--scenarios=ping,sort,expand10,select]
# --aa  runs baseline vs baseline (noise check; no fork build needed).
set -euo pipefail
cd "$(dirname "$0")/../.."
DC="docker compose -f docker/docker-compose.yml run --rm -e PERF_MARKERS=${PERF_MARKERS:-1}"
RERECORD=0; AA=0; BUILD=1; PROFILE=true; DRIVER_ARGS=()
for a in "$@"; do
  case "$a" in
    --rerecord) RERECORD=1 ;;
    --aa) AA=1; BUILD=0 ;;
    --no-build) BUILD=0 ;;
    --no-profile) PROFILE=false ;;
    --rounds=*|--iters=*|--cpu-throttle=*|--scenarios=*|--profile-iters=*) DRIVER_ARGS+=("$a") ;;
    *) echo "unknown arg $a" >&2; exit 2 ;;
  esac
done

ART=perf/results/js/artifacts
mkdir -p perf/results/js

if [ ! -d perf/js/node_modules/puppeteer-core ]; then
  $DC -w /app/perf/js app npm ci --no-audit --no-fund
fi

if [ "$BUILD" = 1 ]; then
  echo "== building fork dist"; $DC app npm run build >/dev/null
fi
[ -f .perf/baseline/dist/livewire.js ] || { echo "baseline dist missing at .perf/baseline/dist" >&2; exit 1; }
[ -f dist/livewire.js ] || { echo "fork dist missing; run without --no-build" >&2; exit 1; }

if [ "$RERECORD" = 1 ] || [ ! -f $ART/page.html ] || [ ! -f $ART/lazy.json ]; then
  echo "== recording artifacts"; $DC app vendor/bin/phpunit perf/js/RecordArtifactsTest.php 2>&1 | grep -E "recorded|OK|FAIL|Error" || true
fi

OUT=perf/results/js/$(date +%Y%m%d-%H%M%S)$([ "$AA" = 1 ] && echo -aa || true).json
SIDES=(--a=baseline --b=fork); [ "$AA" = 1 ] && SIDES=(--a=baseline --b=baseline)
echo "== running A/B -> $OUT"
$DC browser node perf/js/driver.js "${SIDES[@]}" --profile=$PROFILE "${DRIVER_ARGS[@]}" --out=/app/$OUT
$DC app node perf/js/compare.js /app/$OUT --profiles
echo; echo "results: $OUT"
