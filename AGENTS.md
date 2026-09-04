# AGENTS.md ‚Äî Benefit Financial Bank (benefitfinbnk.com)

## What this project actually is
A **static HTML/JS** banking demo site. Despite shipping Laravel-style scaffolding
(`app/Http/Controllers/...`, `composer.json`, `SQL/database.sql`), there is **no PHP
runtime app**: no `artisan`, no `vendor/`, no `routes/`, no `index.php`, no `.env`.
The `app/` controllers are dead code. All real functionality lives client-side in
the `.html` files, which talk to **Supabase** directly via the embedded anon key.

## Auth / data backend
- Supabase project: configured via `<PROJECT_REF>.supabase.co` (placeholders `<SUPA_ANON_KEY>` embedded in
  `login.html`, `register.html`, `dashboard.html`, `admin.html`, `admin-login.html`).
- Auth: `supabase.auth.signUp` / `signInWithPassword` / `getSession` / `signOut`.
- A `profiles` table is auto-populated by a DB trigger on user signup with
  `role=user`, `status=active`, an `account_number` (10-digit numeric), `balance=0`, etc.
- Sessions persist in `localStorage` under `sb-<ref>-auth-token`.
- Email confirmation is **disabled** ‚Äî signUp returns a usable session immediately.

## How the site is served (important)
The deploy config runs: `php -S 0.0.0.0:$PORT -t public public/router.php`
(see `Procfile`, `railway.json`, `nixpacks.toml`). `public/router.php` **must
exist** or the server fails to start and the site returns 502 Bad Gateway.
`public/router.php` serves the static files and applies the clean-URL rewrites
from `vercel.json` (`/login` -> `/login.html`, `/admin/*` -> `/admin.html`, etc.).

## File layout gotcha
Most HTML pages exist in **two copies**: repo root and `public/`. The deploy
serves from `public/`, so **always sync edits to both**:
`cp <page>.html public/<page>.html`. Auth pages (`login.html`, `register.html`)
and `dashboard.html`/`admin.html` are the ones that matter for auth flows.

## Verified auth flows
- Register: `register.html` -> `signUp` -> upsert `profiles` row -> redirect to `/login`
  (then auto-redirects to `/dashboard` because a session is already present).
- Login: `login.html` -> `signInWithPassword` -> read `profiles` -> route by role
  (`admin` -> `/admin`, else `/dashboard`). Missing profile row is auto-created.
- Logout: `dashboard.html` / `admin.html` `doLogout()` -> `signOut` -> `/login`.

## Local run
`php -S 0.0.0.0:12000 -t public public/router.php` (PHP CLI required).
Without PHP, a trivial static server with the vercel.json rewrites also works
(the pages are static + remote Supabase JS).

## Self-hosted go-live (no hosted Supabase needed) ‚Äî 2026-08
`serve.js` is a Node static server that runs the site against a **self-hosted
Supabase stack** (no access to the hosted project required). It serves
`public/`, applies the clean-URL rewrites, and rewrites the embedded Supabase
config in served HTML so the client talks to a `/supa` proxy that forwards to
the local stack (`127.0.0.1:54321`). Run: `PORT=12000 node serve.js`.

**Critical gotcha (root cause of "forms/buttons don't respond"):** the
`@supabase/supabase-js` UMD `createClient()` **rejects relative URLs** with
`Invalid supabaseUrl` (it enforces `^https?://`). If `SUPA_URL` is set to a
relative path like `/supa`, `createClient` throws at the top of every page's
inline `<script>`, so *none* of the handlers (`doLogin`, `doRegister`, deposit
click, etc.) ever get defined ‚Äî the page looks fine but is completely dead.
Fix in `serve.js`: rewrite `SUPA_URL` to an **absolute** URL built from the
request's own origin + `/supa` (uses `x-forwarded-proto` + `Host`, so it works
behind the https work hosts). Also rewrite the CDN SDK `<script src>` to a
**locally vendored** copy at `/vendor/supabase.js` (in `public/vendor/`) so
pages don't depend on an external CDN that may be unreachable from the browser.

