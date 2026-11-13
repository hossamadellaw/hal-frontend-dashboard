#!/usr/bin/env bash
# bump-release.sh — one-command release identity bump (CR-1, v1.1.3).
#
# Updates exactly SIX locations with a single invocation:
#   1. hal-frontend-dashboard.php   header line  " * Version: <VERSION>"
#   2. hal-frontend-dashboard.php   constant     HAL_FRONTEND_DASHBOARD_VERSION '<VERSION>'
#   3. readme.txt                   "Stable tag: <VERSION>"
#   4. readme.txt                   installation ZIP name "hal-frontend-dashboard-<VERSION>.zip"
#   5. readme.txt                   changelog entry "= <VERSION> =" at the top of "== Changelog =="
#   6. build/release-sequence.txt   "<SEQUENCE>" (LF, no BOM)
#
# Inputs: exactly two positional arguments — VERSION and SEQUENCE. Nothing else.
#   VERSION  semver shape ^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$
#   SEQUENCE positive integer
#
# Guards:
#   - Idempotent short-circuit: when all six locations already hold the target
#     values the script exits 0 with zero changes (safe re-run / dry-run).
#   - Pre-flight: fails (exit 1) unless the five text locations are consistent
#     on ONE current version and build/release-sequence.txt equals SEQUENCE-1
#     (prevents accumulation from a stale base). The target version must also
#     be strictly greater than the current one.
#   - Post-verify: re-reads all six locations, prints a match table and runs
#     php -l on the main plugin file.
#   - Zero touch: no file outside the six locations is read-modified-written.
#
# The changelog entry body below describes release 1.1.3 (English, product
# language). Future release checklists must update CHANGELOG_BODY before
# bumping; the "= VERSION =" heading and the identity line are generated
# dynamically from the inputs.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(dirname "$SCRIPT_DIR")"

MAIN="$ROOT/hal-frontend-dashboard.php"
README="$ROOT/readme.txt"
SEQ_FILE="$ROOT/build/release-sequence.txt"

fail() { echo "bump-release: $1" >&2; exit 1; }

