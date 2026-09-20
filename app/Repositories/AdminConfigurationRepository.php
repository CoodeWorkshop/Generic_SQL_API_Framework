<?php

require_once __DIR__ . '/../../core/JsonFileStore.php';

final class AdminConfigurationRepository
{
    public const AUTHENTICATION_MODES = ['none', 'session', 'api_key', 'session+api_key'];
    public const ALLOWED_CORS_METHODS = ['POST', 'OPTIONS'];

    private string $path;

    public function __construct(?string $path = null)
    {
        $configuredPath = getenv('GENERIC_ADMIN_CONFIG_PATH');
        $this->path = $path
            ?? ($configuredPath !== false && trim($configuredPath) !== ''
                ? trim($configuredPath)
                : dirname(__DIR__, 2) . '/config/admin.json');
    }

    public function load(): array
    {
        if (!is_file($this->path)) {
            return self::defaults();
        }
        $configuration = JsonFileStore::load($this->path);
        $this->validate($configuration);
        return $configuration;
    }

    public function save(array $configuration): void
    {
        $this->validate($configuration);
        JsonFileStore::save($this->path, $configuration);
    }

    public static function defaults(): array
    {
        return [
            'version' => 1,
            'cors' => [
                'allowedOrigins' => [
                    'http://127.0.0.1:5173',
                    'http://localhost:5173',
                    'http://127.0.0.1:5314',
                    'http://localhost:5314',
                    'http://127.0.0.1:5341',
                    'http://localhost:5341',
                ],
                'credentialsEnabled' => true,
                'allowedMethods' => ['POST', 'OPTIONS'],
            ],
            'authentication' => ['mode' => 'session'],
        ];
    }

    public static function normalizeOrigin(string $origin): string
    {
        $origin = trim($origin);
        if ($origin === '' || $origin === '*'
            || filter_var($origin, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Allowed origins must be absolute HTTP or HTTPS origins.');
        }
        $parts = parse_url($origin);
        if (!is_array($parts)
            || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
            || !isset($parts['host'])
            || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])
            || (($parts['path'] ?? '') !== '' && ($parts['path'] ?? '') !== '/')) {
            throw new InvalidArgumentException('Allowed origins must not contain paths, credentials, queries, or fragments.');
        }
        $normalized = strtolower((string)$parts['scheme']) . '://' . strtolower((string)$parts['host']);
        if (isset($parts['port'])) {
            $normalized .= ':' . (int)$parts['port'];
        }
        return $normalized;
    }

    private function validate(array $configuration): void
    {
        if (array_diff(array_keys($configuration), ['version', 'cors', 'authentication']) !== []
            || ($configuration['version'] ?? null) !== 1
            || !is_array($configuration['cors'] ?? null)
            || !is_array($configuration['authentication'] ?? null)) {
            throw new RuntimeException('Invalid admin configuration.');
        }
        $cors = $configuration['cors'];
        if (array_diff(array_keys($cors), ['allowedOrigins', 'credentialsEnabled', 'allowedMethods']) !== []
            || !is_array($cors['allowedOrigins'] ?? null)
            || !array_is_list($cors['allowedOrigins'])
            || !is_bool($cors['credentialsEnabled'] ?? null)
            || !is_array($cors['allowedMethods'] ?? null)
            || !array_is_list($cors['allowedMethods'])
            || $cors['allowedMethods'] === []) {
            throw new RuntimeException('Invalid CORS configuration.');
        }
        $origins = [];
        foreach ($cors['allowedOrigins'] as $origin) {
            if (!is_string($origin)) throw new RuntimeException('Invalid CORS configuration.');
            try {
                $normalized = self::normalizeOrigin($origin);
            } catch (InvalidArgumentException $exception) {
                throw new RuntimeException('Invalid CORS configuration.');
            }
            if ($normalized !== $origin || isset($origins[$origin])) {
                throw new RuntimeException('Invalid CORS configuration.');
            }
            $origins[$origin] = true;
        }
        $methods = array_values(array_unique(array_map('strtoupper', $cors['allowedMethods'])));
        if ($methods !== $cors['allowedMethods']
            || array_diff($methods, self::ALLOWED_CORS_METHODS) !== []) {
            throw new RuntimeException('Invalid CORS configuration.');
        }
        $authentication = $configuration['authentication'];
        if (array_keys($authentication) !== ['mode']
            || !in_array($authentication['mode'] ?? null, self::AUTHENTICATION_MODES, true)) {
            throw new RuntimeException('Invalid authentication configuration.');
        }
    }
}