Local stack keys (self-hosted Supabase defaults): anon key = the standard
local anon key `<SUPA_LOCAL_ANON_KEY>` (JWT ref `supabase-demo`), API at `127.0.0.1:54321`.
The live schema (tables, RLS, RPCs, the digit-free `handle_new_user` trigger)
must be applied to the local DB from `SQL/supabase/002_full_app_schema.sql`
and `SQL/supabase/003_fix_review_rpc_note_ambiguity_and_digit_free_account.sql`.
Admin seed user: `admin@benefitfinbnk.com` (set `profiles.role='admin'`).
Verified live in-browser against the self-hosted stack: register, login,
admin dashboard + All Users (no ambiguous-role error), deposit submit.

## Supabase schema (NOT in this repo)
The live schema (tables, RLS policies, RPC functions) lives in the Supabase
project `<PROJECT_REF>` ‚Äî it is NOT the legacy `SQL/database.sql` (that
is a MySQL phpMyAdmin dump from the old PHP app and is dead code). Migrations
that touch the live Supabase schema live in `SQL/supabase/` and must be applied
manually in the Supabase Dashboard SQL editor (the repo has no service_role key
or DB password, so they cannot be auto-applied from the deploy).

## Admin user management ‚Äî known bug + fix (2026-08)
Symptom: created users did not show on the admin dashboard and could not be
managed. Root cause (verified against live project):
- The `admin_get_all_users` RPC threw `column reference "role" is ambiguous`
  (a PL/pgSQL var/param named `role` clashed with `profiles.role`), so the user
  list RPC errored for everyone.
