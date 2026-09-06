# Auto-Deploy Active
Every push to `main` automatically:
1. Runs SQL migrations in `SQL/supabase/` against the Supabase project named by
   the `SUPABASE_PROJECT_REF` secret. The site embeds project
   `<PROJECT_REF>` (from the committed `<SUPA...>` placeholders), so the secret must
   point at that project.
2. Frontend is deployed by Vercel's own Git integration (the CI `vercel deploy`
   job was removed — it duplicated the integration and failed on every run).

## Railway runtime (2026-09-06)
- Railway start command: `node serve.js` (Node 22 via Nixpacks; PHP/php81
  removed — the `php -S` build crashed before Railway could register the app,
  leaving the site on railway `x-railway-fallback`{Application not found}).
- `serve.js` serves `public/` with the clean-URL rewrites, vendors the Supabase
  SDK, and rewrites/serves the hosted Supabase URL/anon key at serve time.

Last updated: 2026-09-06

