#!/usr/bin/env node
// Self-contained Benefit Financial Bank server (no npm deps, no PHP).)
//
// - Serves the static site from public/ with the clean-URL rewrites from
//   vercel.json (/login -> /login.html, /admin -> /admin.html,, etc.).
// - Browser-direct by default: pages call the hosted Supabase project straight from
//   each visitor browser (CORS is open on the project), avoiding server-egress
//   edge blocks. /supa/*is still proxied when SUPABASE_API_URL is set (a
//   self-hosted stack)or SUPABASE_PROXY=enabled — in that mode pages are
//   rewritten so the embedded client points at /supa on this origin,and requests
//   forward over this server outbound connection (GoTrue + PostgREST + storage).
//
// Usage:  node serve.js            (port from PORT env, default 12000)
//
// Self-hosted Supabase endpoints come from SUPABASE_API_URL (default:the hosted
// project),and the anon key from SUPABASE_ANON_KEY.

'use strict';
const http = require('http');
const fs = require('fs');
const path = require('path');
const url = require('url');

const PUBLIC = path.resolve(__dirname, 'public');
const PORT = Number(process.env.PORT || 12000);

// The hosted project values embedded in the committed HTML.
const HOSTED_URL = 'https://hmmtcnklfpqjoumwdcoj.supabase.co';
const HOSTED_KEY = 'sb_publishable_fidyxSk8eEyVTpM_JCMjSA_xz4OQ6_C';

// Browser-direct default: pages call the hosted project straight from the visitor's
// browser — CORS is open on it, and this avoids proxying through the server's egress
// (which Cloudflare blocks for some cloud providers, e.g. Railway's IP ranges).
// Set SUPABASE_API_URL (a self-hosted/alternate stack,)or SUPABASE_PROXY=enabled
// to force the /supa proxy below instead — needed when the upstream origin can't be
// reached directly by visitors' browsers (self-hosted local stacks etc.).
const PROXY_ENABLED = (process.env.SUPABASE_API_URL ? true : false)
  || process.env.SUPABASE_PROXY === 'enabled';
const SUPABASE_API_URL = (PROXY_ENABLED
  ? (process.env.SUPABASE_API_URL || HOSTED_URL.replace(/\/$/, ''))
  : HOSTED_URL);

// Anon key for the default hosted project (public key embedded in served HTML).
// Override with SUPABASE_ANON_KEY when running against a self-hosted stack whose
// JWT secret differs from the hosted project's secret.
const LOCAL_ANON_KEY = process.env.SUPABASE_ANON_KEY
  || 'sb_publishable_fidyxSk8eEyVTpM_JCMjSA_xz4OQ6_C';

// MIME types for static assets.
const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg',
  '.gif': 'image/gif', '.svg': 'image/svg+xml', '.ico': 'image/x-icon',
  '.webp': 'image/webp', '.woff': 'font/woff', '.woff2': 'font/woff2',
  '.ttf': 'font/ttf', '.eot': 'application/vnd.ms-fontobject',
  '.map': 'application/json', '.txt': 'text/plain; charset=utf-8',
  '.pdf': 'application/pdf', '.mp4': 'video/mp4',
};

// Clean-URL rewrites (mirrors vercel.json): /login -> /login.html, etc.
const REWRITES = {
  '/': '/index.html',
  '/business': '/business.html',
  '/personal': '/personal.html',
  '/cards': '/cards.html',
  '/loans': '/loans.html',
  '/contact': '/contact.html',
  '/login': '/login.html',
  '/register': '/register.html',
  '/forgot-password': '/forgot-password.html',
  '/reset-password': '/reset-password.html',
  '/about': '/about.html',
  '/faq': '/faq.html',
  '/apps': '/apps.html',
  '/privacy-policy': '/privacy-policy.html',
  '/terms-of-service': '/terms-of-service.html',
  '/dashboard': '/dashboard.html',
  '/admin-login': '/admin-login.html',
  '/admin/login': '/admin-login.html',
  '/admin': '/admin.html',
};

