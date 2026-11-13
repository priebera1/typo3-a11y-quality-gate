#!/usr/bin/env bash
# Builds both AQG distributions from ONE commit, deterministically, with a provenance record (F12 / F11-01).
#
#   Build/Scripts/build-distributions.sh <git-dir-or-worktree> <commit-ish> <out-dir>
#
#   <out-dir>/a11y_quality_gate_<version>-composer.zip   git archive of the commit: the Composer source (= Packagist dist).
#                                                        composer.json requires mpdf/mpdf → PDF export.
#   <out-dir>/a11y_quality_gate_<version>-classic.zip    Classic/TER artefact: the same tree, only composer.json
#                                                        transformed by Build/Scripts/ClassicDistribution.php *of that
#                                                        commit* (mPDF requirement removed, nothing provided or bundled).
#                                                        Upload with: tailor ter:publish <version> --artefact=<this zip>
#   <out-dir>/a11y_quality_gate_<version>-provenance.json  source commit/tree, derived Classic commit/tree, transform
#                                                        blob, manifest hashes and diff, ZIP hashes and entry counts.
#
# The Classic tree is a commit object whose parent is the source commit, with the source commit's author/committer
# dates, written to a temporary object directory (the repository gets no object, ref or index change). Same commit →
# byte-identical ZIPs. Requires git and php (any PHP >= 8.1 for the transform).
set -euo pipefail

repo=${1:?git dir or worktree}
rev=${2:?commit-ish}
out=${3:?output directory}

# The shared object directory, also from a linked worktree (whose own git dir has no objects/).
objects=$(git -C "$repo" rev-parse --path-format=absolute --git-path objects)
[ -d "$objects" ] || { echo "no object directory: $objects" >&2; exit 1; }
commit=$(git -C "$repo" rev-parse --verify "$rev^{commit}")
source_tree=$(git -C "$repo" rev-parse "$commit^{tree}")
mkdir -p "$out"
out=$(cd "$out" && pwd)

work=$(mktemp -d "${TMPDIR:-/tmp}/aqg-dist.XXXXXX")
trap 'rm -rf "$work"' EXIT

git -C "$repo" show "$commit:composer.json" > "$work/composer.source.json"
git -C "$repo" show "$commit:Build/Scripts/ClassicDistribution.php" > "$work/ClassicDistribution.php"
transform_blob=$(git -C "$repo" rev-parse "$commit:Build/Scripts/ClassicDistribution.php")
php "$work/ClassicDistribution.php" < "$work/composer.source.json" > "$work/composer.classic.json"
# The transform's output is checked, not trusted: valid JSON, equal to the source without the Composer-only requirements.
php -r '
    require $argv[1];
    $source = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
    $classic = json_decode(file_get_contents($argv[3]), true, 512, JSON_THROW_ON_ERROR);
    foreach (array_keys(\Priebera\A11yQualityGate\Build\ClassicDistribution::COMPOSER_ONLY_REQUIREMENTS) as $package) {
        unset($source["require"][$package]);
    }
    if ($classic !== $source || ($classic["extra"]["typo3/cms"]["Package"]["providesPackages"] ?? null) !== []) {
        fwrite(STDERR, "Classic manifest is not the source manifest minus the Composer-only requirements\n");
        exit(1);
    }
' "$work/ClassicDistribution.php" "$work/composer.source.json" "$work/composer.classic.json"

version=$(php -r 'echo json_decode(file_get_contents($argv[1]), false, 512, JSON_THROW_ON_ERROR)->extra->{"typo3/cms"}->version;' "$work/composer.source.json")
emconf_version=$(git -C "$repo" show "$commit:ext_emconf.php" | sed -n "s/^[[:space:]]*'version'[[:space:]]*=>[[:space:]]*'\([^']*\)'.*/\1/p" | head -1)
[ "$version" = "$emconf_version" ] || { echo "composer.json version $version != ext_emconf.php $emconf_version" >&2; exit 1; }

composer_zip="$out/a11y_quality_gate_${version}-composer.zip"
classic_zip="$out/a11y_quality_gate_${version}-classic.zip"
provenance="$out/a11y_quality_gate_${version}-provenance.json"

git -C "$repo" archive --format=zip -o "$composer_zip" "$commit"

