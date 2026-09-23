<?php

require_once __DIR__ . '/../app/Security/SecurityConfiguration.php';

function httpsSecurityAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$nginxPath = $root . '/deployment/nginx/generic-sql-api.linux.example.conf';
$nginx = (string)file_get_contents($nginxPath);
$headerPaths = [
    'frontend' => $root . '/deployment/nginx/security-headers.frontend.example.conf',
    'api' => $root . '/deployment/nginx/security-headers.api.example.conf',
    'admin' => $root . '/deployment/nginx/security-headers.admin.example.conf',
    'sqlparser' => $root . '/deployment/nginx/security-headers.sqlparser.example.conf',
];
$iisPaths = [
    'frontend' => $root . '/deployment/iis/frontend.web.config.example',
    'api' => $root . '/deployment/iis/api.web.config.example',
    'admin' => $root . '/deployment/iis/admin.web.config.example',
    'sqlparser' => $root . '/deployment/iis/sqlparser.web.config.example',
];

foreach ([
    'listen 443 ssl;',
    'ssl_protocols TLSv1.2 TLSv1.3;',
    'ssl_session_tickets off;',
    'return 308 https://reports.example.internal$request_uri;',
    'return 308 https://admin.example.internal:8443$request_uri;',
    'return 308 https://parser.example.internal:8444$request_uri;',
    'fastcgi_param HTTPS on;',
    'REPLACE_WITH_PUBLIC_CERTIFICATE_CHAIN_FILE',
    'REPLACE_WITH_PUBLIC_PRIVATE_KEY_FILE',
] as $required) {
    httpsSecurityAssert(str_contains($nginx, $required), "Nginx HTTPS template is missing {$required}.");
}
httpsSecurityAssert(!str_contains($nginx, 'https://$host'), 'Nginx redirect reflects an arbitrary Host header.');
httpsSecurityAssert(
    !str_contains(strtolower($nginx), 'x-forwarded-proto') && !str_contains(strtolower($nginx), 'forwarded'),
    'Nginx template trusts a forwarded protocol header without an explicit proxy boundary.'
);
httpsSecurityAssert(substr_count($nginx, '{') === substr_count($nginx, '}'), 'Nginx template braces are unbalanced.');

$requiredHeaders = [
    'Strict-Transport-Security',
    'X-Content-Type-Options',
    'Referrer-Policy',
    'Content-Security-Policy',
    'X-Frame-Options',
    'Permissions-Policy',
];
foreach ($headerPaths as $boundary => $path) {
    $headers = (string)file_get_contents($path);
    foreach ($requiredHeaders as $header) {
        httpsSecurityAssert(str_contains($headers, $header), "{$boundary} Nginx headers omit {$header}.");
    }
    httpsSecurityAssert(str_contains($headers, 'frame-ancestors \'none\''), "{$boundary} CSP permits framing.");
    httpsSecurityAssert(!str_contains($headers, "script-src *"), "{$boundary} CSP contains a script wildcard.");
    httpsSecurityAssert(!str_contains($headers, "'unsafe-eval'"), "{$boundary} CSP permits unsafe-eval.");
    httpsSecurityAssert(!str_contains($headers, 'preload'), "{$boundary} enables HSTS preload without deployment opt-in.");
    httpsSecurityAssert(!str_contains($headers, 'includeSubDomains'), "{$boundary} applies HSTS to unreviewed subdomains.");
}
$frontendHeaders = (string)file_get_contents($headerPaths['frontend']);
httpsSecurityAssert(str_contains($frontendHeaders, "script-src 'self'"), 'Frontend CSP does not restrict scripts to self.');
httpsSecurityAssert(str_contains($frontendHeaders, "style-src 'self' 'unsafe-inline'"), 'Frontend CSP does not account for validated Emotion/React inline styles.');
httpsSecurityAssert(!str_contains($frontendHeaders, "script-src 'self' 'unsafe-inline'"), 'Frontend CSP permits inline scripts.');
foreach (['admin', 'sqlparser'] as $boundary) {
    httpsSecurityAssert(!str_contains((string)file_get_contents($headerPaths[$boundary]), "'unsafe-inline'"), "{$boundary} CSP unnecessarily permits inline content.");
}
httpsSecurityAssert(str_contains((string)file_get_contents($headerPaths['api']), "default-src 'none'"), 'API CSP is not deny-by-default.');

