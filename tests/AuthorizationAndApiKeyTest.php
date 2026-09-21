<?php

require_once __DIR__ . '/../app/Configuration/RuntimeConfiguration.php';
require_once __DIR__ . '/../app/Repositories/AuthRepository.php';
require_once __DIR__ . '/../app/Repositories/AdminConfigurationRepository.php';
require_once __DIR__ . '/../app/Repositories/ApiKeyRepository.php';
require_once __DIR__ . '/../app/Services/UserManagementService.php';
require_once __DIR__ . '/../app/Services/ApiKeyService.php';
require_once __DIR__ . '/../app/Services/AuthorizationService.php';
require_once __DIR__ . '/../app/Middleware/AuthenticationMiddleware.php';
require_once __DIR__ . '/../app/Middleware/AuthorizationMiddleware.php';
require_once __DIR__ . '/../app/Middleware/DatabaseAvailabilityMiddleware.php';
require_once __DIR__ . '/../app/Middleware/CsrfProtectionMiddleware.php';
require_once __DIR__ . '/../app/Services/AdminService.php';
require_once __DIR__ . '/../app/Authorization/PrincipalContext.php';

function phase3Assert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function phase3Fails(callable $operation,string $code):void{try{$operation();}catch(ApiRequestException $exception){phase3Assert($exception->getErrorCode()===$code,"Expected {$code}, got {$exception->getErrorCode()}.");return;}throw new RuntimeException("Expected {$code}.");}
function phase3Remove(string $directory):void{if(!is_dir($directory))return;foreach(scandir($directory)?:[] as $name){if($name==='.'||$name==='..')continue;$path=$directory.DIRECTORY_SEPARATOR.$name;if(is_dir($path))phase3Remove($path);else @unlink($path);}@rmdir($directory);}

