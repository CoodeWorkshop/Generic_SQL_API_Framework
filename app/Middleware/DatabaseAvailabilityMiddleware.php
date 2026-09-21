<?php

require_once __DIR__ . '/Middleware.php';
require_once __DIR__ . '/../Runtime/DatabaseAvailabilityManager.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';

final class DatabaseAvailabilityMiddleware extends Middleware
{
    public function __construct(private ?DatabaseAvailabilityManager $manager=null){$this->manager??=new DatabaseAvailabilityManager();}
    public function handle(array $request):void{if(!$this->manager->available())throw new ApiRequestException('Database access is currently unavailable.','DATABASE_UNAVAILABLE',[],503);}
}
