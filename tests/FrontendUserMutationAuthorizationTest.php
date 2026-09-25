<?php

require_once __DIR__ . '/../app/Services/UserManagementService.php';
require_once __DIR__ . '/../app/Requests/FrontendUserRequestValidator.php';

function frontendMutationAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function frontendMutationDenied(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (ApiRequestException $exception) {
        frontendMutationAssert($exception->getErrorCode() === 'AUTHORIZATION_DENIED', $message . ' Wrong error code.');
        frontendMutationAssert($exception->getStatusCode() === 403, $message . ' Wrong HTTP status.');
        return;
    }
    throw new RuntimeException($message);
}

function frontendMutationPrincipal(AuthRepository $repository, string $username): Principal
{
    $user = $repository->findUser($username);
    if ($user === null) throw new RuntimeException("Missing test user {$username}.");
    return new Principal(
        $user['id'], $user['username'], 'session', $user['backendRole'],
        $user['frontendAccess'], $user['frontendRole'], $user['enabled']
    );
}

function frontendMutationRemove(string $path): void
{
    if (!is_dir($path)) return;
    foreach (glob($path . DIRECTORY_SEPARATOR . '*') ?: [] as $item) @unlink($item);
    @rmdir($path);
}

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'generic-frontend-user-policy-' . bin2hex(random_bytes(8));
$authPath = $directory . DIRECTORY_SEPARATOR . 'auth.json';
$logPath = $directory . DIRECTORY_SEPARATOR . 'logs';

