#!/usr/bin/env bash
# Genuine Classic (non-Composer) TYPO3 install: AQG Classic/TER ZIP activation and the mPDF dependency-coexistence
# negative control (F11-01 / F12). CI port of the F12 acceptance procedure (report §27.7).
#
#   Build/Ci/classic-coexistence.sh <13|14> <classic.zip> <composer.zip> <work-dir> <log-file>
#
# Env: DB_HOST DB_PORT DB_USER DB_PASSWORD (a disposable MariaDB/MySQL server), PHP (default: php).
# The official source tarball is downloaded from get.typo3.org and checked against the pinned SHA-256 below.
#
# Cases (each a fresh install with its own database):
#   control   no AQG, then the fixture extension aqg_mpdf_consumer that genuinely requires mpdf/mpdf
#   classic   AQG Classic ZIP activate + extension:setup, content scan, export probe, then aqg_mpdf_consumer
#   composer  AQG Composer ZIP in Classic mode (requires mpdf/mpdf)
# Assertions:
#   both cores: AQG Classic activates (rc 0) and sets up (rc 0); Mpdf\Mpdf is not loadable; PDF is reported unavailable;
#               aqg_mpdf_consumer behaves exactly as in the no-AQG control (AQG provides/impersonates nothing)
#   TYPO3 14:   control and classic refuse aqg_mpdf_consumer ('depends on "mpdf/mpdf" which is not present');
#               the Composer ZIP is refused the same way (why the Classic artefact exists)
#   TYPO3 13:   reads ext_emconf.php only (never composer.json require), so the consumer activates in every case;
#               recorded, asserted equal to the control
set -uo pipefail

major=${1:?13|14}; classic_zip=$(cd "$(dirname "${2:?classic zip}")" && pwd)/$(basename "$2")
composer_zip=$(cd "$(dirname "${3:?composer zip}")" && pwd)/$(basename "$3"); work=${4:?work dir}; log=${5:?log file}
PHP=${PHP:-php}; DB_HOST=${DB_HOST:-127.0.0.1}; DB_PORT=${DB_PORT:-3306}; DB_USER=${DB_USER:-root}; DB_PASSWORD=${DB_PASSWORD:-root}
here=$(cd "$(dirname "$0")" && pwd)

case $major in
    13) version=13.4.36; source_sha=49aed89d1d90e2919c4fcc05d6fea95b8b693d3481589d74ed5215084c3fce3b ;;
    14) version=14.3.9; source_sha=4e06a884a8ec04ecb1b371cb06f7eae256c9740ff25c0a93bc13786d3ef6a7ee ;;
    *) echo "unsupported TYPO3 major $major" >&2; exit 2 ;;
esac

mkdir -p "$work" "$(dirname "$log")"; work=$(cd "$work" && pwd); : > "$log"
say() { echo "$*" | tee -a "$log"; }
failures=0
check() { if [ "$1" = "$2" ]; then say "PASS $3"; else say "FAIL $3 (expected '$2', got '$1')"; failures=$((failures + 1)); fi; }
sha() { if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | cut -d' ' -f1; else shasum -a 256 "$1" | cut -d' ' -f1; fi; }
mysql_cmd() { mysql -h "$DB_HOST" -P "$DB_PORT" -u"$DB_USER" -p"$DB_PASSWORD" "$@"; }

tarball="$work/typo3_src-$version.tar.gz"
[ -f "$tarball" ] || curl -fsSL --retry 3 -o "$tarball" "https://get.typo3.org/$version/tar.gz"
check "$(sha "$tarball")" "$source_sha" "typo3_src-$version.tar.gz matches the pinned get.typo3.org SHA-256"
[ -d "$work/typo3_src-$version" ] || tar -xzf "$tarball" -C "$work"
admin_pw=$(head -c 18 /dev/urandom | base64 | tr -d '/+=' | cut -c1-20)Aa1!