[[ $# -eq 2 ]] || fail "usage: bump-release.sh <VERSION> <SEQUENCE> (exactly two inputs)"
VERSION="$1"
SEQUENCE="$2"
[[ "$VERSION" =~ ^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$ ]] || fail "VERSION '$VERSION' is not semver x.y.z"
[[ "$SEQUENCE" =~ ^[1-9][0-9]*$ ]] || fail "SEQUENCE '$SEQUENCE' is not a positive integer"
[[ -f "$MAIN" && -f "$README" && -f "$SEQ_FILE" ]] || fail "repo layout missing (main file / readme.txt / release-sequence.txt)"

# --- read the six current values -------------------------------------------
CUR_HEADER="$(grep -m1 -oP '^ \* Version: \K[0-9][0-9.]*' "$MAIN" | tr -d '\r')" || true
CUR_CONST="$(grep -m1 -oP "HAL_FRONTEND_DASHBOARD_VERSION', '\K[0-9][0-9.]*" "$MAIN" | tr -d '\r')" || true
CUR_STABLE="$(grep -m1 -oP '^Stable tag: \K[0-9][0-9.]*' "$README" | tr -d '\r')" || true
CUR_ZIP="$(grep -m1 -oP 'hal-frontend-dashboard-\K[0-9][0-9.]*(?=\.zip)' "$README" | tr -d '\r')" || true
CUR_CHANGELOG="$(tr -d '\r' < "$README" | awk '/^== Changelog ==/{s=1;next} s && /^= [0-9][0-9.]* =$/{sub(/^= /,"");sub(/ =$/,"");print;exit}')" || true
CUR_SEQ="$(tr -d '\r\n ' < "$SEQ_FILE")" || true

for v in "$CUR_HEADER" "$CUR_CONST" "$CUR_STABLE" "$CUR_ZIP" "$CUR_CHANGELOG"; do
  [[ "$v" =~ ^[0-9][0-9.]*$ ]] || fail "cannot read current identity values (one of header/const/stable/zip/changelog unreadable)"
done
[[ "$CUR_SEQ" =~ ^[1-9][0-9]*$ ]] || fail "build/release-sequence.txt does not hold a positive integer ('$CUR_SEQ')"

ZIP_COUNT="$(grep -cF "hal-frontend-dashboard-$CUR_ZIP.zip" "$README" || true)"
[[ "$ZIP_COUNT" -eq 1 ]] || fail "expected exactly 1 occurrence of the installation ZIP name, found $ZIP_COUNT"

# --- idempotent short-circuit ------------------------------------------------
if [[ "$CUR_HEADER" == "$VERSION" && "$CUR_CONST" == "$VERSION" && "$CUR_STABLE" == "$VERSION" \
      && "$CUR_ZIP" == "$VERSION" && "$CUR_CHANGELOG" == "$VERSION" && "$CUR_SEQ" == "$SEQUENCE" ]]; then
  echo "bump-release: all six locations already at target (version=$VERSION sequence=$SEQUENCE) — exit 0, zero changes"
  exit 0
fi

# --- pre-flight guard --------------------------------------------------------
PRE_FLIGHT_OK=1
for pair in "header:$CUR_HEADER" "const:$CUR_CONST" "stable:$CUR_STABLE" "zip:$CUR_ZIP" "changelog:$CUR_CHANGELOG"; do
  if [[ "${pair#*:}" != "$CUR_HEADER" ]]; then
    echo "bump-release: pre-flight FAILED — ${pair%%:*} holds '${pair#*:}', expected the consistent current version '$CUR_HEADER'" >&2
    PRE_FLIGHT_OK=0
  fi
done
if [[ "$CUR_SEQ" != "$((SEQUENCE - 1))" ]]; then
  echo "bump-release: pre-flight FAILED — release-sequence.txt holds '$CUR_SEQ', expected SEQUENCE-1 ($((SEQUENCE - 1)))" >&2
  PRE_FLIGHT_OK=0
fi
HIGHER="$(printf '%s\n%s\n' "$CUR_HEADER" "$VERSION" | sort -V | tail -n1)"
if [[ "$HIGHER" != "$VERSION" || "$VERSION" == "$CUR_HEADER" ]]; then
  echo "bump-release: pre-flight FAILED — target version '$VERSION' must be strictly greater than current '$CUR_HEADER'" >&2
  PRE_FLIGHT_OK=0
fi
[[ "$PRE_FLIGHT_OK" -eq 1 ]] || exit 1

# --- changelog entry body (release-specific; see header comment) -------------
if tr -d '\r' < "$README" | grep -qxF "= $VERSION ="; then
  fail "changelog already carries an '= $VERSION =' entry — refusing to duplicate"
fi
if command grep -q $'\r' "$README"; then NL=$'\r\n'; else NL=$'\n'; fi
CHANGELOG_BODY=(
'* The article editor gains a professional formatting toolbar above the writing canvas: ten tool groups plus a History group and an admin-only More menu, a static bottom action bar (Publish / Save Draft / Discard), and responsive tablet and phone layouts — all rendered with the existing dashboard stylesheet.'
'* The editor sidebar gains the Publishing, Metadata, Tags, Translations Hub, and SEO & SERP cards, along with the empty/writing/saved/error editor states, the untranslated-language chip, legacy hidden-block handling, and RankMath suggestions — each behind the existing server-side capability gates.'
'* All runtime source files are English-only: user-facing strings use the standard translation helper with registered English sources, and any remaining Arabic source text is confined to the tracked build/arabic-allowlist.txt file, which must shrink with every later release.'
'* A new release tooling script (build/bump-release.sh) updates the plugin header, version constant, stable tag, installation ZIP name, changelog entry, and release sequence in one command, with pre-flight consistency checks, post-verify reporting, and an idempotent re-run.'
'* The release workflow validates the Requires WordPress and Requires PHP header values against the built manifest, checks the installation ZIP name, the changelog entry, and the release sequence shape, and installs PHP 8.3 explicitly before the full test run.'
)
ENTRY_FILE="$(mktemp "$ROOT/.bump-entry.XXXXXX")"
TMP_README="$(mktemp "$ROOT/.bump-readme.XXXXXX")"
cleanup() { rm -f "$ENTRY_FILE" "$TMP_README"; }
trap cleanup EXIT INT TERM
{
  printf '= %s =%s' "$VERSION" "$NL"
  for line in "${CHANGELOG_BODY[@]}"; do printf '%s%s' "$line" "$NL"; done
  printf '* Version identity %s across the plugin header, version constant, stable tag, and installation ZIP name; release sequence %s.%s' "$VERSION" "$SEQUENCE" "$NL"
  printf '%s' "$NL"
} > "$ENTRY_FILE"

# --- apply the six updates ---------------------------------------------------
INSERT_LINENO="$(tr -d '\r' < "$README" | grep -n -m1 '^= [0-9][0-9.]* =$' | cut -d: -f1)"
[[ -n "$INSERT_LINENO" && "$INSERT_LINENO" -ge 1 ]] || fail "cannot locate the first changelog entry heading"
head -n $((INSERT_LINENO - 1)) "$README" > "$TMP_README"
cat "$ENTRY_FILE" >> "$TMP_README"
tail -n +"$INSERT_LINENO" "$README" >> "$TMP_README"
mv -f "$TMP_README" "$README"

# readme.txt: stable tag and installation ZIP name (in-place, CR per line kept)
sed -i "s|^\(Stable tag: \)[0-9][0-9.]*|\1$VERSION|" "$README"
sed -i "s|hal-frontend-dashboard-[0-9][0-9.]*\.zip|hal-frontend-dashboard-$VERSION.zip|" "$README"

# main file: header version and version constant (in-place, CR per line kept)
sed -i "s|^\( \* Version: \)[0-9][0-9.]*|\1$VERSION|" "$MAIN"
sed -i "s|\(HAL_FRONTEND_DASHBOARD_VERSION', '\)[0-9][0-9.]*\('\)|\1$VERSION\2|" "$MAIN"

# sequence file: LF, no BOM
printf '%s\n' "$SEQUENCE" > "$SEQ_FILE"

# --- post-verify -------------------------------------------------------------
NEW_HEADER="$(grep -m1 -oP '^ \* Version: \K[0-9][0-9.]*' "$MAIN" | tr -d '\r')"
NEW_CONST="$(grep -m1 -oP "HAL_FRONTEND_DASHBOARD_VERSION', '\K[0-9][0-9.]*" "$MAIN" | tr -d '\r')"
NEW_STABLE="$(grep -m1 -oP '^Stable tag: \K[0-9][0-9.]*' "$README" | tr -d '\r')"
NEW_ZIP="$(grep -m1 -oP 'hal-frontend-dashboard-\K[0-9][0-9.]*(?=\.zip)' "$README" | tr -d '\r')"
NEW_CHANGELOG="$(tr -d '\r' < "$README" | awk '/^== Changelog ==/{s=1;next} s && /^= [0-9][0-9.]* =$/{sub(/^= /,"");sub(/ =$/,"");print;exit}')"
NEW_SEQ="$(tr -d '\r\n ' < "$SEQ_FILE")"

STATUS=1
echo "bump-release: post-verify table (target version=$VERSION sequence=$SEQUENCE)"
verify_row() {
  local label="$1" got="$2"
  if [[ "$got" == "$3" ]]; then
    printf '  %-28s MATCH   (%s)\n' "$label" "$got"
  else
    printf '  %-28s BROKEN  (got %s, expected %s)\n' "$label" "$got" "$3"
    STATUS=0
  fi
}
verify_row 'header * Version:' "$NEW_HEADER" "$VERSION"
verify_row 'version constant' "$NEW_CONST" "$VERSION"
verify_row 'readme Stable tag:' "$NEW_STABLE" "$VERSION"
verify_row 'readme ZIP name' "$NEW_ZIP" "$VERSION"
verify_row 'changelog top entry' "$NEW_CHANGELOG" "$VERSION"
verify_row 'release sequence' "$NEW_SEQ" "$SEQUENCE"
php -l "$MAIN" >/dev/null || { echo 'bump-release: php -l FAILED on the main plugin file' >&2; exit 1; }
echo 'bump-release: php -l main plugin file OK'
[[ "$STATUS" -eq 1 ]] || { echo 'bump-release: post-verify FAILED' >&2; exit 1; }
echo 'bump-release: all six locations updated and verified'
