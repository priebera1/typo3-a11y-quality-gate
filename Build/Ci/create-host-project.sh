#!/usr/bin/env bash
# Creates the TYPO3 Composer host project the AQG PHP suites need (the extension repository has no vendor/).
# Layout matches the bootstraps (Tests/Bootstrap/*: project root = dirname(Tests/Bootstrap, 4)):
#
#   <host>/composer.json
#   <host>/packages/a11y_quality_gate/   ← <extension-source>, copied (a checkout, or an unpacked distribution ZIP)
#   <host>/vendor/
#
#   Build/Ci/create-host-project.sh <13|14> <host-dir> <extension-source> [tests|dist]
#
#   tests  (default) core + backend/frontend/extbase/fluid/install/filelist/rte_ckeditor/scheduler/workspaces
#          + testing framework (unit + functional)
#   dist   only what the distribution itself requires: proves Composer resolves the shipped manifest (mpdf/mpdf incl.)
#
# Platform PHP is pinned to 8.2.0 (the extension's minimum), so the lock is resolvable on every supported PHP.
set -euo pipefail

major=${1:?13|14}; host=${2:?host dir}; source=${3:?extension source}; mode=${4:-tests}
case $major in 13) core='^13.4' ;; 14) core='^14.3' ;; *) echo "unsupported TYPO3 major $major" >&2; exit 2 ;; esac
source=$(cd "$source" && pwd)
mkdir -p "$host/packages"; host=$(cd "$host" && pwd)
rm -rf "$host/packages/a11y_quality_gate"
# Copy (never symlink): composer path repositories symlink by default, and the copied tree is what gets tested.
(cd "$source" && tar --exclude=./.git --exclude=./node_modules --exclude=./var -cf - .) | (mkdir -p "$host/packages/a11y_quality_gate" && cd "$host/packages/a11y_quality_gate" && tar -xf -)
version=$(php -r 'echo json_decode(file_get_contents($argv[1]), false, 512, JSON_THROW_ON_ERROR)->extra->{"typo3/cms"}->version;' "$host/packages/a11y_quality_gate/composer.json")

php -r '
    [$_, $file, $core, $version, $mode] = $argv;
    // typo3/cms-core pins the TYPO3 major under test; everything else comes from the AQG manifest itself.
    $require = ["priebera/typo3-a11y-quality-gate" => $version, "typo3/cms-core" => $core];
    $requireDev = [];
    if ($mode === "tests") {
        // Every system extension Classes/ or Tests/ reference (filelist: FilelistMediaAiListener and its unit test).
        foreach (["core", "backend", "frontend", "extbase", "fluid", "install", "filelist", "rte-ckeditor", "scheduler", "workspaces"] as $p) {
            $require["typo3/cms-$p"] = $core;
        }
        $requireDev = ["phpunit/phpunit" => "^11.5", "typo3/testing-framework" => "^9.3", "dg/bypass-finals" => "^1.8"];
    }
    $project = [
        "name" => "aqg-ci/host-" . $mode,
        "type" => "project",
        "license" => "GPL-2.0-or-later",
        "minimum-stability" => "stable",
        "repositories" => [["type" => "path", "url" => "./packages/*", "options" => ["symlink" => true, "versions" => ["priebera/typo3-a11y-quality-gate" => $version]]]],
        "require" => $require,
        "config" => [
            "platform" => ["php" => "8.2.0"],
            "sort-packages" => true,
            "allow-plugins" => ["typo3/class-alias-loader" => true, "typo3/cms-composer-installers" => true],
        ],
    ];
    if ($requireDev !== []) { $project["require-dev"] = $requireDev; }
    file_put_contents($file, json_encode($project, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
' "$host/composer.json" "$core" "$version" "$mode"

echo "$host (TYPO3 $core, AQG $version, mode $mode)"