# Derived Classic commit, written only to the temporary object directory.
export GIT_INDEX_FILE="$work/index" GIT_OBJECT_DIRECTORY="$work/objects" GIT_ALTERNATE_OBJECT_DIRECTORIES="$objects"
mkdir -p "$GIT_OBJECT_DIRECTORY"
git -C "$repo" read-tree "$commit"
classic_blob=$(git -C "$repo" hash-object -w "$work/composer.classic.json")
git -C "$repo" update-index --cacheinfo "100644,$classic_blob,composer.json"
classic_tree=$(git -C "$repo" write-tree)
export GIT_AUTHOR_NAME="AQG distribution build" GIT_AUTHOR_EMAIL="build@aqg.invalid"
export GIT_COMMITTER_NAME="AQG distribution build" GIT_COMMITTER_EMAIL="build@aqg.invalid"
GIT_AUTHOR_DATE=$(git -C "$repo" show -s --format=%aI "$commit")
GIT_COMMITTER_DATE=$(git -C "$repo" show -s --format=%cI "$commit")
export GIT_AUTHOR_DATE GIT_COMMITTER_DATE
classic_commit=$(printf 'AQG %s Classic/TER distribution of %s\n\ncomposer.json transformed by Build/Scripts/ClassicDistribution.php (blob %s): Composer-only requirements removed.\n' \
    "$version" "$commit" "$transform_blob" | git -C "$repo" commit-tree --no-gpg-sign "$classic_tree" -p "$commit")
git -C "$repo" archive --format=zip -o "$classic_zip" "$classic_commit"
changed=$(git -C "$repo" diff-tree --no-commit-id --name-only -r "$commit" "$classic_commit")
[ "$changed" = "composer.json" ] || { echo "Classic tree differs in more than composer.json: $changed" >&2; exit 1; }
manifest_diff=$(git -C "$repo" diff --no-color "$commit" "$classic_commit" -- composer.json)
unset GIT_INDEX_FILE GIT_OBJECT_DIRECTORY GIT_ALTERNATE_OBJECT_DIRECTORIES

sha() { shasum -a 256 "$1" | cut -d' ' -f1; }
entries() { php -r '$z = new ZipArchive(); if ($z->open($argv[1]) !== true) { exit(1); } $n = 0; for ($i = 0; $i < $z->numFiles; $i++) { if (substr($z->getNameIndex($i), -1) !== "/") { $n++; } } echo $n;' "$1"; }
# Assigned first, so that a failing hash or count stops the build (set -e) instead of recording an empty value.
composer_sha=$(sha "$composer_zip"); composer_entries=$(entries "$composer_zip")
classic_sha=$(sha "$classic_zip"); classic_entries=$(entries "$classic_zip")
[ ${#composer_sha} = 64 ] && [ ${#classic_sha} = 64 ] && [ "$composer_entries" -gt 0 ] && [ "$composer_entries" = "$classic_entries" ] \
    || { echo "ZIP hash or entry count check failed" >&2; exit 1; }
tools="$(git --version); PHP $(php -r 'echo PHP_VERSION;')"

php -r '
    [$_, $file, $version, $commit, $sourceTree, $classicCommit, $classicTree, $transformBlob, $sourceJson, $classicJson,
        $composerZip, $composerSha, $composerEntries, $classicZip, $classicSha, $classicEntries, $diff, $tools] = $argv;
    $record = [
        "extension" => "a11y_quality_gate",
        "version" => $version,
        "sourceCommit" => $commit,
        "sourceTree" => $sourceTree,
        "transform" => ["path" => "Build/Scripts/ClassicDistribution.php", "blob" => $transformBlob],
        "composer" => [
            "artefact" => basename($composerZip), "sha256" => $composerSha, "files" => (int)$composerEntries,
            "built" => "git archive --format=zip " . $commit,
            "composerJsonSha256" => hash_file("sha256", $sourceJson),
        ],
        "classic" => [
            "artefact" => basename($classicZip), "sha256" => $classicSha, "files" => (int)$classicEntries,
            "derivedCommit" => $classicCommit, "derivedTree" => $classicTree, "parent" => $commit,
            "built" => "git archive --format=zip " . $classicCommit,
            "composerJsonSha256" => hash_file("sha256", $classicJson),
            "changedPaths" => ["composer.json"],
        ],
        "manifestDiff" => $diff,
        "tools" => $tools,
    ];
    file_put_contents($file, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
' "$provenance" "$version" "$commit" "$source_tree" "$classic_commit" "$classic_tree" "$transform_blob" \
    "$work/composer.source.json" "$work/composer.classic.json" \
    "$composer_zip" "$composer_sha" "$composer_entries" \
    "$classic_zip" "$classic_sha" "$classic_entries" "$manifest_diff" "$tools"

echo "$composer_zip"
echo "$classic_zip"
echo "$provenance"