- The client fallback read `profiles` directly, but RLS on `profiles` is
  `auth.uid() = id` (own row only) with NO admin bypass ‚Äî so a client read can
  never return other users (returns the admin's own row or `[]`).
- `toggleUserStatus()` wrote to `profiles` directly from the client, which RLS
  silently blocked for any non-self user (deactivate/activate did nothing).
Fix (in repo):
- `SQL/supabase/001_fix_admin_user_management.sql` redefines
  `admin_get_all_users` as SECURITY DEFINER (bypasses RLS) with table-qualified
  columns (`p.role`) and adds `admin_set_user_status(target_id, new_status)`
  (SECURITY DEFINER, admin-guarded). **Must be applied in the Supabase SQL
  editor once; idempotent.**
- `admin.html` (+ `public/admin.html`) `loadUsers()` surfaces a clear, actionable
  banner (instead of a silent empty table) when the RPC is still broken or RLS
  limits reads to self; `toggleUserStatus()` now calls the
  `admin_set_user_status` RPC instead of the RLS-blocked direct update.
Other admin management RPCs already work via SECURITY DEFINER:
`admin_update_kyc`, `admin_credit_user`, `admin_hold_funds`, `admin_release_hold`,
`admin_reply_ticket`, `admin_get_stats`. Cross-user-readable tables (no RLS
own-row restriction): `support_tickets`, `transactions`, `deposit_requests`,
`transfer_requests`, `loan_applications`.

## Admin approval-table reads ‚Äî same RLS bug class, fixed (2026-08)
The note above that the approval tables are "cross-user-readable" was **wrong**
for this stack. In the live/self-hosted schema ALL five approval tables
(`deposit_requests`, `transfer_requests`, `loan_applications`,
`support_tickets`, `transactions`) have own-row-only SELECT RLS
(`auth.uid() = user_id`). So the admin dashboard's direct client reads
(`sb.from('<table>').select('*')` in `loadDeposits/loadTransfers/loadLoans/
loadTickets/loadTxns` and the review-detail fetches) could only ever return
the admin's OWN rows ‚Äî every approval table appeared empty even when rows
existed (the SECURITY DEFINER `admin_get_stats` counted them, but the list
reads were RLS-blocked). Same root cause as the All Users bug.
Fix (in repo):
- `SQL/supabase/006_admin_read_all_rpcs.sql` adds SECURITY DEFINER, admin-guarded
  RPCs: `admin_get_all_{deposits,transfers,loans,tickets,transactions}`,
  single-row `admin_get_{deposit,transfer,loan,ticket}`, and
  `admin_send_message(target_user, p_subject, p_body, p_category)`. Guarded to
  no-op when `public.profiles` is absent; idempotent. **Must be applied in the
  Supabase SQL editor once.**
- `admin.html` (+ `public/admin.html`) `load*()` now call the read-all RPCs and
  filter client-side; review-detail fetches use `admin_get_<entity>`;
  `doSendMessage` uses `admin_send_message` (cross-user insert was RLS-blocked).
Known remaining gaps (NOT yet fixed, same RLS class):
- **Live Chat** subsystem (`loadLiveChats`, `pollLcSession`, `sendLcReply`,
  `updateLcStatus`) still reads/updates `support_tickets` directly from the
  client -> cross-user ops RLS-blocked. Needs SECURITY DEFINER
  `admin_get_live_chats` + `admin_append_ticket_message`/`admin_set_ticket_status`.
- `transactions` column is `description`, but `admin.html` `renderTxns`/activity
  uses `t.reason` -> admin txn "reason" column shows "-". (user dashboard uses the
  right column.) Minor display bug.
- User drawer does not auto-refresh after status toggle (cosmetic; DB + table
  are correct).

## Admin review RPCs ‚Äî `note` ambiguity bug + fix (2026-08)
The three admin review RPCs (`admin_review_deposit`, `admin_review_transfer`,
`admin_review_loan`) had the SAME class of PL/pgSQL name-clash bug as the
`role` issue: each took a `note text` parameter and did `set ..., note = note`
in the UPDATE. Postgres raised `column reference "note" is ambiguous`
(code 42702) because it could not decide between the `note` parameter and the
`*.note` table column on the RHS ‚Äî so approving/rejecting any deposit, transfer,
or loan silently failed for everyone.
Fix (in repo):
- `SQL/supabase/003_fix_review_rpc_note_ambiguity_and_digit_free_account.sql`
  redefines all three as SECURITY DEFINER with a local `v_note text := note`
  variable (breaks the param/column collision) and uses `set ..., note = v_note`
  in the UPDATE. Same signatures the client expects, idempotent. **Must be
  applied in the Supabase SQL editor once.**
- Verified end-to-end against a local self-hosted Supabase stack: deposit
  approve credits balance + logs a `deposit` transaction + saves note; loan
  approve credits balance; transfer reject saves note without debiting.

## Account number format — NUMERIC, 10 digits (supersedes digit-free) — 2026-08-23
**Current truth: account numbers are 10-digit numeric-only** (e.g.
`5226870527`). Registration goes through the owner's Edge Function
(`register.html` POSTs to `https://<PROJECT_REF>.supabase.co/functions/v1/register`
— a DIFFERENT project hosting the function; it creates the auth user on
`<PROJECT_REF>` and returns tokens for `sb.auth.setSession`). The
numeric format came from owner commit 146539c "fix: generate 10-digit
numeric-only account numbers on signup" (register.html only).

The earlier digit-free(letters-only) migrations 003/004 have been
**rewritten to match the numeric format**: 003's `handle_new_user` now mints
10 digits, and 004 no longer renumbers digit-bearing accounts (it only fills
NULL/empty account_numbers with numeric values). If 003/004 had been left as
letters-only, the next successful CI run would have renumbered EVERY live
account. Do not reintroduce letters-only minting.

Verified in the Postgres recipe: 002→003→004→008→009 all apply with
ON_ERROR_STOP; trigger mints `^[0-9]{10}$`; 004 leaves existing digit
accounts untouched and fills NULLs.

### Account number vs. wallet address ‚Äî display rule
The UI surfaces the user's **real `account_number`** in all account-number
spots, and the shared `WALLET_ADDRESS` constant
(`bc1qxy2kgdygjrsqtzq2n0yrf2493p83kkfjhx0wlh`) **only** in the BTC deposit
context. Specifically:
- Dashboard sidebar `ID:` ‚Üí `account_number`
- Dashboard balance card (label "Account Number") ‚Üí `account_number`
- Dashboard ATM card (`‚Ä¢‚Ä¢‚Ä¢‚Ä¢ ‚Ä¢‚Ä¢‚Ä¢‚Ä¢ ` + last 4) ‚Üí last 4 of `account_number`
- Dashboard Profile modal (label "Account Number") ‚Üí `account_number`
- Dashboard deposit "Your Wallet Address (use this to add funds)" box ‚Üí
  `WALLET_ADDRESS` (BTC deposit destination ‚Äî correct, keep)
- Admin "All Users" table column "Account" ‚Üí each user's `account_number`
  (relabelled from "Wallet")
- Admin manage-user modal field "Account" ‚Üí selected user's `account_number`

Do NOT put the wallet address in any account-number spot again ‚Äî that was the
"wallet address showing where normal account number should be" bug.

## Full schema reference
`SQL/supabase/002_full_app_schema.sql` is the complete, current schema
(tables, RLS, signup trigger, all 11 RPCs) as a single idempotent file. It is a
reference/integration-testing artifact ‚Äî the live project is NOT migrated from
it wholesale. Incremental migrations (`001`, `003`, `004`, `005`) are what get
applied to the live Supabase project via the SQL editor.

## Self-hosted Supabase via official docker-compose ‚Äî 2026-08
When there is **no** hosted Supabase access and no `supabase` CLI, the site can
run against the official self-hosting stack
(`https://github.com/supabase/supabase` `docker/docker-compose.yml`). Gotchas:

- **First-run bind-mount bug:** the compose file bind-mounts several *files*
  (e.g. `volumes/api/envoy/docker-entrypoint.sh`, `volumes/db/*.sql`,
  `volumes/pooler/pooler.exs`). On first `compose up`, if the host path does
  not exist as a file, Docker creates an **empty directory** there, and the
  affected containers (envoy, auth, rest, pooler) fail to start with
  `not a directory` / `password authentication failed for user
  supabase_auth_admin`. Fix: `rm -rf` the stray dirs and drop the real files in
  from the supabase repo, then `docker compose down && docker compose up -d`.
  If the DB already mis-initialized, also wipe `volumes/db/data` and re-init so
  the roles/init SQLs run.
- **Anon key differs from the CLI default:** the official compose `.env`
  ships its own `JWT_SECRET` + anon key (different from the CLI default), which is NOT the
  Supabase CLI demo key `<SUPA_LOCAL_ANON_KEY>` baked into `serve.js`. Start
  `serve.js` with the matching env overrides:
  `SUPABASE_API_URL=http://127.0.0.1:8000 \
   SUPABASE_ANON_KEY=<.env ANON_KEY> PORT=12000 node serve.js`
  (the API gateway is on port **8000**, not the CLI's 54321). `serve.js`
  honors `SUPABASE_ANON_KEY` to rewrite the embedded client config.
- **Apply app schema to the fresh DB** before testing: run
  `SQL/supabase/002_full_app_schema.sql` then `003`, `004`, `005` (in order)
  via `docker exec supabase-db psql -U postgres -d postgres -f <file>`.
- **Seed an admin** by inserting into `auth.users` (bcrypt password via
  `crypt('pw', gen_salt('bf'))`, `email_confirmed_at=now()`, `role='authenticated'`)
  then upserting the `profiles` row with `role='admin'`. The signup trigger
  otherwise defaults new `profiles.role` to `user`.

## Migration 005 ‚Äî admin_get_all_users role ambiguity (standalone) ‚Äî 2026-08
`SQL/supabase/005_fix_admin_get_all_users_role_ambiguity.sql` is the
authoritative, standalone redefinition of the admin user-management RPCs:
- `admin_get_all_users()` ‚Äî `SECURITY DEFINER`, `set search_path = public`,
  table-qualified `p.role` in the WHERE clause to eliminate the PL/pgSQL
  name clash (the original "column reference \"role\" is ambiguous" error).
- `admin_set_user_status(target_id uuid, new_status text)` ‚Äî `SECURITY
  DEFINER` (bypasses RLS so admins can activate/deactivate any user), with
  admin-only guard + a self-status-change block (errcode 44000).
- Ensures `admin@benefitfinbnk.com` has `role='admin'`.
Verified live in-browser: All Users lists every user (no RLS banner), and the
toggle status button flips users active‚Üîinactive through the RPC.

## Admin panel JS gotchas (2026-08)
- `admin.html` live-chat render code called an `esc()` HTML-escape helper that
  was **never defined**, so `loadLiveChats()` threw a ReferenceError and the
  Live Chat section stayed stuck on "Loading‚Ä¶". Fix: a top-level
  `function esc(s){...}` is now defined near the other helpers. Any new code
  that interpolates user text into innerHTML must use `esc()` (or define it).
- `admin.html` `showSection(id)` uses the global `event` (`event.currentTarget`)
  rather than a passed element ‚Äî works in browsers but fragile in headless
  automation. `dashboard.html` `showSection(id, el)` is the cleaner pattern.
- When editing any HTML page's inline JS, extract the `<script>` blocks with a
  tiny python+regex and run `node --check`. A single syntax error in one
  function breaks the WHOLE inline script, so `init()` silently never runs and
  the page hangs on "Loading ... forever".
- Verified end-to-end (self-hosted Supabase): Deposit section now
  clicks/responds and creates `deposit_requests`; admin "All Users" loads all
  users (10-digit numeric accounts); Live Chat renders sessions;
  ATM card shows `‚Ä¢‚Ä¢‚Ä¢‚Ä¢ ‚Ä¢‚Ä¢‚Ä¢‚Ä¢ krsx` (last chars of digit-free account, no digits).


## 2026-08-19 ‚Äî Critical "admin sees nothing" postmortem + full E2E hardening
Root causes found together (ALL had to be fixed):
1. **Migrations never ran on the live project.** The GitHub Actions secret
   `SUPABASE_PROJECT_REF` was set to the wrong project while the site
   embeds `<PROJECT_REF>`. Every fix CI "applied" went to the wrong
   project. Verify secrets before debugging SQL.
2. **Migration 005 was foreign-schema**: it targeted `public.users`/`orders`
   of the wrong project and was rewritten to fix `admin_get_all_users` (drop +
   recreate; old signature returned a stale column set) and
   `admin_set_user_status` with table-qualified `p.role` (42702 ambiguity fix).
3. **Missing DROP guards** ‚Äî several migrations used bare
   `CREATE OR REPLACE FUNCTION` on RPCs whose `RETURNS` changed; Postgres
   rejects that (42P13) so the API kept the OLD broken function. All
   migrations 001/002/003/006/007/008 now `drop function if exists ...`
   first (inside `DO $$` guards where the table may not exist yet).
4. **Client/DB status mismatch for holds**: `admin_hold_funds` inserts
   transactions with `status='pending'` + `held=true`; the admin release
   modal must filter `filter_status: 'pending'` (was `'active'` -> always
   empty). `admin_get_stats` must count `held=true`, not `status='active'`.
5. **Widget script path**: pages must load `/chat-widget.js` (never
   `/public/chat-widget.js` ‚Äî that path 404s on Vercel).
6. **CI**: `deploy.yml` now FAILS the job if any migration returns non-2xx
   (was: warn-and-pass), and the duplicate `vercel deploy` job was removed ‚Äî
   Vercel's own Git integration deploys `public/`; the CLI job only failed.
   `workflow_dispatch:` lets you re-run migrations manually.

## CI migration 403 — RESOLVED (2026-08-23)
The `SUPABASE_ACCESS_TOKEN` secret had expired (403 on every migration run
since ~2026-08-20). The owner refreshed it on 2026-08-23; the full pipeline
(migrations 001→009 + live probe) is green again. If a 403 ever recurs,
mint a fresh token at https://supabase.com/dashboard/account/tokens and
update the repo secret.

Beware the probe design: an existence check must use the REAL param names —
PGRST202 fires for both "function absent" and "wrong/empty payload", so an
empty-body probe treats an existing function as missing. The current probe
calls `create_guest_ticket` with p_name/p_email/p_message (empty values ->
expected 22023 validation error proves existence).

## Guest + session live chat (008_guest_chat.sql)
- `public.create_guest_ticket(p_name, p_email, p_message)` (the widget passes
  p_ params) — SECURITY DEFINER, grants to `anon`, inserts `support_tickets`
  row with `user_id = NULL`, `category='live_chat'`, subject
  `Live Chat: <email>`, first message `[guest] <name> (<email>): <msg>`.
  Returns ticket uuid.
- Widget (`public/chat-widget.js`) ‚Äî the ONLY copy is in `public/`:
  - Logged-in users: widget passes `access_token` + `refresh_token` from
    `localStorage sb-<ref>-auth-token` into `createClient` options, so the
    INSERT carries the user's JWT (anon key alone would break RLS).
  - Guests: calls `create_guest_ticket` (no auth header needed).
  - Admin-reply dedup: on realtime UPDATE, if the ticket's `admin_reply`
    changed, append it as an admin bubble (skip if last bubble text is the
    same) ‚Äî the old code re-pushed it on every UPDATE.

## Support email + live chat everywhere + admin Email section (009) ‚Äî 2026-08-23
- **Support email identity** is `support@benefitfinbnk.com`. It is shown on
  /contact (Email Us card + footer, replacing a stray old template email
  leftover) and in the chat widget's guest form.
- **Chat widget is now on every public page**: it was missing from
  personal/business/cards/loans/faq/privacy-policy/terms-of-service ‚Äî the
  `<script src="/chat-widget.js"></script>` include was added before `</body>`
  in BOTH the root and `public/` copies (keep them in sync).
- **Contact form was dead** ‚Äî it posted to `homesendcontact`, an endpoint from
  the removed PHP app. It now calls the new
  `public.create_contact_message(p_name, p_email, p_phone, p_message)` RPC
  (migration `SQL/supabase/009_contact_messages.sql`, SECURITY DEFINER, grants
  to anon) which inserts a `support_tickets` row with `user_id=NULL`,
  `category='contact'`, subject `Contact form: <name>`, message with
  `From:`/`Phone:` trailer lines. The old `form-validator.min.js` /
  `contact-form-script.js` includes (jQuery, posted to `assets/php/...`) were
  removed from contact.html. **Applied to the live project by CI on push.**
- **Admin "Email" section** (nav: Email, `section-email` in admin.html):
  mail is modelled on `support_tickets`, same as the user Webmail ‚Äî
  INBOUND = non-live_chat ticket whose `message` is NOT one of the outbound
  placeholders `['Admin message','Notification','Welcome email','__chat_init__']`;
  SENT = non-live_chat ticket with `admin_reply` set. Reads use
  `admin_get_all_tickets` (cached in `window._allTickets`), reply uses
  `admin_reply_ticket` (lands in the user's Webmail), compose uses
  `admin_send_message`. Guest threads (user_id NULL, `From:` parsed from the
  message) hide the reply box and show a mailto: hand-off instead ‚Äî guests
  have no Webmail to receive a reply.
- **serve.js now rewrites `/chat-widget.js`** (same hosted‚Üí/supa replacement
  as .html) ‚Äî the widget embeds the hosted SUPA_URL/KEY and was bypassing the
  proxy in self-hosted mode.
- **Widget PROJECT_REF fix**: the session-token lookup regex only matched
  `*.supabase.co` hosts, so under the `/supa` proxy URL it derived a NULL ref
  and lost the user's JWT. It now falls back to the first hostname label,
  matching how supabase-js derives its `sb-<ref>-auth-token` storage key.
- E2E-verified against the live project via serve.js: widget guest send
  (create_guest_ticket) succeeds through the rewritten proxy; contact form
  success path requires 009 to be applied (verified in the Postgres recipe:
  valid insert + 22023 validation errors + admin_get_all_tickets sees the
  guest row).

## Webmail polish (threading + internal addresses + arrival polling) ‚Äî 2026-08-23
- **Internal addresses**: support mail displays as
  `Benefit Financial Bank Support <support@benefitfinbnk.com>`; a registered user's
  address is `<account_number lowercase>@benefitfinbnk.com`
  (`userMailAddress()` in dashboard.html, `userMailAddr()` in admin.html).
  Guests keep their real email. Purely presentational ‚Äî no SMTP involved.
- **Threading** (both dashboard Webmail and admin Email section): messages
  group by normalized subject ‚Äî `mailThreadKey`/`emailThreadKey` strip the
  `Support Message Sent ‚Äî ` prefix AND `Re: ` prefixes, so a ticket, its
  auto-confirmation notification, and all replies form ONE conversation.
  Read pane renders chat-style bubbles: each stored row contributes an
  outbound bubble (its `message`, unless a placeholder) and/or an inbound
  bubble (its `admin_reply`). `MAIL_PLACEHOLDERS` / `EMAIL_PLACEHOLDER_MSGS`
  must stay in sync with the literal `message` values used by
  `sendInboxMail` ('Notification'), `ensureWelcomeEmail` ('Welcome email'),
  and `admin_send_message` ('Admin message').
- **Reply prefill**: dashboard `replyToThread()` opens modal-ticket with
  subject `Re: <thread subject>` ‚Äî this is what makes threading work (the
  new ticket shares the normalized key).
- **Arrival polling**: dashboard polls `loadMail()` every 30s; a row that
  newly gains an `admin_reply` (or a brand-new inbound row) fires a toast +
  nav badge. `knownMailState[id]` tracks reply presence;
  `sendInboxMail` marks its own insert id known (via `.select('id')`) so its
  own toast doesn't double-fire. `renderMailPane(thread)` re-renders the
  open conversation on every loadMail so replies appear live.
- **Admin**: `openEmail(id)` renders the full thread (replies target the
  newest user-authored row, never overwriting an earlier admin_reply);
  `loadEmail()` runs at init and every 30s to keep the `nb-email` badge
  (open inbound count) fresh.
- E2E-verified live: 3‚Üí5-message thread with mixed in/out bubbles,
  internal addresses in meta lines, single toast on send, badge counts,
  live pane refresh.


## Verification recipe (no hosted Supabase access needed)
Spin up plain Postgres 16 (`docker run -e POSTGRES_PASSWORD=...`), stub the
auth layer, then `psql -f` each `SQL/supabase/*.sql` in order:
    create schema if not exists auth;
    create table auth.users(id uuid primary key, email text,
      email_confirmed_at timestamptz, raw_user_meta_data jsonb);
    create or replace function auth.uid() returns uuid language sql stable
      as $$ select nullif(current_setting('app.user_id', true), '')::uuid $$;
    create role anon; create role authenticated;
Set the "current user" per test with `set app.user_id = '<uuid>'`. This
proved: signup trigger (digit-free MV accounts), admin_get_all_users (no
42702), status toggle + self-toggle 44000, KYC/credit/hold/release,
deposit/transfer/loan review (approve credits balance + saves `note`,
reject saves note only), ticket append/status/reply, `create_guest_ticket`
(NULL user_id + validation errors), stats JSON, and non-admin 42501 denial.

## serve.js https upstreams
`SUPABASE_API_URL` may now be an `https://` URL (e.g. the hosted project);
the proxy picks `https.request` and port 443 automatically. Browser E2E:
`SUPABASE_API_URL=https://<project>.supabase.co SUPABASE_ANON_KEY=<hosted
anon key> PORT=12000 node serve.js` ‚Äî register, deposit, chat, login all
verified working against the live project this way.
