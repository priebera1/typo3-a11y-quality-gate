#!/usr/bin/env bash
# Verifies the output of Build/Scripts/build-distributions.sh (release engineering, AQG 3.0.0).
#
#   Build/Scripts/verify-distributions.sh <dist-dir> [<expected-inventory-dir>]
#
# Checks, fail-closed:
#   - the provenance JSON names both ZIPs, and their SHA-256 and file counts are the recorded ones;
#   - the Composer and Classic ZIPs contain the same paths, and differ in exactly one file: ./composer.json;
#   - Composer composer.json requires mpdf/mpdf; Classic composer.json does not, and provides no package
#     (extra.typo3/cms.Package.providesPackages is an empty object: no impersonation);
#   - both ZIPs carry ext_emconf.php and composer.json at the archive root with the same version;
#   - no development-only path (Tests/, Build/, .github/, CLAUDE.md, .claude/, node_modules/, var/, Scss) is distributed;
#   - optionally: each ZIP's file inventory is byte-identical to <expected-inventory-dir>/inventory-<kind>.sha256
#     (for 3.0.0: Build/Release/3.0.0/, the inventories of the F13-r1 candidate: F13 plus the toolbar refinement,
#     re-approved 2026-11-10 from the F13 inventories, which no longer match).
#
# Writes <dist-dir>/inventory-{composer,classic}.sha256 and <dist-dir>/inventory-composer-vs-classic.diff and prints a
# one-line summary per check. Requires bash, php (>= 8.1, ext-zip), unzip, diff and shasum or sha256sum.
set -euo pipefail

dist=${1:?dist directory}
expected=${2:-}
dist=$(cd "$dist" && pwd)

fail() { echo "FAIL: $*" >&2; exit 1; }
ok() { echo "ok   $*"; }
if command -v sha256sum >/dev/null 2>&1; then sha() { sha256sum "$1" | cut -d' ' -f1; }; else sha() { shasum -a 256 "$1" | cut -d' ' -f1; }; fi
hashlist() { if command -v sha256sum >/dev/null 2>&1; then xargs -0 sha256sum; else xargs -0 shasum -a 256; fi; }

provenance=$(ls "$dist"/a11y_quality_gate_*-provenance.json 2>/dev/null | head -1)
[ -n "$provenance" ] || fail "no provenance JSON in $dist"
field() { php -r '$d = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); foreach (explode(".", $argv[2]) as $k) { $d = $d[$k] ?? null; } echo is_scalar($d) ? $d : "";' "$provenance" "$1"; }

version=$(field version)
[ -n "$version" ] || fail "provenance has no version"
for kind in composer classic; do
    zip="$dist/$(field "$kind.artefact")"
    [ -f "$zip" ] || fail "$kind ZIP missing: $zip"
    [ "$(basename "$zip")" = "a11y_quality_gate_${version}-${kind}.zip" ] || fail "$kind ZIP name does not carry version $version"
    [ "$(sha "$zip")" = "$(field "$kind.sha256")" ] || fail "$kind ZIP SHA-256 differs from the provenance record"
    ok "$kind ZIP sha256 $(field "$kind.sha256") matches provenance"
done
[ "$(field classic.parent)" = "$(field sourceCommit)" ] || fail "Classic derived commit is not a child of the source commit"

work=$(mktemp -d "${TMPDIR:-/tmp}/aqg-verify.XXXXXX")
trap 'rm -rf "$work"' EXIT
for kind in composer classic; do
    mkdir -p "$work/$kind"
    unzip -q "$dist/a11y_quality_gate_${version}-${kind}.zip" -d "$work/$kind"
    (cd "$work/$kind" && find . -type f -print0 | LC_ALL=C sort -z | hashlist) > "$dist/inventory-$kind.sha256"
    count=$(wc -l < "$dist/inventory-$kind.sha256" | tr -d ' ')
    [ "$count" = "$(field "$kind.files")" ] || fail "$kind inventory has $count files, provenance says $(field "$kind.files")"
    for root in ext_emconf.php composer.json; do [ -f "$work/$kind/$root" ] || fail "$kind ZIP has no root $root"; done
    for dev in Tests Build .github CLAUDE.md AGENTS.md .claude node_modules var Resources/Private/Scss package.json phpunit.xml; do
        [ ! -e "$work/$kind/$dev" ] || fail "$kind ZIP distributes development-only path $dev"
    done
    ok "$kind inventory: $count files, root ext_emconf.php + composer.json, no development-only paths"
done

diff "$dist/inventory-composer.sha256" "$dist/inventory-classic.sha256" > "$dist/inventory-composer-vs-classic.diff" || true
cut -c67- "$dist/inventory-composer.sha256" > "$work/paths-composer"
cut -c67- "$dist/inventory-classic.sha256" > "$work/paths-classic"
cmp -s "$work/paths-composer" "$work/paths-classic" || fail "Composer and Classic ZIPs contain different path sets"
changed=$(diff "$dist/inventory-composer.sha256" "$dist/inventory-classic.sha256" | sed -n 's/^< [0-9a-f]\{64\}  //p' || true)
[ "$changed" = "./composer.json" ] || fail "Composer and Classic differ in more than ./composer.json: ${changed:-<nothing>}"
ok "Composer vs Classic: same paths, only ./composer.json differs"

php -r '
    [$_, $composerFile, $classicFile, $version] = $argv;
    $composer = json_decode(file_get_contents($composerFile), true, 512, JSON_THROW_ON_ERROR);
    $classic = json_decode(file_get_contents($classicFile), true, 512, JSON_THROW_ON_ERROR);
    $errors = [];
    if (!isset($composer["require"]["mpdf/mpdf"])) { $errors[] = "Composer manifest does not require mpdf/mpdf"; }
    if (isset($classic["require"]["mpdf/mpdf"])) { $errors[] = "Classic manifest still requires mpdf/mpdf"; }
    foreach (["composer" => $composer, "classic" => $classic] as $kind => $m) {
        $provides = $m["extra"]["typo3/cms"]["Package"]["providesPackages"] ?? null;
        if ($provides !== []) { $errors[] = "$kind manifest providesPackages is not an empty object"; }
        if (($m["extra"]["typo3/cms"]["version"] ?? null) !== $version) { $errors[] = "$kind manifest version is not $version"; }
        if (($m["name"] ?? null) !== "priebera/typo3-a11y-quality-gate") { $errors[] = "$kind manifest package name changed"; }
    }
    unset($composer["require"]["mpdf/mpdf"]);
    if ($composer !== $classic) { $errors[] = "Classic manifest is not the Composer manifest minus mpdf/mpdf"; }
    if ($errors !== []) { fwrite(STDERR, "FAIL: " . implode("; ", $errors) . "\n"); exit(1); }
' "$work/composer/composer.json" "$work/classic/composer.json" "$version"
grep -q "'version' => '$version'" "$work/composer/ext_emconf.php" || fail "ext_emconf.php version is not $version"
ok "manifests: Composer requires mpdf/mpdf; Classic = Composer minus mpdf/mpdf; providesPackages {}; version $version"

if [ -n "$expected" ]; then
    for kind in composer classic; do
        cmp -s "$dist/inventory-$kind.sha256" "$expected/inventory-$kind.sha256" \
            || { diff "$expected/inventory-$kind.sha256" "$dist/inventory-$kind.sha256" | head -20 >&2 || true; fail "$kind inventory differs from $expected"; }
        ok "$kind inventory is byte-identical to $expected/inventory-$kind.sha256"
    done
fi
echo "verified: a11y_quality_gate $version from source commit $(field sourceCommit) (tree $(field sourceTree))"
