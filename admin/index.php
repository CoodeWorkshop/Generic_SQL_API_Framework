<?php

$remoteAddress = (string)($_SERVER['REMOTE_ADDR'] ?? '');
if (!in_array($remoteAddress, ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit('Not found.');
}
header_remove('X-Powered-By');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Generic SQL API · Administration</title>
    <link rel="stylesheet" href="/assets/admin.css">
    <link rel="stylesheet" href="/assets/service-controls.css">
</head>
<body class="pre-auth">
<div class="shell">
    <aside class="sidebar">
        <div class="brand"><span class="brand-mark">G</span><span>Generic SQL API</span></div>
        <nav id="navigation" hidden>
            <a href="/admin/health" data-route="health">System Health</a>
            <a href="/admin/info" data-route="info">System Info</a>
            <a href="/admin/configuration" data-route="configuration">Configuration</a>
            <a href="/admin/users" data-route="users">Users</a>
            <a href="/admin/roles" data-route="roles">Roles &amp; Permissions</a>
            <a href="/admin/api-keys" data-route="api-keys">API Keys</a>
        </nav>
        <button id="logout" class="quiet" type="button" hidden>Sign out</button>
    </aside>
    <main>
        <header><div><p class="eyebrow">Local administration</p><h1 id="page-title">Starting…</h1></div><span class="local-pill">127.0.0.1 only</span></header>
        <div id="toast" class="toast" role="status" aria-live="polite"></div>
        <section id="content" class="panel loading"><div class="skeleton"></div><div class="skeleton short"></div></section>
    </main>
</div>
<dialog id="confirmation"><form method="dialog"><h2 id="confirm-title">Confirm action</h2><p id="confirm-message"></p><div class="actions"><button value="cancel" class="secondary">Cancel</button><button value="confirm" class="danger">Confirm</button></div></form></dialog>
<script src="/assets/admin.js" defer></script>
</body>
</html>
