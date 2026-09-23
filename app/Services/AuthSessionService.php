<?php

require_once __DIR__ . '/../Security/SecurityConfiguration.php';

final class AuthSessionService
{
    private const AUTH_KEY = 'generic_reporting_auth';
    private const META_KEY = 'generic_reporting_session_meta';

    private string $sessionName;
    private array $options;

    public function __construct(string $sessionName = 'generic_reporting_session', ?array $options = null)
    {
        $this->sessionName = $sessionName;
        $this->options = $options ?? SecurityConfiguration::sessionOptions();
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if (headers_sent()) {
            throw new RuntimeException('Session cannot be started after response output.');
        }

        $absoluteTimeout = max(300, (int)($this->options['absoluteTimeout'] ?? 28800));
        $this->requireIniSetting('session.use_cookies', '1');
        $this->requireIniSetting('session.use_only_cookies', '1');
        $this->requireIniSetting('session.use_strict_mode', '1');
        $this->requireIniSetting('session.use_trans_sid', '0');
        $this->requireIniSetting('session.cookie_lifetime', '0');
        $this->requireIniSetting('session.gc_maxlifetime', (string)$absoluteTimeout);
        session_name($this->sessionName);
        session_set_cookie_params([
            'lifetime' => (int)($this->options['lifetime'] ?? 0),
            'path' => (string)($this->options['path'] ?? '/'),
            'domain' => (string)($this->options['domain'] ?? ''),
            'secure' => ($this->options['secure'] ?? false) === true,
            'httponly' => ($this->options['httponly'] ?? true) === true,
            'samesite' => (string)($this->options['samesite'] ?? 'Lax'),
        ]);

        if (!session_start()) {
            throw new RuntimeException('Session could not be started.');
        }
    }

    public function resume(): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return !$this->expireIfNecessary();
        }
        if (!isset($_COOKIE[$this->sessionName]) || !is_string($_COOKIE[$this->sessionName])) {
            return false;
        }
        $this->start();
        return !$this->expireIfNecessary();
    }

    public function isAuthenticated(): bool
    {
        $identity = $this->identity();
        return ($identity['authenticated'] ?? false) === true
            && is_string($identity['username'] ?? null)
            && $identity['username'] !== ''
            && is_string($identity['userId'] ?? null)
            && preg_match('/^[a-f0-9]{32}$/', $identity['userId']) === 1
            && is_int($identity['authVersion'] ?? null)
            && $identity['authVersion'] >= 1;
    }

    public function authenticatedUsername(): ?string
    {
        return $this->isAuthenticated() ? $_SESSION[self::AUTH_KEY]['username'] : null;
    }

    public function authenticatedUserId(): ?string
    {
        return $this->isAuthenticated() ? $_SESSION[self::AUTH_KEY]['userId'] : null;
    }

    public function authenticatedAuthVersion(): ?int
    {
        return $this->isAuthenticated() ? $_SESSION[self::AUTH_KEY]['authVersion'] : null;
    }

    public function establish(
        string $username,
        string $userId,
        int $authVersion
    ): void
    {
        $username = trim($username);
        if ($username === '') {
            throw new InvalidArgumentException('Authenticated username is required.');
        }
        if (preg_match('/^[a-f0-9]{32}$/', $userId) !== 1 || $authVersion < 1) {
            throw new InvalidArgumentException('Authenticated user identity is invalid.');
        }

        $this->start();
        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Session identifier could not be regenerated.');
        }
        $_SESSION[self::AUTH_KEY] = [
            'authenticated' => true,
            'username' => $username,
            'userId' => $userId,
            'authVersion' => $authVersion,
        ];
        $now = time();
        $_SESSION[self::META_KEY] = ['createdAt' => $now, 'lastActivity' => $now];
    }

    public function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE && !$this->resume()) {
            return;
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $parameters = session_get_cookie_params();
            setcookie($this->sessionName, '', [
                'expires' => time() - 42000,
                'path' => $parameters['path'],
                'domain' => $parameters['domain'],
                'secure' => $parameters['secure'],
                'httponly' => $parameters['httponly'],
                'samesite' => $parameters['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
    }

    private function identity(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return [];
        }
        $identity = $_SESSION[self::AUTH_KEY] ?? null;
        return is_array($identity) ? $identity : [];
    }

    private function expireIfNecessary(): bool
    {
        if (!isset($_SESSION[self::AUTH_KEY])) return false;
        $metadata = $_SESSION[self::META_KEY] ?? null;
        $now = time();
        $idleTimeout = max(60, (int)($this->options['idleTimeout'] ?? 1800));
        $absoluteTimeout = max(300, (int)($this->options['absoluteTimeout'] ?? 28800));
        if (!is_array($metadata)
            || !is_int($metadata['createdAt'] ?? null)
            || !is_int($metadata['lastActivity'] ?? null)
            || $now - $metadata['lastActivity'] > $idleTimeout
            || $now - $metadata['createdAt'] > $absoluteTimeout) {
            $this->destroy();
            return true;
        }
        $_SESSION[self::META_KEY]['lastActivity'] = $now;
        return false;
    }

    private function requireIniSetting(string $name, string $value): void
    {
        ini_set($name, $value);
        if ((string)ini_get($name) !== $value) {
            throw new RuntimeException("Required session setting {$name} could not be applied.");
        }
    }
}
