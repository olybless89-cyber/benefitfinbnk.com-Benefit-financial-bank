# Auto-Deploy Setup Guide

Every push to `main` automatically:
1. Runs all SQL migrations in `SQL/supabase/*.sql` against Supabase
2. Deploys the frontend to Vercel

## Required GitHub Secrets

Go to: **https://github.com/olybless89-cyber/benefitfinbnk.com-Benefit-financial-bank/settings/secrets/actions**  
Click **"New repository secret"** for each one below:

| Secret Name | Where to get it |
|---|---|
| `SUPABASE_ACCESS_TOKEN` | https://supabase.com/dashboard/account/tokens → "Generate new token" |
| `SUPABASE_PROJECT_REF` | Your project ref ID (the 10-char alphanumeric id from your Supabase project URL) |
| `VERCEL_TOKEN` | https://vercel.com/account/tokens → "Create Token" |
| `VERCEL_ORG_ID` | Run `vercel whoami` or check `.vercel/project.json` after `vercel link` |
| `VERCEL_PROJECT_ID` | Run `vercel link` in repo root → stored in `.vercel/project.json` |

> Note: a DB password is no longer required. Migrations run through the
> Supabase Management API `executeSql` endpoint using only the access token +
> project ref.

## How to get VERCEL_ORG_ID and VERCEL_PROJECT_ID

```bash
npm install -g vercel
cd /your/repo
vercel link        # follow prompts to link to existing project
cat .vercel/project.json   # shows orgId and projectId
```

## How migrations work

- All `.sql` files in `SQL/supabase/` are run in alphabetical order on every push
- Files are idempotent (`CREATE OR REPLACE`, `DROP POLICY IF EXISTS`) — safe to re-run
- To add a new fix: create `SQL/supabase/006_your_fix.sql` and push to main

## Workflow file location

`.github/workflows/deploy.yml`
