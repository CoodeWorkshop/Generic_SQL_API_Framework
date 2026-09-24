<?php

require_once __DIR__ . '/../app/Services/AuthSessionService.php';

if ($argc !== 4) exit(2);
[$script, $sessionName, $sessionPath, $sessionId] = $argv;
ini_set('session.save_path', $sessionPath);
$_COOKIE[$sessionName] = $sessionId;

$session = new AuthSessionService($sessionName, [
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
    'idleTimeout' => 600,
    'absoluteTimeout' => 3600,
]);
if (!$session->resume() || !$session->isAuthenticated()) exit(3);

$counter = $_SESSION['security_concurrent_counter'] ?? null;
if (!is_int($counter)) exit(4);
$_SESSION['security_concurrent_counter'] = $counter + 1;
usleep(200000);
session_write_close();
echo "ok\n";
