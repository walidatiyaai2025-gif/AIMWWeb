#!/usr/bin/env bash
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel)"
SOURCE_TOOLS="$ROOT/variants/laravel-aiwmweb/tools/convergence"
TMP_TOOLS="${RUNNER_TEMP:-/tmp}/laravel-aiwmweb-convergence-preflight"
rm -rf "$TMP_TOOLS"
mkdir -p "$TMP_TOOLS"
cp "$SOURCE_TOOLS/manifest.json" "$TMP_TOOLS/manifest.json"
cp "$SOURCE_TOOLS/apply_mechanical_overlays.py" "$TMP_TOOLS/apply_mechanical_overlays.py"
cp "$ROOT/variants/laravel-aiwmweb/tools/convergence_preflight.py" "$TMP_TOOLS/convergence_preflight.py"

mapfile -t manifest_values < <(python3 - "$TMP_TOOLS/manifest.json" <<'PY'
import json, sys
m=json.load(open(sys.argv[1]))
print(m['main']['sha'])
for p in m['composition_order']:
    e=next(x for x in m['authorities'] if x['pr']==p)
    print(f"{e['pr']}|{e['branch']}|{e['sha']}|{e['role']}")
PY
)
MAIN_SHA="${manifest_values[0]}"

cd "$ROOT"
git config user.name "Laravel AIWMWeb Convergence Preflight"
git config user.email "convergence-preflight@example.invalid"

# Fetch every authoritative ref by branch name and prove the captured SHA still exists.
for row in "${manifest_values[@]:1}"; do
    IFS='|' read -r pr branch sha role <<<"$row"
    git fetch --no-tags origin "$branch"
    git cat-file -e "${sha}^{commit}"
done
# #260 is logical protocol authority even though #269 transports its tree.
git fetch --no-tags origin feature/laravel-aiwmweb-demo-vertical-slice

git checkout --detach "$MAIN_SHA"
: > "$TMP_TOOLS/merge-log.txt"

for row in "${manifest_values[@]:1}"; do
    IFS='|' read -r pr branch sha role <<<"$row"
    echo "MERGE_START pr=$pr role=$role sha=$sha" | tee -a "$TMP_TOOLS/merge-log.txt"
    if ! git merge --no-edit --no-ff -X ours "$sha"; then
        git status --short | tee -a "$TMP_TOOLS/merge-log.txt"
        echo "COMPOSITION_MERGE=FAIL pr=$pr" | tee -a "$TMP_TOOLS/merge-log.txt"
        exit 20
    fi
    echo "MERGE_PASS pr=$pr" | tee -a "$TMP_TOOLS/merge-log.txt"
done

if git ls-files -u | grep -q .; then
    echo "Unmerged paths remain after mechanical merge strategy" >&2
    git ls-files -u
    exit 21
fi

python3 "$TMP_TOOLS/apply_mechanical_overlays.py" \
    --root "$ROOT" \
    --manifest "$TMP_TOOLS/manifest.json"

# Staging-only branches are intentionally excluded from the product composition.
rm -f "$ROOT/.sync-payload.part1"

git diff --check

echo "COMPOSITION_TREE=$(git write-tree)" | tee -a "$TMP_TOOLS/merge-log.txt"
echo "COMPOSITION_OVERLAYS=PASS" | tee -a "$TMP_TOOLS/merge-log.txt"
echo "PREFLIGHT_TMP=$TMP_TOOLS"
