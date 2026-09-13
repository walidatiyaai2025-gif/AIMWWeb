#!/usr/bin/env bash
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel)"
SOURCE_TOOLS="$ROOT/variants/laravel-aiwmweb/tools/convergence"
TMP_TOOLS="${RUNNER_TEMP:-/tmp}/laravel-aiwmweb-convergence-preflight"
COMPOSE_ROOT="${RUNNER_TEMP:-/tmp}/laravel-aiwmweb-convergence-tree"
CANDIDATE_SHA="$(git -C "$ROOT" rev-parse HEAD)"
rm -rf "$TMP_TOOLS" "$COMPOSE_ROOT"
mkdir -p "$TMP_TOOLS"
cp "$SOURCE_TOOLS/manifest.json" "$TMP_TOOLS/manifest.json"
cp "$SOURCE_TOOLS/apply_mechanical_overlays.py" "$TMP_TOOLS/apply_mechanical_overlays.py"
cp "$ROOT/variants/laravel-aiwmweb/tools/convergence_preflight.py" "$TMP_TOOLS/convergence_preflight.py"

mapfile -t manifest_values < <(python3 - "$TMP_TOOLS/manifest.json" <<'PY'
import json, sys
m=json.load(open(sys.argv[1]))
print(m['main']['sha'])
by_role={entry['role']: entry for entry in m['authorities']}
for role in m['composition_order']:
    e=by_role[role]
    pr='' if e.get('pr') is None else str(e['pr'])
    print(f"{pr}|{e['branch']}|{e['sha']}|{e['role']}")
PY
)
MAIN_SHA="${manifest_values[0]}"

cd "$ROOT"
git config user.name "Laravel AIWMWeb Convergence Preflight"
git config user.email "convergence-preflight@example.invalid"

for row in "${manifest_values[@]:1}"; do
    IFS='|' read -r pr branch sha role <<<"$row"
    git fetch --no-tags origin "$branch"
    git cat-file -e "${sha}^{commit}"
done
# #260 is the logical Site/Connector authority even though #269 transports its tree.
git fetch --no-tags origin feature/laravel-aiwmweb-demo-vertical-slice

# Reconstruct the pinned authority graph in an isolated worktree. The previous
# preflight checked out the historical manifest base in the primary workspace,
# which discarded integration-owned fixes already present on the exact PR head
# and then tested the stale reconstruction as if it were the candidate.
git worktree add --detach "$COMPOSE_ROOT" "$MAIN_SHA" >/dev/null
: > "$TMP_TOOLS/merge-log.txt"

echo "CANDIDATE_SHA=$CANDIDATE_SHA" | tee -a "$TMP_TOOLS/merge-log.txt"
echo "RECONSTRUCTION_ROOT=$COMPOSE_ROOT" | tee -a "$TMP_TOOLS/merge-log.txt"

cd "$COMPOSE_ROOT"
for row in "${manifest_values[@]:1}"; do
    IFS='|' read -r pr branch sha role <<<"$row"
    label="${pr:-branch:$branch}"
    echo "MERGE_START authority=$label role=$role sha=$sha" | tee -a "$TMP_TOOLS/merge-log.txt"
    if ! git merge --no-edit --no-ff -X ours "$sha"; then
        git status --short | tee -a "$TMP_TOOLS/merge-log.txt"
        echo "COMPOSITION_MERGE=FAIL authority=$label" | tee -a "$TMP_TOOLS/merge-log.txt"
        exit 20
    fi
    echo "MERGE_PASS authority=$label" | tee -a "$TMP_TOOLS/merge-log.txt"
done

if git ls-files -u | grep -q .; then
    echo "Unmerged paths remain after mechanical merge strategy" >&2
    git ls-files -u
    exit 21
fi

python3 "$TMP_TOOLS/apply_mechanical_overlays.py" \
    --root "$COMPOSE_ROOT" \
    --manifest "$TMP_TOOLS/manifest.json"

# Staging-only sync reconciliation payloads are never product convergence inputs.
rm -f "$COMPOSE_ROOT/.sync-payload.part1" "$COMPOSE_ROOT/.sync-payload.part2" "$COMPOSE_ROOT/.sync-payload.part3"

git diff --check

echo "RECONSTRUCTION_TREE=$(git write-tree)" | tee -a "$TMP_TOOLS/merge-log.txt"
echo "RECONSTRUCTION_OVERLAYS=PASS" | tee -a "$TMP_TOOLS/merge-log.txt"
echo "CANDIDATE_TREE=$(git -C "$ROOT" rev-parse HEAD^{tree})" | tee -a "$TMP_TOOLS/merge-log.txt"
echo "CANDIDATE_ACCEPTANCE_ROOT=$ROOT" | tee -a "$TMP_TOOLS/merge-log.txt"
echo "PREFLIGHT_TMP=$TMP_TOOLS"
