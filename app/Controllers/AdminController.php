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
        if ($action === 'admin.status' || $action === 'admin.health') $result = $this->service->status();
        elseif ($action === 'admin.system.info') $result = $this->service->systemInformation();
        elseif ($action === 'admin.api.start') $result = $this->service->controlApi('start');
        elseif ($action === 'admin.api.stop') $result = $this->service->controlApi('stop');
        elseif ($action === 'admin.api.restart') $result = $this->service->controlApi('restart');
        elseif ($action === 'admin.database.get') $result = $this->service->databaseConfiguration();
        elseif ($action === 'admin.database.test') $result = $this->service->testDatabase($request['database']);
        elseif ($action === 'admin.database.save') $result = $this->service->saveDatabase($request['database']);
        elseif ($action === 'admin.settings.get') $result = $this->service->settings();
        elseif ($action === 'admin.server.save') $result = $this->service->saveServer($request['server']);
        elseif ($action === 'admin.features.save') $result = $this->service->saveFeatures($request['features']);
        elseif ($action === 'admin.cors.save') $result = $this->service->saveCors($request['cors']);
        elseif ($action === 'admin.authentication.save') $result = $this->service->saveAuthentication($request['mode']);
        else $result = $this->service->saveAuthentication($request['mode']);

        $this->success([$result], 'Admin operation completed.');
    }
}
