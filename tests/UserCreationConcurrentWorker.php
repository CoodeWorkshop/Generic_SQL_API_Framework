<?php

require_once __DIR__ . '/../app/Services/UserManagementService.php';

$service = new UserManagementService(new AuthRepository($argv[1]), new PasswordHasher());
try {
    $service->createUser($argv[2], $argv[3], RoleModel::READ_ONLY, false, null);
    exit(0);
} catch (ApiRequestException $exception) {
    exit($exception->getErrorCode() === 'USER_ALREADY_EXISTS' ? 2 : 3);
} catch (Throwable $exception) {
    exit(4);
}