foreach ($iisPaths as $boundary => $path) {
    $config = (string)file_get_contents($path);
    foreach ($requiredHeaders as $header) {
        httpsSecurityAssert(str_contains($config, 'name="' . $header . '"'), "{$boundary} IIS headers omit {$header}.");
    }
    httpsSecurityAssert(str_contains($config, 'max-age=31536000'), "{$boundary} IIS HSTS policy is missing.");
    httpsSecurityAssert(!str_contains($config, 'preload') && !str_contains($config, 'includeSubDomains'), "{$boundary} IIS HSTS scope is too broad.");
}
$iisRedirect = (string)file_get_contents($root . '/deployment/iis/http-redirect.web.config.example');
httpsSecurityAssert(str_contains($iisRedirect, 'input="{HTTPS}" pattern="^OFF$"'), 'IIS redirect lacks an HTTPS loop guard.');
httpsSecurityAssert(str_contains($iisRedirect, 'redirectType="Permanent"'), 'IIS HTTP redirect is not permanent.');
httpsSecurityAssert(str_contains($iisRedirect, 'https://reports.example.internal/'), 'IIS redirect does not use a fixed configured host.');
foreach ($requiredHeaders as $header) {
    httpsSecurityAssert(!str_contains($iisRedirect, $header), 'IIS HTTP redirect emits an HTTPS-only security header.');
}

$applicationSources = [
    $root . '/api/index.php',
    $root . '/admin/index.php',
    $root . '/admin/api.php',
    $root . '/sqlparser/index.php',
];
foreach ($applicationSources as $sourcePath) {
    $source = (string)file_get_contents($sourcePath);
    httpsSecurityAssert(
        str_contains($source, 'GENERIC_APP_ENV') || str_contains($source, 'SecurityConfiguration::isProduction()'),
        'Application boundary does not preserve local-only header fallback: ' . basename(dirname($sourcePath)) . '/' . basename($sourcePath)
    );
    httpsSecurityAssert(!str_contains($source, 'Strict-Transport-Security'), 'Application emits HSTS instead of leaving it to the production web server.');
}

$oldEnvironment = getenv('GENERIC_APP_ENV');
$oldHttps = $_SERVER['HTTPS'] ?? null;
$oldForwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null;
try {
    putenv('GENERIC_APP_ENV=production');
    unset($_SERVER['HTTPS']);
    $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
    $productionSession = SecurityConfiguration::sessionOptions();
    httpsSecurityAssert($productionSession['secure'] === true, 'Production cookies are not Secure.');
    httpsSecurityAssert($productionSession['httponly'] === true, 'Production cookies are not HttpOnly.');
    httpsSecurityAssert($productionSession['samesite'] === 'Lax', 'Production cookie SameSite policy changed.');

    putenv('GENERIC_APP_ENV=development');
    httpsSecurityAssert(SecurityConfiguration::sessionOptions()['secure'] === false, 'Arbitrary forwarded protocol enabled Secure-cookie detection.');
    $_SERVER['HTTPS'] = 'on';
    httpsSecurityAssert(SecurityConfiguration::sessionOptions()['secure'] === true, 'Direct HTTPS did not enable Secure cookies.');
} finally {
    $oldEnvironment === false ? putenv('GENERIC_APP_ENV') : putenv('GENERIC_APP_ENV=' . $oldEnvironment);
    if ($oldHttps === null) unset($_SERVER['HTTPS']); else $_SERVER['HTTPS'] = $oldHttps;
    if ($oldForwardedProto === null) unset($_SERVER['HTTP_X_FORWARDED_PROTO']); else $_SERVER['HTTP_X_FORWARDED_PROTO'] = $oldForwardedProto;
}

$linuxLauncher = (string)file_get_contents($root . '/start-linux.sh');
$windowsLauncher = (string)file_get_contents($root . '/start-windows.bat');
foreach ([$linuxLauncher, $windowsLauncher] as $launcher) {
    httpsSecurityAssert(str_contains($launcher, 'http://127.0.0.1:'), 'Local launcher was forced to HTTPS.');
    httpsSecurityAssert(!str_contains($launcher, 'Strict-Transport-Security'), 'Local launcher enables HSTS.');
}

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/deployment', FilesystemIterator::SKIP_DOTS)) as $file) {
    if (!$file->isFile()) continue;
    $name = strtolower($file->getFilename());
    httpsSecurityAssert(!preg_match('/\.(?:pem|key|pfx|p12|crt|cer)$/', $name), 'Certificate or private-key material exists in deployment templates.');
}

echo "HTTPS and security-header tests passed.\n";
