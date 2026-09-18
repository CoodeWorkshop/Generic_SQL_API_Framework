<?php

final class AuthSessionService
{
    private const AUTH_KEY = 'generic_reporting_auth';

    private string $sessionName;

    public function __construct(string $sessionName = 'generic_reporting_session')
    {
        $this->sessionName = $sessionName;
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if (headers_sent()) {
            throw new RuntimeException('Session cannot be started after response output.');
        }

        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_strict_mode', '1');
        session_name($this->sessionName);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $this->isHttpsRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        if (!session_start()) {
            throw new RuntimeException('Session could not be started.');
        }
    }

    public function resume(): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }
        if (!isset($_COOKIE[$this->sessionName]) || !is_string($_COOKIE[$this->sessionName])) {
            return false;
        }
        $this->start();
        return true;
    }

    public function isAuthenticated(): bool
    {
        $identity = $this->identity();
        return ($identity['authenticated'] ?? false) === true
            && is_string($identity['username'] ?? null)
            && $identity['username'] !== ''
            && is_bool($identity['isAdmin'] ?? null);
    }

    public function authenticatedUsername(): ?string
    {
        return $this->isAuthenticated() ? $_SESSION[self::AUTH_KEY]['username'] : null;
    }

    public function authenticatedUserIsAdmin(): bool
    {
        return $this->isAuthenticated() && $_SESSION[self::AUTH_KEY]['isAdmin'] === true;
    }

    public function establish(string $username, bool $isAdmin): void
    {
        $username = trim($username);
        if ($username === '') {
            throw new InvalidArgumentException('Authenticated username is required.');
        }

        $this->start();
        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Session identifier could not be regenerated.');
        }
        $_SESSION[self::AUTH_KEY] = [
            'authenticated' => true,
            'username' => $username,
            'isAdmin' => $isAdmin,
        ];
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

    private function isHttpsRequest(): bool
    {
        return isset($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    }
}