# install <label>: fresh Classic site in $work/<label>, database classic_<major>_<label>; prints nothing, logs everything
install() {
    local label=$1; local dir="$work/$1/htdocs" db="classic_${major}_$1"
    rm -rf "$work/$label"; mkdir -p "$dir"
    ln -s "$work/typo3_src-$version" "$dir/typo3_src"; ln -s typo3_src/index.php "$dir/index.php"; ln -s typo3_src/typo3 "$dir/typo3"
    mysql_cmd -e "DROP DATABASE IF EXISTS $db; CREATE DATABASE $db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" >>"$log" 2>&1
    (cd "$dir" && $PHP typo3/sysext/core/bin/typo3 setup -n --driver=mysqli --host="$DB_HOST" --port="$DB_PORT" --dbname="$db" \
        --username="$DB_USER" --password="$DB_PASSWORD" --admin-username=classic-admin --admin-user-password="$admin_pw" \
        --admin-email=admin@aqg.invalid --project-name="AQG CI Classic $major $label" --create-site=http://localhost/ --server-type=other) >>"$log" 2>&1
    echo "$?" > "$work/$label/setup.rc"
    for e in scheduler rte_ckeditor; do (cd "$dir" && $PHP typo3/sysext/core/bin/typo3 extension:activate "$e") >>"$log" 2>&1; done
}
t3() { local label=$1; shift; (cd "$work/$label/htdocs" && $PHP typo3/sysext/core/bin/typo3 "$@") 2>&1; }
activate() { local label=$1 key=$2 out rc; out=$(t3 "$label" extension:activate "$key"); rc=$?; echo "$out" >> "$log"
    if [ $rc = 0 ]; then echo ok; elif echo "$out" | grep -q 'depends on "mpdf/mpdf" which is not present'; then echo refused-mpdf; else echo "error-$rc"; fi; }
unpack() { mkdir -p "$work/$1/htdocs/typo3conf/ext/$2" && unzip -q "$3" -d "$work/$1/htdocs/typo3conf/ext/$2"; }
consumer() { cp -R "$here/Fixtures/aqg_mpdf_consumer" "$work/$1/htdocs/typo3conf/ext/aqg_mpdf_consumer"; activate "$1" aqg_mpdf_consumer; }
mpdf_loadable() { (cd "$work/$1/htdocs" && $PHP -r 'require "typo3_src/vendor/autoload.php"; echo class_exists("Mpdf\\Mpdf") ? "yes" : "no";'); }

say "== genuine Classic TYPO3 $version | classic $(basename "$classic_zip") $(sha "$classic_zip") | composer $(basename "$composer_zip") $(sha "$composer_zip")"

install control; check "$(cat "$work/control/setup.rc")" 0 "control: typo3 setup"
control_consumer=$(consumer control)
say "control (no AQG): aqg_mpdf_consumer -> $control_consumer"

install classic; check "$(cat "$work/classic/setup.rc")" 0 "classic: typo3 setup"
unpack classic a11y_quality_gate "$classic_zip"
check "$(grep -c '"mpdf/mpdf"' "$work/classic/htdocs/typo3conf/ext/a11y_quality_gate/composer.json")" 0 "classic: Classic ZIP composer.json does not require mpdf/mpdf"
check "$(activate classic a11y_quality_gate)" ok "classic: extension:activate a11y_quality_gate"
t3 classic extension:setup -e a11y_quality_gate >> "$log"; check "$?" 0 "classic: extension:setup -e a11y_quality_gate"
db="classic_${major}_classic"
mysql_cmd "$db" -e "INSERT INTO tt_content (pid, CType, header, bodytext, colPos, sys_language_uid) VALUES (1, 'text', 'Probe', '<p><a href=\"https://example.invalid/\">click here</a></p><h4>Skipped level</h4>', 0, 0);" >>"$log" 2>&1
t3 classic a11y:scan --root-pid=1 >> "$log"; check "$?" 0 "classic: a11y:scan --root-pid=1"
stored=$(mysql_cmd "$db" -N -e "SELECT COUNT(*) FROM tx_a11y_issue" 2>>"$log")
[ "${stored:-0}" -gt 0 ] && say "PASS classic: content scan stored $stored findings" || { say "FAIL classic: content scan stored no findings"; failures=$((failures + 1)); }
probe=$($PHP "$here/classic-probe.php" "$work/classic/htdocs" 2>&1 | grep -v '^PHP Deprecated'); echo "$probe" >> "$log"
check "$(echo "$probe" | grep -o 'pdfAvailable=[a-z]*' | head -1)" "pdfAvailable=no" "classic: PdfGenerator reports PDF unavailable without mPDF"
check "$(echo "$probe" | grep -oE '^local csv: [0-9]+' | head -1)" "local csv: 200" "classic: local CSV export works"
check "$(mpdf_loadable classic)" no "classic: Mpdf\\Mpdf is not loadable in the Classic runtime"
classic_consumer=$(consumer classic)
check "$classic_consumer" "$control_consumer" "classic: aqg_mpdf_consumer behaves as in the no-AQG control (no impersonation)"
check "$(grep -c "'a11y_quality_gate'" "$work/classic/htdocs/typo3conf/PackageStates.php")" 1 "classic: AQG stays active"

install composer; check "$(cat "$work/composer/setup.rc")" 0 "composer: typo3 setup"
unpack composer a11y_quality_gate "$composer_zip"
composer_activation=$(activate composer a11y_quality_gate)
say "Composer ZIP in Classic mode: activate -> $composer_activation"

if [ "$major" = 14 ]; then
    check "$control_consumer" refused-mpdf "TYPO3 14 control refuses aqg_mpdf_consumer without mPDF"
    check "$classic_consumer" refused-mpdf "TYPO3 14 with AQG Classic refuses aqg_mpdf_consumer without mPDF"
    check "$composer_activation" refused-mpdf "TYPO3 14 refuses the Composer ZIP in Classic mode (requires mPDF)"
else
    check "$control_consumer" ok "TYPO3 13 control: ext_emconf-only resolution activates aqg_mpdf_consumer (core behaviour)"
    check "$composer_activation" ok "TYPO3 13: Composer ZIP activates via ext_emconf.php (core behaviour; the Classic ZIP is the TER artefact)"
fi

say "== $failures failure(s)"
[ "$failures" = 0 ]
