<?php

require_once __DIR__ . '/Middleware.php';
require_once __DIR__ . '/../Authorization/PrincipalContext.php';
require_once __DIR__ . '/../Services/AuthorizationService.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';

final class AuthorizationMiddleware extends Middleware
{
    public function __construct(private ?AuthorizationService $authorization = null) { $this->authorization ??= new AuthorizationService(); }
    public function handle(array $request): void
    {
        $principal = PrincipalContext::current();
        if ($principal === null) throw new ApiRequestException('Authentication required.', 'AUTHENTICATION_REQUIRED', [], 401);
        $action = (string)($request['action'] ?? '');
        if (str_starts_with($action, 'metadata.')) { $this->authorization->authorize($principal, 'metadata.read'); return; }
        if ($action === 'sql') { $this->authorization->authorize($principal, 'sql.execute', is_string($request['resource'] ?? null) ? $request['resource'] : '', 'sql'); return; }
        if (in_array($action, ['insert', 'update', 'delete', 'upsert'], true)) { $this->authorization->authorize($principal, 'data.write', is_string($request['resource'] ?? null) ? $request['resource'] : '', 'write'); return; }
        if (in_array($action, ['procedure', 'function', 'tableFunction'], true)) { $this->authorization->authorize($principal, 'routine.execute'); return; }
        if (in_array($action, ['select', 'union', 'unionAll'], true)) { $this->authorization->authorize($principal, 'data.read'); return; }
        $this->authorization->authorize($principal, 'admin.manage');
    }
}
