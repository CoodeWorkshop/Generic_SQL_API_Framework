<?php

require_once __DIR__ . '/Middleware.php';
require_once __DIR__ . '/../Authorization/PrincipalContext.php';
require_once __DIR__ . '/../Services/AuthorizationService.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';

final class FrontendUserAuthorizationMiddleware extends Middleware
{
    public function __construct(private array $actions,private ?AuthorizationService $authorization=null){$this->authorization??=new AuthorizationService();}
    public function handle(array$request):void
    {
        if(!in_array($request['action']??null,$this->actions,true))return;
        $principal=PrincipalContext::current();if($principal===null)throw new ApiRequestException('Authentication required.','AUTHENTICATION_REQUIRED',[],401);
        $this->authorization->authorize($principal,'frontend.users.manage');
    }
}
