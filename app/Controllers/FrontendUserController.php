<?php

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../Services/UserManagementService.php';
require_once __DIR__ . '/../Services/AuthSessionService.php';

final class FrontendUserController extends BaseController
{
    public function __construct(private ?UserManagementService $users=null,private ?AuthSessionService $session=null){$this->users??=new UserManagementService();$this->session??=new AuthSessionService();}
    public function dispatch(array$request):void
    {
        $action=$request['action'];
        if($action==='auth.frontendUsers.list'){$this->success($this->users->listFrontendUsers(),'Frontend users loaded.');}
        if($action==='auth.frontendUsers.create'){$result=$this->users->createFrontendUser($request['username'],$request['password'],$request['frontendRole'],$request['enabled']);$this->success([$result],'Frontend user created.',201);}
        if($action==='auth.frontendUsers.update')$result=$this->users->updateUsername($request['username'],$request['newUsername'],true);
        elseif($action==='auth.frontendUsers.enable')$result=$this->users->setEnabled($request['username'],true,true);
        elseif($action==='auth.frontendUsers.disable')$result=$this->users->setEnabled($request['username'],false,true);
        elseif($action==='auth.frontendUsers.delete')$result=$this->users->deleteUser($request['username'],(string)$this->session->authenticatedUsername(),true);
        elseif($action==='auth.frontendUsers.changePassword')$result=$this->users->changePassword($request['username'],$request['newPassword'],true);
        else $result=$this->users->assignFrontendAuthorization($request['username'],$request['frontendRole']);
        $this->success([$result],'Frontend user updated.');
    }
}
