<?php
// Classic runtime probe for Build/Ci/classic-coexistence.sh (F12 §27.7): the real ExportController pdf/csv actions as the
// Classic administrator of an unlicensed install. Usage: php classic-probe.php <htdocs>
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
$root = $argv[1];
chdir($root);
$classLoader = require $root . '/typo3_src/vendor/autoload.php';
putenv('TYPO3_PATH_ROOT=' . $root); putenv('TYPO3_PATH_APP=' . $root);
SystemEnvironmentBuilder::run(0, SystemEnvironmentBuilder::REQUESTTYPE_BE);
$container = Bootstrap::init($classLoader);
echo 'composerMode=', \TYPO3\CMS\Core\Core\Environment::isComposerMode() ? 'yes' : 'no', ' mpdf=', class_exists('Mpdf\\Mpdf') ? 'yes' : 'no',
    ' pdfAvailable=', \Priebera\A11yQualityGate\Export\PdfGenerator::isAvailable() ? 'yes' : 'no', PHP_EOL;
$be = new \TYPO3\CMS\Core\Authentication\BackendUserAuthentication();
$be->setBeUserByName('classic-admin'); $be->fetchGroupData(); $GLOBALS['BE_USER'] = $be;
$GLOBALS['LANG'] = $container->get(\TYPO3\CMS\Core\Localization\LanguageServiceFactory::class)->createFromUserPreferences($be);
foreach ([['scope' => 'local', 'site' => 'main', 'id' => '1', 'pageUid' => '1', 'language' => '0'], ['scope' => 'remote', 'site' => 'main', 'id' => '1', 'language' => '0']] as $q) {
    $request = (new \TYPO3\CMS\Core\Http\ServerRequest('http://localhost/typo3/module/content/a11y/export/pdf', 'GET'))->withQueryParams($q)->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
    $request = $request->withAttribute('normalizedParams', \TYPO3\CMS\Core\Http\NormalizedParams::createFromRequest($request));
    $GLOBALS['TYPO3_REQUEST'] = $request;
    $controller = $container->get(\Priebera\A11yQualityGate\Controller\ExportController::class);
    $r = $controller->pdfAction($request);
    echo $q['scope'], ' pdf: ', $r->getStatusCode(), ' ', $r->getHeaderLine('Content-Type'), ' ', substr(preg_replace('/\s+/', ' ', (string)$r->getBody()), 0, 120), PHP_EOL;
    $c = $controller->csvAction($request);
    $body = (string)$c->getBody();
    echo $q['scope'], ' csv: ', $c->getStatusCode(), ' ', $c->getHeaderLine('Content-Type'), ' rows=', max(0, substr_count(trim($body), "\n")), ' ', substr(preg_replace('/\s+/', ' ', $body), 0, 0), PHP_EOL;
}
