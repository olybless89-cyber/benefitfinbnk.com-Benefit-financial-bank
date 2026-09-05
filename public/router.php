<?php

/**
 * Static-file router for Benefit Financial Bank.
 *
 * The site is a set of static HTML pages backed by Supabase in the browser.
 * This router powers the `php -S 0.0.0.0:$PORT -t public public/router.php`
 * start command declared in Procfile / railway.json / nixpacks.toml, applying
 * the same clean-URL rewrites defined in vercel.json so /login, /register,
 * /dashboard, /admin, etc. resolve to their .html files.
 */

$root = __DIR__;

// Strip query string.
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$uri = rawurldecode($uri);

$rewrites = [
    '/'                 => '/index.html',
    '/index'            => '/index.html',
    '/business'         => '/business.html',
    '/personal'         => '/personal.html',
    '/cards'            => '/cards.html',
    '/loans'            => '/loans.html',
    '/contact'          => '/contact.html',
    '/login'            => '/login.html',
    '/register'         => '/register.html',
    '/forgot-password'   => '/forgot-password.html',
    '/reset-password'    => '/reset-password.html',
    '/about'            => '/about.html',
    '/faq'              => '/faq.html',
    '/apps'             => '/apps.html',
    '/privacy-policy'   => '/privacy-policy.html',
    '/terms-of-service' => '/terms-of-service.html',
    '/dashboard'        => '/dashboard.html',
    '/admin-login'      => '/admin-login.html',
    '/admin/login'      => '/admin-login.html',
    '/admin'            => '/admin.html',
];

if (isset($rewrites[$uri])) {
    $uri = $rewrites[$uri];
} elseif (preg_match('#^/admin(/.*)?$#', $uri)) {
    // /admin/<anything> -> admin SPA shell.
    $uri = '/admin.html';
}

// Resolve the file within the public root, preventing traversal.
$file = realpath($root . $uri);
if ($file === false || strpos($file, $root) !== 0 || !is_file($file)) {
    // Fallback to index.html for unknown paths (SPA-style catch-all),
    // matching the last vercel.json rewrite rule.
    $file = $root . '/index.html';
    if (!is_file($file)) {
        http_response_code(404);
        echo 'Not found';
        return;
    }
}

// Serve with a sensible content type.
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$types = [
    'html' => 'text/html; charset=utf-8',
    'css'  => 'text/css; charset=utf-8',
    'js'   => 'application/javascript; charset=utf-8',
    'json' => 'application/json; charset=utf-8',
    'svg'  => 'image/svg+xml',
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif'  => 'image/gif',
    'ico'  => 'image/x-icon',
    'webp' => 'image/webp',
    'woff' => 'font/woff',
    'woff2'=> 'font/woff2',
    'ttf'  => 'font/ttf',
    'eot'  => 'application/vnd.ms-fontobject',
    'txt'  => 'text/plain; charset=utf-8',
    'map'  => 'application/json; charset=utf-8',
];
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('Cache-Control: no-cache');

readfile($file);
