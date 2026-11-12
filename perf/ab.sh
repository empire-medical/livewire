#!/usr/bin/env bash
# A/B PHP benchmark: baseline (v4.4.8 worktree at .perf/baseline) vs the working tree.
# usage: perf/ab.sh perf/php/RoundTripBenchTest.php [extra phpunit args]   (run from repo root)
set -euo pipefail
cd "$(dirname "$0")/.."
TEST="$1"; shift || true
D="docker compose -f docker/docker-compose.yml run --rm"

# Make the baseline worktree run the same benchmark files (it has its own vendor/ and untouched src/).
mkdir -p .perf/baseline/perf && rsync -a --delete --exclude results perf/ .perf/baseline/perf/
mkdir -p .perf/baseline/perf/results perf/results
rm -f .perf/baseline/perf/results/baseline-*.json perf/results/fork-*.json

for round in 1 2 3; do   # interleave to spread machine noise across both sides
  $D -e PERF_LABEL=baseline -w /app/.perf/baseline app vendor/bin/phpunit "$TEST" "$@" 2>&1 | grep -E "^\s*\.?\s*\[|FAIL|Error|OK \(" || true
  $D -e PERF_LABEL=fork app vendor/bin/phpunit "$TEST" "$@" 2>&1 | grep -E "^\s*\.?\s*\[|FAIL|Error|OK \(" || true
  mkdir -p perf/results/runs/$round
  cp .perf/baseline/perf/results/baseline-*.json perf/results/runs/$round/ 2>/dev/null || true
  cp perf/results/fork-*.json perf/results/runs/$round/ 2>/dev/null || true
done
python3 perf/tools/ab_compare.py perf/results/runs
