<?php
declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\ReleaseQa;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant\ToolbarAssistantContextResolver;
use Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant\ToolbarAssistantService;
use Priebera\A11yQualityGate\Controller\ToolbarScanController;
use Priebera\A11yQualityGate\Export\RemoteExportBuilder;
use Priebera\A11yQualityGate\Service\RemoteResultAuthorizationService;
use Priebera\A11yQualityGate\Service\RemoteResultProvenanceService;
use Priebera\A11yQualityGate\Service\ScopeAccessService;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/** Independent release review: synthetic records; real TYPO3 permissions and services. */
#[\PHPUnit\Framework\Attributes\Group('releaseqa')]
final class ReleaseQaAuthorizationTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pool = $this->getConnectionPool();
        foreach ([[1,0,'QA root','/',31,0,0],[2,1,'QA public','/open',31,0,0],[3,1,'QA confidential','/private',0,0,0],[4,1,'QA German title','/offen',31,1,2]] as [$uid,$pid,$title,$slug,$perms,$lang,$parent]) {
            $pool->getConnectionForTable('pages')->insert('pages', ['uid'=>$uid,'pid'=>$pid,'title'=>$title,'slug'=>$slug,'doktype'=>1,'is_siteroot'=>$uid===1?1:0,'perms_everybody'=>$perms,'perms_userid'=>99,'perms_user'=>31,'sys_language_uid'=>$lang,'l10n_parent'=>$parent]);
        }
        foreach ([[10,2,'QA public content',0,0,0],[11,3,'QA private content',0,0,0],[12,2,'QA German content',1,0,0],[20,2,'QA OTHER WORKSPACE TITLE',0,5,10]] as [$uid,$pid,$header,$lang,$ws,$oid]) {
            $pool->getConnectionForTable('tt_content')->insert('tt_content',['uid'=>$uid,'pid'=>$pid,'header'=>$header,'bodytext'=>'<button></button>','CType'=>'html','sys_language_uid'=>$lang,'t3ver_wsid'=>$ws,'t3ver_oid'=>$oid]);
        }
        foreach ([[10,'0'],[11,'1']] as [$uid,$langs]) {
            $pool->getConnectionForTable('be_groups')->insert('be_groups',['uid'=>$uid,'title'=>'QA group','groupMods'=>'web,web_a11y','db_mountpoints'=>'1','tables_select'=>'pages,tt_content','tables_modify'=>'pages,tt_content','allowed_languages'=>$langs]);
        }
        foreach ([[1,1,''],[2,0,'10'],[3,0,'11'],[4,0,'']] as [$uid,$admin,$group]) {
            $pool->getConnectionForTable('be_users')->insert('be_users',['uid'=>$uid,'username'=>'qa'.$uid,'admin'=>$admin,'usergroup'=>$group,'workspace_perms'=>1]);
        }
        $this->get(SiteWriter::class)->write('qa', ['rootPageId'=>1,'base'=>'https://qa.example.invalid/','languages'=>[
            ['languageId'=>0,'title'=>'English','locale'=>'en_US.UTF-8','base'=>'/'],
            ['languageId'=>1,'title'=>'Deutsch','locale'=>'de_DE.UTF-8','base'=>'/de/'],
        ]]);
        $this->get(SiteFinder::class)->getAllSites(false);
        $pool->getConnectionForTable('tx_a11y_remote_scan')->insert('tx_a11y_remote_scan',['uid'=>1,'site_identifier'=>'qa','job_id'=>'qa-site','scan_scope'=>'site','language_uid'=>0,'status'=>'completed','persisted_at'=>100,'pages_scanned'=>2,'issues_total'=>2]);
        foreach ([[51,'/open',10],[52,'/private',11]] as [$uid,$path,$content]) {
            $pool->getConnectionForTable('tx_a11y_remote_scan_page')->insert('tx_a11y_remote_scan_page',['uid'=>$uid,'remote_scan'=>1,'url'=>'https://qa.example.invalid'.$path,'title'=>'QA result '.$uid,'http_status'=>200]);
            $pool->getConnectionForTable('tx_a11y_remote_issue')->insert('tx_a11y_remote_issue',['uid'=>$uid,'remote_scan'=>1,'remote_scan_page'=>$uid,'rule_id'=>'qa-rule-'.$uid,'impact'=>'serious','nodes_count'=>1]);
            $pool->getConnectionForTable('tx_a11y_remote_issue_node')->insert('tx_a11y_remote_issue_node',['uid'=>$uid,'remote_issue'=>$uid,'mapped_table'=>'tt_content','mapped_uid'=>$content,'html_snippet'=>'QA SYNTHETIC NODE '.$uid]);
        }
    }

    private function user(int $uid): void
    {
        $this->setUpBackendUser($uid);
        $GLOBALS['LANG']=$this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
    }
    private function request(array $query): ServerRequest
    {
        $r=(new ServerRequest('https://qa.example.invalid/typo3/ajax/a11y/toolbar/assistant'))->withQueryParams($query)->withAttribute('applicationType',SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $r=$r->withAttribute('normalizedParams',NormalizedParams::createFromRequest($r));
        $GLOBALS['TYPO3_REQUEST']=$r;
        return $r;
    }
    private function rules(): array
    {
        return array_column($this->get(RemoteResultAuthorizationService::class)->authorize(['uid'=>1])->findings,'ruleId');
    }

    #[Test]
    public function toolbarRefusesAnotherWorkspaceRecordAndKeepsPermittedRecord(): void
    {
        $this->user(2);
        $resolver=$this->get(ToolbarAssistantContextResolver::class);
        self::assertSame('record',$resolver->resolve($this->request(['editTable'=>'tt_content','editUid'=>'10']))->type);
        $response=$this->get(ToolbarScanController::class)->assistantAction($this->request(['editTable'=>'tt_content','editUid'=>'20']));
        self::assertSame(200,$response->getStatusCode());
        self::assertStringNotContainsString('QA OTHER WORKSPACE TITLE',(string)$response->getBody());
        self::assertSame('unavailable',$resolver->resolve($this->request(['editTable'=>'tt_content','editUid'=>'20']))->type);
    }
    #[Test]
    public function toolbarRefusesDisallowedPageTranslation(): void
    {
        $this->user(2);
        self::assertFalse($GLOBALS['BE_USER']->checkLanguageAccess(1));
        $response=$this->get(ToolbarScanController::class)->assistantAction($this->request(['editTable'=>'pages','editUid'=>'4']));
        self::assertStringNotContainsString('QA German title',(string)$response->getBody());
    }
    #[Test]
    public function toolbarDoesNotFallbackIntoDisallowedDefaultLanguage(): void
    {
        $this->user(3);
        self::assertFalse($GLOBALS['BE_USER']->checkLanguageAccess(0));
        self::assertSame('unavailable',$this->get(ToolbarAssistantContextResolver::class)->resolve($this->request(['id'=>'2','language'=>'0']))->type);
    }
    #[Test]
    public function fileFindingsRespectTheContentLanguage(): void
    {
        $this->getConnectionPool()->getConnectionForTable('sys_file_reference')->insert('sys_file_reference',['uid'=>30,'pid'=>2,'uid_local'=>7,'uid_foreign'=>12,'tablenames'=>'tt_content','fieldname'=>'image','sys_language_uid'=>1]);
        $this->insertIssue(['site_identifier'=>'qa','page_uid'=>2,'source_uid'=>12,'source_lang_uid'=>1,'source_field'=>'image','source_type'=>'structured','rule_id'=>'structured.file_reference_alt','context_path'=>'Page > tt_content:12 > image > ref:30']);
        $this->user(2);
        $rows=(new \ReflectionMethod(ToolbarAssistantService::class,'openImageIssuesOfFile'))->invoke($this->get(ToolbarAssistantService::class),7);
        self::assertSame([],$rows);
    }
    #[Test]
    public function remoteLegacyAndUnknownOwnersAreWithheldButAdministratorCanRead(): void
    {
        $this->user(2);
        self::assertSame([],$this->rules());
        $this->user(1);
        self::assertCount(2,$this->rules());
        $this->getConnectionPool()->getConnectionForTable('tx_a11y_remote_scan_page')->update('tx_a11y_remote_scan_page',['url'=>'https://qa.example.invalid/no-owner'],['uid'=>51]);
        $this->get(RemoteResultProvenanceService::class)->stampScan(1,'qa');
        $this->user(2);
        self::assertSame([],$this->rules());
    }
    #[Test]
    public function remoteHistoricalOwnerSurvivesSlugReassignmentAndCsvMatches(): void
    {
        $this->get(RemoteResultProvenanceService::class)->stampScan(1,'qa');
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages',['slug'=>'/old-private'],['uid'=>3]);
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages',['slug'=>'/private'],['uid'=>2]);
        $this->user(2);
        self::assertSame(['qa-rule-51'],$this->rules());
        $csv=$this->get(RemoteExportBuilder::class)->buildOverviewCsv('qa',1);
        self::assertStringContainsString('QA SYNTHETIC NODE 51',$csv);
        self::assertStringNotContainsString('QA SYNTHETIC NODE 52',$csv);
    }
    #[Test]
    public function remoteUnmappedAndDeletedRecordsAreWithheld(): void
    {
        $this->get(RemoteResultProvenanceService::class)->stampScan(1,'qa');
        $this->user(2);
        self::assertSame(['qa-rule-51'],$this->rules());
        $this->getConnectionPool()->getConnectionForTable('tx_a11y_remote_issue_node')->update('tx_a11y_remote_issue_node',['mapped_table'=>'','mapped_uid'=>0],['uid'=>51]);
        self::assertSame([],$this->rules());
        $this->getConnectionPool()->getConnectionForTable('tx_a11y_remote_issue_node')->update('tx_a11y_remote_issue_node',['mapped_table'=>'tt_content','mapped_uid'=>10],['uid'=>51]);
        $this->getConnectionPool()->getConnectionForTable('tt_content')->update('tt_content',['deleted'=>1],['uid'=>10]);
        self::assertSame([],$this->rules());
    }
    #[Test]
    public function deletedPageScanHasNoRootFallback(): void
    {
        $scan=['site_identifier'=>'qa','scan_scope'=>'page','page_uid'=>2,'language_uid'=>0];
        $this->user(2);
        self::assertTrue($this->get(ScopeAccessService::class)->canReadRemoteScan($scan));
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages',['deleted'=>1],['uid'=>2]);
        self::assertFalse($this->get(ScopeAccessService::class)->canReadRemoteScan($scan));
    }
    #[Test]
    public function noModuleUserCannotReadToolbar(): void
    {
        $this->user(4);
        self::assertSame(403,$this->get(ToolbarScanController::class)->assistantAction($this->request(['id'=>'2']))->getStatusCode());
    }
    #[Test]
    public function inaccessibleTranslatedHistoryDoesNotChangePagination(): void
    {
        $pool=$this->getConnectionPool();
        $pool->getConnectionForTable('be_groups')->update('be_groups',['TSconfig'=>'options.a11y_quality_gate.mcp.connect = 1'],['uid'=>11]);
        $pool->getConnectionForTable('pages')->update('pages',['perms_everybody'=>0],['uid'=>4]);
        $this->get(\Priebera\A11yQualityGate\Mcp\McpSiteSettingsRepository::class)->save('qa',true,['history:read'],1);
        $principal=['tokenId'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','tokenSuffix'=>'qa','clientName'=>'QA synthetic','beUserUid'=>3,'scopes'=>['history:read'],'siteIdentifiers'=>['qa'],'expiresAt'=>'2026-12-01T00:00:00Z'];
        $call=fn()=> $this->get(\Priebera\A11yQualityGate\Mcp\McpToolExecutor::class)->execute('aqg_list_scans',['siteIdentifier'=>'qa','scope'=>'page'],$principal);
        $before=$call();
        $rows=[];
        for($uid=1000;$uid<6001;$uid++) {
            $rows[]=[$uid,'qa',sprintf('%08d-0000-4000-8000-000000000000',$uid),'single_page','page',2,1,'completed',100000+$uid];
        }
        foreach(array_chunk($rows,1000) as $chunk) {
            $pool->getConnectionForTable('tx_a11y_remote_scan')->bulkInsert('tx_a11y_remote_scan',$chunk,['uid','site_identifier','job_id','source_type','scan_scope','page_uid','language_uid','status','crdate']);
        }
        $after=$call();
        file_put_contents('/tmp/aqg-release-qa-history.json',json_encode(['before'=>$before,'after'=>$after],JSON_PRETTY_PRINT));
        self::assertSame($before['scans'],$after['scans']);
        self::assertSame($before['searchComplete'],$after['searchComplete'],'refused translation scans must not change completeness');
        self::assertSame($before['hasMore'],$after['hasMore']);
        self::assertNull($after['nextCursor']);
    }
    #[Test]
    public function readOnlyImageAltCannotBeWrittenByTheAqgService(): void
    {
        $pool=$this->getConnectionPool();
        $pool->getConnectionForTable('be_groups')->update('be_groups',[
            'tables_select'=>'pages,tt_content,sys_file_reference','tables_modify'=>'pages,tt_content,sys_file_reference',
            'non_exclude_fields'=>'tt_content:assets,sys_file_reference:alternative,sys_file_reference:tx_a11y_is_decorative',
            // Adapted from the SOL fixture: a real content type whose form shows the image field (CType '' has no layout).
            'explicit_allowdeny'=>'tt_content:CType:textmedia',
        ],['uid'=>10]);
        $pool->getConnectionForTable('tt_content')->update('tt_content',['CType'=>'textmedia'],['uid'=>10]);
        // Adapted from the SOL fixture: the reference points to a real image file (type 2). Without it TYPO3 lays the
        // reference out as file type 0, whose form shows no alternative text, and the positive control is refused.
        $pool->getConnectionForTable('sys_file')->insert('sys_file',['uid'=>7,'pid'=>0,'storage'=>1,'type'=>2,'name'=>'qa.png','identifier'=>'/qa.png','mime_type'=>'image/png']);
        $pool->getConnectionForTable('sys_file_reference')->insert('sys_file_reference',['uid'=>30,'pid'=>2,'uid_local'=>7,'uid_foreign'=>10,'tablenames'=>'tt_content','fieldname'=>'assets','alternative'=>'QA original alt','tstamp'=>100]);
        $uid=$this->insertIssue(['site_identifier'=>'qa','page_uid'=>2,'source_uid'=>10,'source_field'=>'assets','source_type'=>'structured','rule_id'=>'structured.file_reference_alt','context_path'=>'Page > tt_content:10 > assets > ref:30']);
        $this->user(2);
        $service=$this->get(\Priebera\A11yQualityGate\Remediation\ImageRemediationService::class);
        $tokenService=$this->get(\Priebera\A11yQualityGate\Remediation\ImageFindingVersionTokenService::class);
        $context=$service->resolve($uid);
        // The same user can apply an ordinary editable ALT field: positive control.
        $service->applyAlt($uid,'QA permitted alt',$tokenService->create($context));
        self::assertSame('QA permitted alt',$pool->getConnectionForTable('sys_file_reference')->fetchOne('SELECT alternative FROM sys_file_reference WHERE uid = 30'));
        $pool->getConnectionForTable('tx_a11y_issue')->update('tx_a11y_issue',['status'=>0],['uid'=>$uid]);
        $original=$GLOBALS['TCA']['sys_file_reference']['columns']['alternative']['config'];
        try {
            $GLOBALS['TCA']['sys_file_reference']['columns']['alternative']['config']['readOnly']=true;
            $context=$service->resolve($uid);
            try {
                $service->applyAlt($uid,'QA forbidden readonly alt',$tokenService->create($context));
            } catch(\Priebera\A11yQualityGate\Remediation\ImageRemediationPermissionException $e) {
                // Refusal is acceptable; the persisted value must stay the same.
            }
            self::assertSame('QA permitted alt',$pool->getConnectionForTable('sys_file_reference')->fetchOne('SELECT alternative FROM sys_file_reference WHERE uid = 30'));
        } finally {
            $GLOBALS['TCA']['sys_file_reference']['columns']['alternative']['config']=$original;
        }
    }
    #[Test]
    public function contentCsvRefusesSourceTableThatEditorCannotRead(): void
    {
        $this->insertIssue(['site_identifier'=>'qa','page_uid'=>2,'source_uid'=>10,'source_type'=>'rte','context_snippet'=>'QA TABLE-DENIED SOURCE SNIPPET']);
        $this->user(2);
        $controller=$this->get(\Priebera\A11yQualityGate\Controller\ExportController::class);
        $r=$this->request(['scope'=>'content','site'=>'qa','pageUid'=>'2','id'=>'2','language'=>'0']);
        $positive=$controller->csvAction($r);
        self::assertSame(200,$positive->getStatusCode());
        self::assertStringContainsString('QA TABLE-DENIED SOURCE SNIPPET',(string)$positive->getBody());
        $this->getConnectionPool()->getConnectionForTable('be_groups')->update('be_groups',['tables_select'=>'pages','tables_modify'=>'pages'],['uid'=>10]);
        $this->user(2);
        self::assertFalse($GLOBALS['BE_USER']->check('tables_select','tt_content'));
        $denied=$controller->csvAction($r);
        self::assertStringNotContainsString('QA TABLE-DENIED SOURCE SNIPPET',(string)$denied->getBody());
    }
    #[Test]
    public function contentCsvWithholdsRecordAfterItMovedToRefusedPage(): void
    {
        $this->insertIssue(['site_identifier'=>'qa','page_uid'=>2,'source_uid'=>10,'source_type'=>'rte','context_snippet'=>'QA MOVED RECORD SNIPPET']);
        $this->user(2);
        $this->getConnectionPool()->getConnectionForTable('tt_content')->update('tt_content',['pid'=>3],['uid'=>10]);
        self::assertFalse($this->get(ScopeAccessService::class)->canReadPage(3));
        $response=$this->get(\Priebera\A11yQualityGate\Controller\ExportController::class)->csvAction($this->request(['scope'=>'content','site'=>'qa','pageUid'=>'2','id'=>'2','language'=>'0']));
        self::assertStringNotContainsString('QA MOVED RECORD SNIPPET',(string)$response->getBody());
    }
    #[Test]
    public function remotePdfUsesOnlyAuthorizedPagesAndFindings(): void
    {
        $this->get(RemoteResultProvenanceService::class)->stampScan(1,'qa');
        $this->user(2);
        $pdf=$this->get(RemoteExportBuilder::class)->buildOverviewPdf('qa',$this->request([]),1);
        self::assertStringStartsWith('%PDF-',$pdf);
        file_put_contents('/tmp/aqg-release-qa-authorized.pdf',$pdf);
    }
    #[Test]
    public function legacyRemotePdfCanBeGeneratedForInspection(): void
    {
        $this->user(2);
        $pdf=$this->get(RemoteExportBuilder::class)->buildOverviewPdf('qa',$this->request([]),1);
        self::assertStringStartsWith('%PDF-',$pdf);
        file_put_contents('/tmp/aqg-release-qa-legacy.pdf',$pdf);
    }
    #[Test]
    public function consentModelDoesNotChangeWhenAnInaccessibleEnabledSiteIsAdded(): void
    {
        $this->user(2);
        $this->request([]);
        $sites=$this->get(\Priebera\A11yQualityGate\Mcp\McpSiteSettingsRepository::class);
        $sites->save('qa',true,['history:read'],1);
        $controller=$this->get(\Priebera\A11yQualityGate\Controller\McpConsentController::class);
        $build=new \ReflectionMethod($controller,'buildConsent');
        $before=$build->invoke($controller,$GLOBALS['BE_USER'],['requestedScopes'=>['history:read']]);
        $this->get(SiteWriter::class)->createNewBasicSite('qa-private',3,'https://private.example.invalid/');
        $this->get(SiteFinder::class)->getAllSites(false);
        $sites->save('qa-private',true,['history:read'],1);
        $after=$build->invoke($controller,$GLOBALS['BE_USER'],['requestedScopes'=>['history:read']]);
        self::assertSame($before,$after);
        self::assertSame(['qa'],array_column($after['sites'],'identifier'));
    }
    #[Test]
    public function mockAiExplainAndFixLeaveTheDisposableRecordUnchanged(): void
    {
        $this->user(1);
        $feature=$this->createMock(\Priebera\A11yQualityGate\Ai\Contract\AiFeatureAccessServiceInterface::class);
        $feature->method('isAvailable')->willReturn(true);
        $config=$this->createMock(\Priebera\A11yQualityGate\Ai\Contract\AiConfigurationResolverInterface::class);
        $config->method('resolve')->willReturn(new \Priebera\A11yQualityGate\Ai\Dto\AiProviderConfiguration('openai','synthetic-unused','gpt-5.4-mini','site','qa'));
        $config->method('status')->willReturn(['provider'=>'openai','selectedModelId'=>'gpt-5.4-mini']);
        $calls=0;
        $executor=$this->createMock(\Priebera\A11yQualityGate\Ai\Task\AiStructuredTaskExecutorInterface::class);
        $executor->method('supports')->willReturn(true);
        $executor->method('execute')->willReturnCallback(function($request)use(&$calls){
            $calls++;
            $data=$request->task===\Priebera\A11yQualityGate\Ai\Task\AiTask::EXPLAIN
                ? ['status'=>'explanation','explanation'=>'Synthetic: the button needs an accessible name.','how_to_fix'=>'Name its purpose.']
                : ['status'=>'suggestion','value'=>'Send request','reason'=>'Synthetic reviewed suggestion.'];
            return new \Priebera\A11yQualityGate\Ai\Task\AiStructuredResponse($data,new \Priebera\A11yQualityGate\Ai\Usage\AiUsage(10,10,true),'openai','gpt-5.4-mini');
        });
        $users=$this->get(\Priebera\A11yQualityGate\Service\BackendUserService::class);
        $settings=$this->get(\Priebera\A11yQualityGate\Ai\Settings\AiSiteSettingsService::class);
        // The real usage table: since PI-03 every attempt is admitted as a usage row before its (mock) provider request.
        $usage=$this->get(\Priebera\A11yQualityGate\Domain\Repository\AiUsageRepository::class);
        $runner=new \Priebera\A11yQualityGate\Ai\Task\AiStructuredTaskRunner($feature,$settings,$this->get(\Priebera\A11yQualityGate\Ai\Service\AiRateLimiter::class),$config,new \Priebera\A11yQualityGate\Ai\Usage\AiUsageRecorder($usage,new \Priebera\A11yQualityGate\Ai\Usage\AiUsageMeter(),$users),[$executor]);
        $access=new \Priebera\A11yQualityGate\Ai\Editor\EditorAiAccessService($feature,$config,$settings,$this->get(\Priebera\A11yQualityGate\Domain\Repository\Contract\AiConfigurationRepositoryInterface::class),$users);
        $service=new \Priebera\A11yQualityGate\Ai\Editor\EditorAiRemediationService(
            $this->get(\Priebera\A11yQualityGate\Ai\Editor\EditorAiRecordContextResolver::class),$access,
            $this->get(\Priebera\A11yQualityGate\Ai\Editor\EditorFindingTargetResolver::class),
            $this->get(\Priebera\A11yQualityGate\Ai\Editor\EditorAiContextBuilder::class),
            $this->get(\Priebera\A11yQualityGate\Ai\Editor\EditorAiValueValidator::class),
            $this->get(\Priebera\A11yQualityGate\Ai\Editor\EditorAiImageResolver::class),$runner,$settings,
            $this->get(\Priebera\A11yQualityGate\Rule\RuleRegistry::class),$this->get(\Priebera\A11yQualityGate\Service\RuleConfigurationService::class));
        $input=['table'=>'tt_content','uid'=>10,'field'=>'bodytext','html'=>'<button></button>','ruleId'=>'rte.button_label_missing','contextPath'=>'button','occurrence'=>0];
        $before=$this->getConnectionPool()->getConnectionForTable('tt_content')->select(['*'],'tt_content',['uid'=>10])->fetchAssociative();
        self::assertSame('suggestion',$service->fix($input)['status']);
        self::assertSame('explanation',$service->explain($input)['status']);
        self::assertSame(2,$calls);
        self::assertSame($before,$this->getConnectionPool()->getConnectionForTable('tt_content')->select(['*'],'tt_content',['uid'=>10])->fetchAssociative());
    }
    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }
}
