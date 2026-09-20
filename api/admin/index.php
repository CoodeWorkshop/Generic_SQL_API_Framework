<?php

$enabled = getenv('GENERIC_ADMIN_ENABLED') === '1';
$remoteAddress = (string)($_SERVER['REMOTE_ADDR'] ?? '');
if (!$enabled || !in_array($remoteAddress, ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found.';
    exit;
}

header_remove('X-Powered-By');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Generic SQL API · Administration</title>
    <link rel="stylesheet" href="/admin/assets/admin.css">
</head>
<body>
    <div class="shell">
        <aside class="sidebar">
            <div class="brand"><span class="brand-mark">G</span><span>Generic SQL API</span></div>
            <nav id="navigation" hidden>
                <a href="/admin" data-route="overview">Overview</a>
                <a href="/admin/database" data-route="database">Database</a>
                <a href="/admin/cors" data-route="cors">CORS</a>
                <a href="/admin/authentication" data-route="authentication">Authentication</a>
                <a href="/admin/sql-parser" data-route="sql-parser">SQL parser</a>
                <a href="/admin/security" data-route="security">Security</a>
                <a href="/admin/system" data-route="system">System</a>
            </nav>
            <button id="logout" class="quiet" type="button" hidden>Sign out</button>
        </aside>
        <main>
            <header><div><p class="eyebrow">Local administration</p><h1 id="page-title">Starting…</h1></div><span class="local-pill">127.0.0.1 only</span></header>
            <div id="notice" role="status" aria-live="polite"></div>
            <section id="content" class="panel loading">Loading the administration console…</section>
        </main>
    </div>
    <script src="/admin/assets/admin.js" defer></script>
</body>
</html>
