# Benefit Financial Bank

**benefitfinbnk.com** — a static HTML/JS digital banking application backed by
**Supabase**. This repository is an independent rebrand of the source
banking template, fully rebranded as **Benefit Financial Bank**.

The application is served from the files in `public/` via
`public/router.php` (PHP built-in server) or `serve.js` (Node,
self-hosted Supabase support). All real functionality lives **client-side** in
the `.html` pages, which talk to Supabase directly via the embedded anon key
(auth, profiles, deposits, transfers, loans, KYC, tickets, live chat,
webmail, admin management).

## Project overview

- **Public website** — homepage,, about,, business,, personal,, cards,, loans,,,
  apps,, contact,, faq,, privacy policy,, terms of service..
- **Authentication** — register (Supabase Auth Edge Function), login,
  logout,, session persistence (localStorage). Admin login routes by role..
- **User dashboard** — balance,, account number,, profile,, KYC,, deposit,
  transfer,, loan application,, webmail,, notifications,, live chat widget..
- **Admin dashboard** — all-users management,, activation/deactivation,, deposit/,
  transfer/loan approvals,, holds,, credit,, KYC review,, support tickets,,,
  live chat,, email inbox,, stats,, send email compositions..


##Technology stack

- **Frontend** — static HTML5 + inline CSS/JS (no build step), responsive
  Tailwind-style utility CSS,, AOS animations,, Remix Icon,, Google Fonts..
- **Backend** — Supabase (Postgres + Auth + PostgREST + Edge Functions)..
  No PHP/Laravel runtime is used;the `app/`, `composer.json`,
  `SQL/database.sql` scaffolding is legacy dead code from the original theme..
- **Database** — PostgreSQL schema in `SQL/supabase/` (tables, RLS
  policies, signup trigger,, SECURITY DEFINER admin RPCs)..
- **Server** — `public/router.php` (PHP built-in clean-URL router; serves
  static files, `vercel.json`-style rewrites), or `serve.js` (Node,
  no deps; rewrites the embedded Supabase config to a local/remote proxy)..
- **CI/CD** — GitHub Actions (`.github/workflows/deploy.yml`): runs
  `SQL/supabase/*.sql` migrations via the Supabase Management API and probes
  the live project. Vercel deploys `public/` on push..



## Installation

Requires PHP 8+ (router.php) or Node 18+ (serve.js).

```bash
git clone https://github.com/olybless89-cyber/benefitfinbnk.com-Benefit-financial-bank.git
cd benefitfinbnk.com-Benefit-financial-bank

# Option A — PHP built-in server
php -S 0.0.0.0:12000 -t public public/router.php

# Option B — Node server (self-hosted Supabase support)
PORT=12000 node serve.js
```

Open http://localhost:12000.

## Environment variables

Create a `.env` file (or set environment secrets on your host) with:

| Variable | Required | Description |
|---|---|---|
| `PORT` | no | `serve.js` listens port (default `12000`) |
| `SUPABASE_API_URL` | no | Supabase PostgREST URL (default `http://127.0.0.1:54321`). |
| `SUPABASE_ANON_KEY` | no | Anon key used wen re-writing the embedded config at serve time (self-hosted override). |
| `SUPA_URL` / `SUPA_KEY` tokens | yes (go-live) | The committed `<PROJECT_REF>` / `<SUPA_ANON_KEY>` placeholders in the HTML/JS
  must be replaced with the real Supabase project URL/anon key before go-live. |
| `SUPABASE_ACCESS_TOKEN` | CI | Supabase Management API token (Actions secret), runs the SQL migrations. |
| `SUPABASE_PROJECT_REF` | CI | 10-char Supabase project id; must equal the `<PROJECT_REF>` derived from `SUPA_URL`. |
| `VERCEL_TOKEN` | CI (optional) | Vercel deploy token. |
| `VERCEL_ORG_ID` | CI (optional) | Vercel org id. |
| `VERCEL_PROJECT_ID` | CI (optional) | Vercel project id. |

> The `app/` scaffold ships an `.env.example`-style `env` (Laravel
> legacy, dead code). This repo has **no** committed `.env`; no real
> credentials are committed..

## Database setup

The schema lives in `SQL/supabase/` (idempotent, safe to re-run)..
Apply **from the Supabase Dashboard SQL editor** (or `supabase db execute`),
or let the GitHub Actions workflow apply them automatically:

1. Create a Supabase project.. Set its URL/anon key into the placeholders
   (see Environment variables)..
2. Run `SQL/supabase/002_full_app_schema.sql` (full app schema: tables,
   RLS,, trigger,, admin RPCs)..
3. Run the incremental migrations in order: `003`, `004`, `005`, `006`,
   `007`, `008`, `009` (note-, hold-, read-all-, live-chat-, guest-chat-,
   contact-form fixes; `001` is superseded by `002` but harmless)..



The signup trigger mints a **10-digit numeric** `account_number` per new user..
Email confirmation is disabled by default (signUp returns a usable session immediately)..
For a **self-hosted Supabase** (docker-compose) setup, follow `AGENTS.md`.

