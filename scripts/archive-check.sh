#!/usr/bin/env bash
# Fail unless `git archive HEAD`, which is what a Packagist install downloads,
# holds exactly the wp.org distribution plus composer.json, README.md and
# CHANGELOG.md. The distribution is read from the DIST array in
# plugin-check/run.sh, so .gitattributes and that array cannot drift apart.
set -euo pipefail
cd "$(dirname "$0")/.."

extra=(composer.json README.md CHANGELOG.md)

dist_line=$(grep -E '^DIST=\(.+\)$' plugin-check/run.sh) || {
  echo "no DIST=(...) line in plugin-check/run.sh, so there is nothing to compare against." >&2
  exit 2
}
read -ra dist <<< "$(sed -E 's/^DIST=\((.*)\)$/\1/' <<< "$dist_line")"

# An entry that matches no committed file would drop out of both sides and
# compare equal, so a typo in DIST must fail here rather than pass.
for path in "${dist[@]}" "${extra[@]}"; do
  if [[ -z "$(git ls-tree -r --name-only HEAD -- "$path")" ]]; then
    echo "$path is listed as shipped but HEAD has no file there." >&2
    exit 1
  fi
done

expected=$(git ls-tree -r --name-only HEAD -- "${dist[@]}" "${extra[@]}" | sort)
actual=$(git archive --format=tar HEAD | tar -t | { grep -v '/$' || true; } | sort)

if [[ "$expected" == "$actual" ]]; then
  echo "git archive HEAD ships $(wc -l <<< "$actual") files: the wp.org distribution plus ${extra[*]}"
  exit 0
fi

echo "git archive HEAD does not match the wp.org distribution plus ${extra[*]}."
echo "Lines with > ship but should not; lines with < should ship but do not:"
diff <(echo "$expected") <(echo "$actual") | grep '^[<>]' || true
echo "Fix the export-ignore lines in .gitattributes, or the DIST array in plugin-check/run.sh."
exit 1
