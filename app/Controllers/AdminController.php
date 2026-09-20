<?php

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../Services/AdminService.php';

final class AdminController extends BaseController
{
    private AdminService $service;

    public function __construct(?AdminService $service = null)
    {
        $this->service = $service ?? new AdminService();
    }

    public function dispatch(array $request): void
    {
        $action = $request['action'];
        if ($action === 'admin.status') $result = $this->service->status();
        elseif ($action === 'admin.database.get') $result = $this->service->databaseConfiguration();
        elseif ($action === 'admin.database.test') $result = $this->service->testDatabase($request['database']);
        elseif ($action === 'admin.database.save') $result = $this->service->saveDatabase($request['database']);
        elseif ($action === 'admin.settings.get') $result = $this->service->settings();
        elseif ($action === 'admin.cors.save') $result = $this->service->saveCors($request['cors']);
        elseif ($action === 'admin.authentication.save') $result = $this->service->saveAuthentication($request['mode']);
        else $result = ['result' => $this->service->convertSql($request['sql'])];

        $this->success([$result], 'Admin operation completed.');
    }
}