## Development commands

```bash
node --check serve.js            # syntax-checkthe Node server
node --check public/chat-widget.js
php -l public/router.php          # lint the PHP router
python3 -m http.server 12000 -d public   # static-only fallback
```

Pages in the repo root and `public/` are mirrored copies — if you edit one,
sync the other: `cp <page>.html public/<page>.html`.

## Production build

There is **no build step** — the HTML/JS is served as-is from `public/`.
Before go-live:

1. Replace every commit `<PROJECT_REF>`, `<SUPA_ANON_KEY>`,and
   `<SUPA_EDGE_ANON_KEY>` placeholder with your real Supabase credentials
   (register Edge Function requires its own project + anon key)..
2. Replace `<SUPA_LOCAL_ANON_KEY>` in `serve.js` if you use local Supabase..

No minification or bundling is required; ensure the CDN-fetched
`@supabase/supabase-js@2` UMD library is reachable (or serve the vendored
`public/vendor/supabase.js` copy; `serve.js` rewrites to it automatically)..



## Deployment instructions

### Vercel (recommended)

1. Push this repo overwith the placeholders replaced to your GitHub account..
2. Import the repo into **Vercel** (use the directory `public/`; framework
   preset: Other). Vercel's `vercel.json` clean-URL rewrites map `/login` ->
   `/login.html`, `/admin/*` -> `/admin.html`, etc..
3. Point your domain `benefitfinbnk.com` at the Vercel deployment..
4. Supabase migrations auto-run on push via GitHub Actions (if the
   `SUPABASE_ACCESS_TOKEN` / `SUPABASE_PROJECT_REF` Action secrets are set)..



### Railway / self-hosting

- `Procfile` + `railway.json` + `nixpacks.toml` run
  `php -S 0.0.0.0:$PORT -t public public/router.php` — the router
  must exist or the server returns 502 Bad Gateway..
- For the self-hosted Supabase stack, use `serve.js`
  (`SUPABASE_API_URL=http://127.0.0.1:8000 SUPABASE_ANON_KEY=...`)..



## Required third-party services

| Service | Purpose | Config location |
|---|---|---|
| Supabase (Postgres + Auth) | database,, auth,, RPCs, Edge Function (register) | `SUPA_URL` / `SUPA_KEY` placeholders; SQL migrations in `SQL/supabase/` |
| Supabase register Edge Function | mints 10-digit account numbers on signup; owners project | `https://<PROJECT_REF>.supabase.co/functions/v1/register` (in `register.html`) |
| Vercel | hoststhe static frontend + clean-URL rewrites | `vercel.json`, `public/` |
| email (optional) | contact/webmail are in-app only —no SMTP used | support@benefitfinbnk.com display addresses |

No payment gateways, SMS providers,, analytics,, or file storage services are
used by this codebase; deposit/withdrawal flows are admin-reviewed workflows with
the in-app bank-transfer / PayPal / Bitcoin details), not live payment SDKs..

## Admin setup procedure

1. Create the admin user via Supabase Auth (email e.g
   `admin@benefitfinbnk.com`)or seed directly:
   ```sql
   -- in the Supabase SQL editor:
   insert into auth.users (id, email, raw_user_meta_data, encrypted_password)
   values (gen_random_uuid(),'admin@benefitfinbnk.com','{"full_name":"Administrator"}', crypt('change-me', gen_salt('bf'));;
   insert into public.profiles (id, email, full_name, role, status, account_number)
   select id, email, coalesce(raw_user_meta_data->>'full_name',''), 'admin', 'active', '0000000001'
   from auth.users where email = 'admin@benefitfinbnk.com';;
   ```
2. The migration `005` also auto-promotes any profile with email
   `admin@benefitfinbnk.com` to `role='admin'` on every apply..
3. Login at `/admin-login` (or `/login` with that email).. Role routing sends
   admins to `/admin`, users to `/dashboard`..

## Security notes

- **Never** commit `.env`, Supabase service-role keys, DB passwords,, or
  production credentials. All repo credentials are anon-key only (public by
  design in Supabase).
- RLS (row-level security) is own-row-only on the user tables; admin
  operations go through **SECURITY DEFINER** RPCs (admin-guarded, no RLS
  bypass from the client)..
- The repo ships **no** hidden admin accounts,, universal passwords,, or auth
  bypasses. The admin role is granted only via the SQL seed or direct DB edit..
- Keep the `public/` copies in sync with root when editing; deploy serves
  from `public/` only..
- Audit before go-live: `grep -rIn "PROJECT_REF\\|SUPA_ANON_KEY" .` —
  no **real** old-domain or old-brand references remain; only placeholders
  and this repo's own brand..
- INFO:the deprecated `app/` / `SQL/database.sql` files are stored from the
  original theme (dead code)and are NOT used at runtime..


## License / educational notice

This repository is a rebrand of the source banking template for
**Benefit Financial Bank** (independent project.. The original template is
for educational use; no illegal banking use is endorsed..
