<?php

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../Services/UserManagementService.php';
require_once __DIR__ . '/../Authorization/PrincipalContext.php';

final class FrontendUserController extends BaseController
{
    public function __construct(private ?UserManagementService $users=null){$this->users??=new UserManagementService();}
    public function dispatch(array$request):void
    {
        $action=$request['action'];$actor=PrincipalContext::current();
        if($actor===null)throw new ApiRequestException('Authentication required.','AUTHENTICATION_REQUIRED',[],401);
        if($action==='auth.frontendUsers.list'){$this->success($this->users->listFrontendUsers(),'Frontend users loaded.');}
        if($action==='auth.frontendUsers.create'){$result=$this->users->createFrontendUser($actor,$request['username'],$request['password'],$request['role'],$request['enabled']);$this->success([$result],'Frontend user created.',201);}
        if($action==='auth.frontendUsers.update')$result=$this->users->updateFrontendUsername($actor,$request['username'],$request['newUsername']);
        elseif($action==='auth.frontendUsers.enable')$result=$this->users->setFrontendUserEnabled($actor,$request['username'],true);
        elseif($action==='auth.frontendUsers.disable')$result=$this->users->setFrontendUserEnabled($actor,$request['username'],false);
        elseif($action==='auth.frontendUsers.delete')$result=$this->users->deleteFrontendUser($actor,$request['username']);
        elseif($action==='auth.frontendUsers.changePassword')$result=$this->users->changeFrontendUserPassword($actor,$request['username'],$request['newPassword']);
        else $result=$this->users->assignFrontendAccess($actor,$request['username'],$request['frontendAccess'],$request['frontendRole']);
        $this->success([$result],'Frontend user updated.');
    }
}