function resolveFile(reqPath) {
  let p = decodeURIComponent(reqPath.split('?')[0]);
  // Admin sub-paths all serve admin.html (matches /admin/(.*) in vercel.json).
  if (p.startsWith('/admin/') && p !== '/admin/login') p = '/admin.html';
  if (REWRITES[p]) p = REWRITES[p];
  if (p === '/' || p === '') p = '/index.html';
  // Strip the leading slash for filesystem path.
  let rel = p.replace(/^\//, '');
  let fp = path.join(PUBLIC, rel);
  // Prevent path traversal.
  if (!fp.startsWith(PUBLIC)) fp = path.join(PUBLIC, 'index.html');
  // If directory, look for index.html; if no extension and a .html sibling exists, use it.
  try {
    const st = fs.statSync(fp);
    if (st.isDirectory()) {
      const idx = path.join(fp, 'index.html');
      if (fs.existsSync(idx)) return idx;
    }
    return fp;
  } catch (e) {
    // Try adding .html (clean URL form like /login.html already handled; bare names)
    if (!path.extname(fp)) {
      const withHtml = fp + '.html';
      if (fs.existsSync(withHtml)) return withHtml;
    }
    return null;
  }
}

function rewriteHtml(content, origin) {
  // The Supabase JS SDK rejects relative URLs:
  // it enforces ^https?://, so the URL passed to createClient must be absolute.
  // By default we leave the committed HOSTED_URL intact (browser-direct mode):
  // pages talk straight to the hosted project from the visitor browser, bypassing
  // this server egress. When PROXY_ENABLED we rewrite it to /supa on this origin,
  // routing every Supabase call through the proxy below (self-hosted/alternate stack).
  const supaUrl = origin + '/supa';
  // Serve the Supabase JS SDK from a local vendored copy so no CDN dependency.
  const cdnSdk = 'https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2/dist/umd/supabase.js';
  const localSdk = '/vendor/supabase.js';
  let out = content;
  if (PROXY_ENABLED) {
    out = out.replace(HOSTED_URL, supaUrl);
    out = out.replace(HOSTED_KEY, LOCAL_ANON_KEY);
  }
  return out.replace(cdnSdk, localSdk);
}

function serveStatic(req, res, reqPath) {
  const fp = resolveFile(reqPath);
  if (!fp || !fs.existsSync(fp)) {
    // SPA-ish fallback: index.html (last rewrite in vercel.json).
    const idx = path.join(PUBLIC, 'index.html');
    if (fs.existsSync(idx)) {
      const body = fs.readFileSync(idx);
      res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
      res.end(rewriteHtml(body.toString('utf8'), originFor(req)));
      return;
    }
    res.writeHead(404, { 'Content-Type': 'text/plain' });
    res.end('Not found');
    return;
  }
  const ext = path.extname(fp).toLowerCase();
  const mime = MIME[ext] || 'application/octet-stream';
  // The chat widget also embeds the hosted Supabase URL/anon key, so rewrite
  // it the same way as the HTML pages or it would keep talking to the hosted
  // project in self-hosted mode.
  if (ext === '.js' && path.basename(fp) === 'chat-widget.js') {
    let body;
    try { body = fs.readFileSync(fp); } catch (e) { res.writeHead(500); res.end('read error'); return; }
    res.writeHead(200, { 'Content-Type': mime, 'Cache-Control': 'no-store' });
    res.end(rewriteHtml(body.toString('utf8'), originFor(req)));
    return;
  }
  if (ext === '.html') {
    let body;
    try { body = fs.readFileSync(fp); } catch (e) { res.writeHead(500); res.end('read error'); return; }
    res.writeHead(200, { 'Content-Type': mime, 'Cache-Control': 'no-store' });
    res.end(rewriteHtml(body.toString('utf8'), originFor(req)));
    return;
  }
  fs.readFile(fp, (err, data) => {
    if (err) { res.writeHead(404); res.end('Not found'); return; }
    res.writeHead(200, { 'Content-Type': mime, 'Cache-Control': 'public, max-age=3600' });
    res.end(data);
  });
}

function originFor(req) {
  const host = req.headers['host'] || ('localhost:' + PORT);
  // Honour the reverse proxy's scheme (work hosts are https).
  const fwdProto = (req.headers['x-forwarded-proto'] || '').split(',')[0].trim();
  const proto = fwdProto || (req.socket.encrypted ? 'https' : 'http');
  return proto + '://' + host;
}

function proxySupabase(req, res, reqPath) {
  // reqPath begins with /supa; map to the local API.
  let target = SUPABASE_API_URL + reqPath.replace(/^\/supa/, '');
  const parsed = url.parse(target, true);
  const upstream = parsed;
  // Support https upstreams too (e.g. proxying straight at a hosted project).
  const transport = upstream.protocol === 'https:' ? require('https') : http;
  const payload = [];
  req.on('data', c => payload.push(c));
  req.on('end', () => {
    const body = Buffer.concat(payload);
    const headers = { ...req.headers };
    delete headers['host'];
    delete headers['content-length'];
    if (body.length) headers['content-length'] = body.length;
    const proxyReq = transport.request(
      {
        hostname: upstream.hostname,
        port: upstream.port || (upstream.protocol === 'https:' ? 443 : 80),
        path: upstream.path,
        method: req.method,
        headers,
      },
      proxyRes => {
        res.writeHead(proxyRes.statusCode, proxyRes.headers);
        proxyRes.pipe(res);
      }
    );
    proxyReq.on('error', err => {
      res.writeHead(502, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ error: 'Supabase proxy error', message: err.message }));
    });
    if (body.length) proxyReq.write(body);
    proxyReq.end();
  });
}

const server = http.createServer((req, res) => {
  const reqPath = req.url || '/';
  if (reqPath.startsWith('/supa/') || reqPath === '/supa') {
    return proxySupabase(req, res, reqPath);
  }
  serveStatic(req, res, reqPath);
});

server.listen(PORT, '0.0.0.0', () => {
  console.log(`Benefit Financial Bank server listening on http://0.0.0.0:${PORT}`);
  console.log(`  static root: ${PUBLIC}`);
  console.log(`  supabase proxy: /supa -> ${SUPABASE_API_URL}`);
});