try {
    mkdir($directory, 0700, true);
    mkdir($logPath, 0700, true);
    $repository = new AuthRepository($authPath);
    $repository->save(['version' => 4, 'users' => []]);
    $service = new UserManagementService($repository, new PasswordHasher(), new Logger($logPath));

    $service->createUser('Super.Admin', 'super-admin-password', RoleModel::SYSTEM_ADMINISTRATOR, true, RoleModel::APPLICATION_ADMINISTRATOR);
    $service->createUser('Backend.Only.Super', 'backend-only-super-password', RoleModel::SYSTEM_ADMINISTRATOR, false, null);
    $service->createUser('Application.Admin', 'application-admin-password', null, true, RoleModel::APPLICATION_ADMINISTRATOR);
    $service->createUser('Protected.Admin', 'protected-admin-password', null, true, RoleModel::APPLICATION_ADMINISTRATOR);
    $service->createUser('Normal.User', 'normal-user-password', null, true, null);
    $service->createUser('Disposable.User', 'disposable-password', null, true, null);
    $service->createUser('Access.Removal', 'access-removal-password', null, true, null);
    $service->createUser('No.Access', 'no-access-password', null, false, null, false);
    $service->createUser('Normal.Actor', 'normal-actor-password', null, true, null);

    $admin = frontendMutationPrincipal($repository, 'Application.Admin');
    $superAdmin = frontendMutationPrincipal($repository, 'Super.Admin');

    $normalActor = $repository->findUser('Normal.Actor');
    $forgedAdmin = new Principal(
        $normalActor['id'], $normalActor['username'], 'session', null,
        true, RoleModel::APPLICATION_ADMINISTRATOR, true
    );
    frontendMutationDenied(
        fn () => $service->updateFrontendUsername($forgedAdmin, 'Normal.User', 'Forged.Change'),
        'Client-supplied actor roles overrode persisted authorization.'
    );

    // Application Admin may manage only normal frontend identities.
    $service->updateFrontendUsername($admin, 'Normal.User', 'Normal.Renamed');
    frontendMutationAssert($repository->findUser('Normal.Renamed') !== null, 'Admin could not modify a normal frontend user.');
    $service->createFrontendUser($admin, 'Added.User', 'added-user-password', null);
    frontendMutationAssert($repository->findUser('Added.User') !== null, 'Admin could not add a normal frontend user.');
    $service->createFrontendUser($admin, 'Added.Admin', 'added-admin-password', RoleModel::APPLICATION_ADMINISTRATOR);
    frontendMutationAssert($repository->findUser('Added.Admin')['frontendRole'] === RoleModel::APPLICATION_ADMINISTRATOR, 'Admin could not create another Admin.');
    $service->createFrontendUser($admin, 'Added.Operator', 'added-operator-password', RoleModel::DATA_OPERATOR);
    frontendMutationAssert($repository->findUser('Added.Operator')['backendRole'] === RoleModel::DATA_OPERATOR, 'Admin could not create a Data Operator.');
    $service->createFrontendUser($admin, 'Added.Reader', 'added-reader-password', RoleModel::READ_ONLY);
    frontendMutationAssert($repository->findUser('Added.Reader')['backendRole'] === RoleModel::READ_ONLY, 'Admin could not create a Read Only user.');
    $service->setFrontendUserEnabled($admin, 'Added.Operator', false);
    $service->deleteFrontendUser($admin, 'Added.Reader');
    $service->assignFrontendAccess($admin, 'No.Access', true, null);
    frontendMutationAssert($repository->findUser('No.Access')['frontendAccess'] === true, 'Admin could not grant frontend access.');
    $service->assignFrontendAccess($admin, 'Access.Removal', false, null);
    $removed = $repository->findUser('Access.Removal');
    frontendMutationAssert($removed['frontendAccess'] === false && $removed['enabled'] === false, 'Frontend access removal did not preserve the enabled-user access invariant.');
    $service->setFrontendUserEnabled($admin, 'Normal.Renamed', false);
    frontendMutationAssert($repository->findUser('Normal.Renamed')['enabled'] === false, 'Admin could not disable a normal frontend user.');
    $service->setFrontendUserEnabled($admin, 'Normal.Renamed', true);
    $service->deleteFrontendUser($admin, 'Disposable.User');
    frontendMutationAssert($repository->findUser('Disposable.User') === null, 'Admin could not delete a normal frontend user.');

    frontendMutationDenied(
        fn () => $service->createFrontendUser($admin, 'Unsafe.Super', 'unsafe-super-password', RoleModel::SYSTEM_ADMINISTRATOR),
        'Admin created a Super Admin.'
    );
    frontendMutationDenied(
        fn () => $service->assignFrontendAccess($admin, 'Normal.Renamed', true, RoleModel::APPLICATION_ADMINISTRATOR),
        'Admin promoted a normal user to Admin.'
    );

    foreach ([
        fn () => $service->updateFrontendUsername($admin, 'Super.Admin', 'Changed.Super'),
        fn () => $service->changeFrontendUserPassword($admin, 'Super.Admin', 'changed-super-password'),
        fn () => $service->setFrontendUserEnabled($admin, 'Super.Admin', false),
        fn () => $service->deleteFrontendUser($admin, 'Super.Admin'),
        fn () => $service->assignFrontendAccess($admin, 'Super.Admin', true, null),
        fn () => $service->assignFrontendAccess($admin, 'Super.Admin', false, null),
    ] as $operation) frontendMutationDenied($operation, 'Admin modified a Super Admin.');

    foreach ([
        fn () => $service->updateFrontendUsername($admin, 'Protected.Admin', 'Changed.Admin'),
        fn () => $service->changeFrontendUserPassword($admin, 'Protected.Admin', 'changed-admin-password'),
        fn () => $service->assignFrontendAccess($admin, 'Protected.Admin', true, null),
        fn () => $service->assignFrontendAccess($admin, 'Protected.Admin', false, null),
        fn () => $service->setFrontendUserEnabled($admin, 'Protected.Admin', false),
        fn () => $service->deleteFrontendUser($admin, 'Protected.Admin'),
    ] as $operation) frontendMutationDenied($operation, 'Admin modified another protected Admin.');

    foreach ([
        fn () => $service->updateFrontendUsername($admin, 'Application.Admin', 'Changed.Self'),
        fn () => $service->changeFrontendUserPassword($admin, 'Application.Admin', 'changed-self-password'),
        fn () => $service->assignFrontendAccess($admin, 'Application.Admin', true, null),
        fn () => $service->assignFrontendAccess($admin, 'Application.Admin', false, null),
        fn () => $service->setFrontendUserEnabled($admin, 'Application.Admin', false),
        fn () => $service->deleteFrontendUser($admin, 'Application.Admin'),
    ] as $operation) frontendMutationDenied($operation, 'Admin modified its own protected identity.');

    // System Administrator retains full frontend-user management authority.
    $service->assignFrontendAccess($superAdmin, 'Normal.Renamed', true, RoleModel::APPLICATION_ADMINISTRATOR);
    frontendMutationAssert($repository->findUser('Normal.Renamed')['frontendRole'] === RoleModel::APPLICATION_ADMINISTRATOR, 'Super Admin could not grant Admin access.');
    $service->assignFrontendAccess($superAdmin, 'Normal.Renamed', true, null);
    frontendMutationAssert($repository->findUser('Normal.Renamed')['frontendRole'] === null, 'Super Admin could not remove Admin access.');
    $service->updateFrontendUsername($superAdmin, 'Protected.Admin', 'Managed.Admin');
    $service->setFrontendUserEnabled($superAdmin, 'Managed.Admin', false);
    $service->deleteFrontendUser($superAdmin, 'Managed.Admin');
    frontendMutationAssert($repository->findUser('Managed.Admin') === null, 'Super Admin could not manage an Admin.');
    $service->assignFrontendAccess($superAdmin, 'Added.User', false, null);
    frontendMutationAssert($repository->findUser('Added.User')['frontendAccess'] === false, 'Super Admin could not manage a normal frontend user.');
    $service->createFrontendUser($superAdmin, 'Super.Created.Admin', 'super-created-admin-password', RoleModel::APPLICATION_ADMINISTRATOR);
    frontendMutationAssert(
        $repository->findUser('Super.Created.Admin')['frontendRole'] === RoleModel::APPLICATION_ADMINISTRATOR,
        'Super Admin could not create an Admin.'
    );

    $listed = $service->listFrontendUsers();
    frontendMutationAssert(
        count(array_filter($listed, fn (array $user): bool => $user['username'] === 'Added.User' && $user['frontendAccess'] === false)) === 1,
        'A frontend-only user disappeared after access was removed.'
    );
    frontendMutationAssert(
        count(array_filter($listed, fn (array $user): bool => $user['username'] === 'Backend.Only.Super'
            && $user['backendRole'] === RoleModel::SYSTEM_ADMINISTRATOR
            && $user['backendProtected'] === true)) === 1,
        'A backend-only Super Admin was not represented as protected in the frontend list.'
    );

    $validator = new FrontendUserRequestValidator();
    $removal = $validator->validate([
        'action' => 'auth.frontendUsers.assignRole',
        'username' => 'Added.User',
        'frontendAccess' => false,
        'frontendRole' => null,
    ]);
    frontendMutationAssert($removal['frontendAccess'] === false && $removal['frontendRole'] === null, 'Frontend access removal was not validated.');
    foreach ([RoleModel::APPLICATION_ADMINISTRATOR, RoleModel::DATA_OPERATOR, RoleModel::READ_ONLY] as $role) {
        $creation = $validator->validate([
            'action' => 'auth.frontendUsers.create',
            'username' => 'Validated.' . str_replace('-', '.', $role),
            'password' => 'validated-user-password',
            'passwordConfirmation' => 'validated-user-password',
            'role' => $role,
        ]);
        frontendMutationAssert($creation['role'] === $role, "Create role {$role} was not validated.");
    }

    echo "Frontend user mutation authorization tests passed.\n";
} finally {
    frontendMutationRemove($logPath);
    @unlink($authPath);
    @unlink($authPath . '.lock');
    @rmdir($directory);
}