$directory=sys_get_temp_dir().DIRECTORY_SEPARATOR.'generic-sql-phase3-'.bin2hex(random_bytes(8));
$oldRuntime=getenv('GENERIC_RUNTIME_CONFIG_DIR');$oldAdmin=getenv('GENERIC_ADMIN_CONFIG_PATH');
try{
    putenv('GENERIC_RUNTIME_CONFIG_DIR='.$directory);putenv('GENERIC_ADMIN_CONFIG_PATH');RuntimeConfiguration::ensure();
    $users=new UserManagementService();
    $admin=$users->createUser('Phase3.Admin','phase-three-admin-password',true);
    $viewer=$users->createUser('Phase3.Viewer','phase-three-viewer-password',false);
    $users->assignRoles('Phase3.Viewer',['viewer']);
    $developer=$users->createUser('Phase3.Developer','phase-three-developer-password',false,true,['developer']);
    $editor=$users->createUser('Phase3.Editor','phase-three-editor-password',false,true,['data-editor']);
    phase3Assert($admin['roles']===['admin']&&$viewer['roles']===['viewer'],'Default roles were not assigned.');

    $authorization=new AuthorizationService();$middleware=new AuthorizationMiddleware($authorization);
    PrincipalContext::set(new Principal(null,'viewer','test',['viewer'],true,false));
    $middleware->handle(['action'=>'select']);$middleware->handle(['action'=>'metadata.tables']);
    phase3Fails(fn()=>$middleware->handle(['action'=>'sql','resource'=>'reports/sales']),'RESOURCE_ACCESS_DENIED');
    phase3Fails(fn()=>$middleware->handle(['action'=>'insert','resource'=>'customers']),'RESOURCE_ACCESS_DENIED');
    PrincipalContext::set(new Principal(null,'developer','test',['developer'],true,false));
    $middleware->handle(['action'=>'sql','resource'=>'reports/sales']);$middleware->handle(['action'=>'procedure']);
    phase3Fails(fn()=>$middleware->handle(['action'=>'upsert','resource'=>'customers']),'RESOURCE_ACCESS_DENIED');
    PrincipalContext::set(new Principal(null,'editor','test',['data-editor'],true,false));
    $middleware->handle(['action'=>'delete','resource'=>'customers']);
    phase3Fails(fn()=>$middleware->handle(['action'=>'metadata.tables']),'AUTHORIZATION_DENIED');
    PrincipalContext::set(new Principal(null,'admin','test',['admin'],true,true));
    foreach([['action'=>'sql','resource'=>'any/resource'],['action'=>'upsert','resource'=>'any-write'],['action'=>'admin.status']] as $request)$middleware->handle($request);
    PrincipalContext::set(new Principal(null,'disabled','test',['admin'],false,true));
    phase3Fails(fn()=>$middleware->handle(['action'=>'select']),'AUTHORIZATION_DENIED');

    $keys=new ApiKeyService();$created=$keys->create('Build integration','Phase3.Developer',['developer']);
    phase3Assert(str_starts_with($created['apiKey'],'gsk_')&&strlen($created['fingerprint'])===12,'API key was not generated safely.');
    $stored=(new ApiKeyRepository())->load();$serialized=json_encode($stored,JSON_THROW_ON_ERROR);
    phase3Assert(!str_contains($serialized,$created['apiKey'])&&isset($stored['keys'][0]['secretHash']),'Plaintext API key reached storage.');
    phase3Assert(!array_key_exists('apiKey',$keys->list()[0]),'Raw API key was displayed after creation.');
    $resolved=$keys->authenticate($created['apiKey']);phase3Assert($resolved['owner']['username']==='Phase3.Developer','API key owner was not resolved.');
    phase3Assert($keys->list()[0]['lastUsedAt']!==null,'API key last-used time was not updated.');
    $settings=AdminConfigurationRepository::defaults();$settings['authentication']['mode']='none';(new AdminConfigurationRepository())->save($settings);
    (new AuthenticationMiddleware(true))->handle(['action'=>'select']);phase3Assert(PrincipalContext::current()?->authenticationType==='none','No-auth mode did not resolve a public principal.');$middleware->handle(['action'=>'select']);phase3Fails(fn()=>$middleware->handle(['action'=>'admin.status']),'AUTHORIZATION_DENIED');
    $_SERVER['HTTP_X_API_KEY']=$created['apiKey'];$settings['authentication']['mode']='api_key';(new AdminConfigurationRepository())->save($settings);
    (new AuthenticationMiddleware(true))->handle(['action'=>'sql']);$principal=PrincipalContext::current();
    phase3Assert($principal!==null&&$principal->authenticationType==='api_key'&&$principal->roles===['developer'],'API key principal was not created.');
    $middleware->handle(['action'=>'sql','resource'=>'reports/sales']);
    $users->setEnabled('Phase3.Developer',false);phase3Fails(fn()=>(new AuthenticationMiddleware(true))->handle(['action'=>'select']),'AUTHENTICATION_REQUIRED');$users->setEnabled('Phase3.Developer',true);(new AuthenticationMiddleware(true))->handle(['action'=>'select']);
    $keys->setEnabled($created['id'],false);phase3Fails(fn()=>(new AuthenticationMiddleware(true))->handle(['action'=>'select']),'AUTHENTICATION_REQUIRED');
    $keys->setEnabled($created['id'],true);(new AuthenticationMiddleware(true))->handle(['action'=>'select']);
    $keys->revoke($created['id']);phase3Fails(fn()=>(new AuthenticationMiddleware(true))->handle(['action'=>'select']),'AUTHENTICATION_REQUIRED');
    phase3Fails(fn()=>$keys->setEnabled($created['id'],true),'API_KEY_REVOKED');
    unset($_SERVER['HTTP_X_API_KEY']);
    $settings['authentication']['mode']='session';(new AdminConfigurationRepository())->save($settings);

    $sessionName='phase3_'.bin2hex(random_bytes(4));$sessionPath=$directory.'/sessions';mkdir($sessionPath);ini_set('session.save_path',$sessionPath);
    $session=new AuthSessionService($sessionName);$storedAdmin=(new AuthRepository())->findUser('Phase3.Admin');$session->establish($storedAdmin['username'],true,$storedAdmin['id'],$storedAdmin['authVersion']);
    (new AuthenticationMiddleware(true,[], $session))->handle(['action'=>'select']);
    phase3Assert(PrincipalContext::current()?->authenticationType==='session'&&PrincipalContext::current()?->roles===['admin'],'Session principal was not created.');
    $beforeVersion=$storedAdmin['authVersion'];$users->assignRoles('Phase3.Admin',['admin','viewer']);
    phase3Assert((new AuthRepository())->findUser('Phase3.Admin')['authVersion']>$beforeVersion,'Role change did not invalidate sessions.');
    phase3Fails(fn()=>(new AuthenticationMiddleware(true,[],$session))->handle(['action'=>'select']),'AUTHENTICATION_REQUIRED');
    phase3Fails(fn()=>$users->assignRoles('Phase3.Admin',['viewer']),'LAST_ENABLED_ADMIN');

    $database=new DatabaseAvailabilityManager();$database->setAvailable(false);phase3Fails(fn()=>(new DatabaseAvailabilityMiddleware($database))->handle(['action'=>'select']),'DATABASE_UNAVAILABLE');$database->setAvailable(true);(new DatabaseAvailabilityMiddleware($database))->handle(['action'=>'select']);
    $databasePath=$directory.'/database.json';JsonFileStore::save($databasePath,['provider'=>'sqlserver','driver'=>'auto','server'=>'localhost','database'=>'test','authentication'=>'sql','username'=>'tester','password'=>'not-used','port'=>'1433','options'=>['encrypt'=>true,'trustServerCertificate'=>false]]);
    $tests=0;$adminService=new AdminService(databasePath:$databasePath,connectionTester:function(array $configuration)use(&$tests):void{$tests++;phase3Assert($configuration['password']==='not-used','Database control exposed or changed credentials.');},databaseAvailability:$database);
    $adminService->controlDatabase('disconnect');phase3Assert(!$database->available(),'Database disconnect did not close runtime availability.');
    $adminService->controlDatabase('connect');phase3Assert($database->available()&&$tests===1,'Database connect did not validate and enable availability.');
    $adminService->testCurrentDatabase();phase3Assert($database->available()&&$tests===2,'Temporary database test changed availability.');
    $adminService->controlDatabase('restart');phase3Assert($database->available()&&$tests===3,'Database restart did not reinitialize availability.');
    $adminJavaScript=(string)file_get_contents(__DIR__.'/../admin/assets/admin.js');$controlCss=(string)file_get_contents(__DIR__.'/../admin/assets/service-controls.css');
    foreach(['data-service="api"','data-service="sqlParser"','data-database="connect"','data-database="disconnect"','data-database="restart"','setButtonBusy','auth.apiKeys.create','auth.users.assignRoles'] as $marker)phase3Assert(str_contains($adminJavaScript,$marker),"Admin UI is missing {$marker}.");
    phase3Assert(str_contains($controlCss,'repeat(auto-fit')&&str_contains($controlCss,'@media'),'Service controls are not responsive.');
    unset($_SERVER['GENERIC_AUTH_PROVIDER'],$_SERVER['HTTP_X_CSRF_TOKEN']);
    foreach(['auth.users.assignRoles','auth.apiKeys.create','auth.apiKeys.disable','auth.apiKeys.revoke','admin.database.connect','admin.database.disconnect','admin.database.restart','admin.database.testCurrent'] as $action)phase3Fails(fn()=>(new CsrfProtectionMiddleware())->handle(['action'=>$action]),'CSRF_VALIDATION_FAILED');
    echo "Authorization and API key tests passed.\n";
}finally{
    if(session_status()===PHP_SESSION_ACTIVE)session_destroy();unset($_SERVER['HTTP_X_API_KEY']);
    $oldRuntime===false?putenv('GENERIC_RUNTIME_CONFIG_DIR'):putenv('GENERIC_RUNTIME_CONFIG_DIR='.$oldRuntime);
    $oldAdmin===false?putenv('GENERIC_ADMIN_CONFIG_PATH'):putenv('GENERIC_ADMIN_CONFIG_PATH='.$oldAdmin);phase3Remove($directory);
}
