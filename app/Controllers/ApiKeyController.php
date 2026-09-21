<?php

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../Services/ApiKeyService.php';

final class ApiKeyController extends BaseController
{
    public function __construct(private ?ApiKeyService $service=null){$this->service??=new ApiKeyService();}
    public function dispatch(array $request):void {
        $action=$request['action'];
        if($action==='auth.apiKeys.list'){$data=$this->service->list();$message='API keys loaded.';}
        elseif($action==='auth.apiKeys.create'){$data=[$this->service->create($request['name'],$request['ownerUsername'],$request['roles'])];$message='API key created. Copy it now; it cannot be displayed again.';}
        elseif($action==='auth.apiKeys.enable'){$data=[$this->service->setEnabled($request['id'],true)];$message='API key enabled.';}
        elseif($action==='auth.apiKeys.disable'){$data=[$this->service->setEnabled($request['id'],false)];$message='API key disabled.';}
        else{$data=[$this->service->revoke($request['id'])];$message='API key revoked.';}
        $this->success($data,$message,$action==='auth.apiKeys.create'?201:200);
    }
}
