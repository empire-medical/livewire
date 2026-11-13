#!/usr/bin/env bash
# Cut a LOCAL fork release that Composer can install: builds dist/, commits it (force-added, like the upstream
# release workflow does) on a release branch and tags it. Pushes nothing.
# usage: perf/release.sh 4.4.8-p1      (from the repo root, on the branch to release, with a clean working tree)
# Version note: Composer reads "-pN" as a patch level of 4.4.8 (stable, newer than 4.4.8), so ^4.3.3 constraints accept it.
# An unknown suffix such as "-perf.1" is NOT a valid Composer stability, so don't use one.
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION="${1:?usage: perf/release.sh <version, e.g. 4.4.8-p1>}"
VERSION="${VERSION#v}"
[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-p[0-9]+)?$ ]] || { echo "version must look like 4.4.8-p1" >&2; exit 2; }
[ -z "$(git status --porcelain)" ] || { echo "working tree must be clean" >&2; exit 2; }
git rev-parse -q --verify "refs/tags/v$VERSION" >/dev/null && { echo "tag v$VERSION already exists" >&2; exit 2; }

START_BRANCH="$(git branch --show-current)"
DC="docker compose -f docker/docker-compose.yml run --rm"

$DC app npm ci --no-audit --no-fund >/dev/null
$DC app npm run build >/dev/null
$DC app npm run test:run | tail -4

git switch -c "release/v$VERSION"
git add -f dist/
git commit -q -m "Build assets for v$VERSION"
git tag "v$VERSION"
git switch "$START_BRANCH"
echo "created branch release/v$VERSION and tag v$VERSION (not pushed). Push with:"
echo "  git push origin release/v$VERSION v$VERSION"
