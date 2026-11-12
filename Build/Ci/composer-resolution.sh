#!/usr/bin/env bash
# Composer PDF dependency resolution of the shipped Composer ZIP (F12 §27.7, "Composer resolution").
#
#   Build/Ci/composer-resolution.sh <13|14> <composer.zip> <classic.zip> <work-dir>
#
# 1. The Composer ZIP, unpacked as a path package into a minimal host project (platform PHP 8.2): `composer update`
#    succeeds, locks mpdf/mpdf, and Mpdf\Mpdf plus the extension's PdfGenerator autoload; a real PDF is rendered.
# 2. Control: the Classic ZIP's manifest resolves too, but locks NO mpdf/mpdf (Classic installs never get mPDF via AQG).
set -euo pipefail

major=${1:?13|14}; composer_zip=${2:?composer zip}; classic_zip=${3:?classic zip}; work=${4:?work dir}
here=$(cd "$(dirname "$0")" && pwd)
mkdir -p "$work"; work=$(cd "$work" && pwd)

for kind in composer classic; do
    zip=$composer_zip; [ "$kind" = classic ] && zip=$classic_zip
    rm -rf "$work/$kind-src"; mkdir -p "$work/$kind-src"; unzip -q "$zip" -d "$work/$kind-src"
    "$here/create-host-project.sh" "$major" "$work/$kind-host" "$work/$kind-src" dist
    (cd "$work/$kind-host" && composer update --no-interaction --no-progress --prefer-dist 2>&1 | tail -5)
    locked=$(php -r '$l = json_decode(file_get_contents($argv[1]), true); foreach ($l["packages"] as $p) { if ($p["name"] === "mpdf/mpdf") { echo $p["version"]; } }' "$work/$kind-host/composer.lock")
    core=$(php -r '$l = json_decode(file_get_contents($argv[1]), true); foreach ($l["packages"] as $p) { if ($p["name"] === "typo3/cms-core") { echo $p["version"]; } }' "$work/$kind-host/composer.lock")
    echo "$kind ZIP: typo3/cms-core $core, mpdf/mpdf ${locked:-<not locked>}"
    if [ "$kind" = composer ]; then
        [ -n "$locked" ] || { echo "FAIL: Composer ZIP did not lock mpdf/mpdf" >&2; exit 1; }
        (cd "$work/$kind-host" && php -r '
            require "vendor/autoload.php";
            if (!class_exists(\Mpdf\Mpdf::class) || !class_exists(\Priebera\A11yQualityGate\Export\PdfGenerator::class)) { fwrite(STDERR, "FAIL: autoload\n"); exit(1); }
            $pdf = (function () { $m = new \Mpdf\Mpdf(["tempDir" => sys_get_temp_dir() . "/aqg-ci-mpdf"]); $m->WriteHTML("<h1>AQG CI</h1>"); return $m->Output("", "S"); })();
            if (substr($pdf, 0, 5) !== "%PDF-") { fwrite(STDERR, "FAIL: mPDF did not render a PDF\n"); exit(1); }
            echo "Composer ZIP: Mpdf\\Mpdf + PdfGenerator autoload, rendered " . strlen($pdf) . " byte PDF\n";
        ')
    else
        [ -z "$locked" ] || { echo "FAIL: Classic manifest pulled mpdf/mpdf $locked" >&2; exit 1; }
    fi
done
echo "Composer resolution TYPO3 $major: PASS"
