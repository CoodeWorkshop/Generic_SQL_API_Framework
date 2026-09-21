<?php

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../Services/AuthorizationService.php';

final class RoleController extends BaseController
{
    public function list(array $request):void { $roles=[]; foreach((new AuthorizationService())->roles() as $id=>$role)$roles[]=['id'=>$id,...$role]; $this->success($roles,'Roles and permissions loaded.'); }
}
