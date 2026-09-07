-- ─────────────────────────────────────────────────────────────────────────────
-- Fix: admin_reset_user_password / admin_reset_all_user_passwords / the
-- internal password generator (017_admin_reset_password.sql) failed live in
-- the admin panel with:
--
--   function gen_random_bytes(integer) does not exist
--
-- Root cause: Supabase installs pgcrypto (and its other managed extensions)
-- into a dedicated `extensions` schema, not `public` — and normally keeps
-- that schema on every role's default search_path so `gen_random_bytes` /
-- `crypt` / `gen_salt` just work without qualification. But 017's functions
-- pin `set search_path = public` (to guard against the search_path-hijacking
-- class of SECURITY DEFINER vulnerability), which *replaces* the role's
-- default search_path for the duration of the call — dropping `extensions`
-- along with it, so pgcrypto's functions stop resolving. This is why the
-- exact same `crypt('pw', gen_salt('bf'))` call works fine when pasted into
-- the Supabase SQL editor (that runs under the role's normal search_path)
-- but failed inside these SECURITY DEFINER functions.
--
-- Fix: widen search_path to `public, extensions` on the three functions that
-- actually call pgcrypto. No signature or return-type changes, so a plain
-- CREATE OR REPLACE is enough (no DROP needed) — safe to re-run.
--
-- Verified against a local Postgres 16 instance that reproduces Supabase's
-- exact convention (pgcrypto installed into a separate `extensions` schema,
-- database default search_path = public, extensions): applying 001-017
-- as-is reproduces this exact error byte-for-byte; applying this migration
-- on top fixes both admin_reset_user_password and admin_reset_all_user_passwords.
-- ─────────────────────────────────────────────────────────────────────────────

create or replace function public._bfb_gen_password(len int default 12)
returns text
language plpgsql
set search_path = public, extensions
as $$
declare
  chars text := 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
  out_pw text := '';
  i int;
begin
  for i in 1..len loop
    out_pw := out_pw || substr(chars, (get_byte(gen_random_bytes(1), 0) % length(chars)) + 1, 1);
  end loop;
  return out_pw;
end;
$$;

create or replace function public.admin_reset_user_password(
  target_id uuid, p_new_password text default null
)
returns table(user_id uuid, email text, account_number text, new_password text)
language plpgsql
security definer
set search_path = public, extensions
as $$
declare
  v_pw text;
  v_email text;
  v_account text;
  v_role text;
begin
  if not public.is_admin() then
    raise exception 'Access denied: admins only' using errcode = '42501';
  end if;
  if target_id = auth.uid() then
    raise exception 'Use the Security section to change your own password' using errcode = '44000';
  end if;

  select p.email, p.account_number, p.role into v_email, v_account, v_role
    from public.profiles p where p.id = target_id;
  if not found then
    raise exception 'User not found' using errcode = 'P0002';
  end if;
  if v_role = 'admin' then
    raise exception 'Cannot reset another admin''s password from here' using errcode = '44000';
  end if;

  v_pw := coalesce(p_new_password, public._bfb_gen_password(12));
  if length(v_pw) < 8 then
    raise exception 'Password must be at least 8 characters' using errcode = '22023';
  end if;

  update auth.users set encrypted_password = crypt(v_pw, gen_salt('bf')), updated_at = now()
    where id = target_id;

  -- Best-effort: revoke existing refresh tokens so a session opened with the
  -- OLD password can't keep riding on it. See 017 for why the table is
  -- aliased (art) and every column table-qualified (the function's own
  -- user_id OUT parameter otherwise silently shadows the table column).
  begin
    update auth.refresh_tokens art set revoked = true
      where art.user_id::text = target_id::text and art.revoked = false;
  exception when others then null;
  end;

  return query select target_id, v_email, v_account, v_pw;
end;
$$;

create or replace function public.admin_reset_all_user_passwords()
returns table(user_id uuid, email text, account_number text, new_password text)
language plpgsql
security definer
set search_path = public, extensions
as $$
declare
  r record;
  v_pw text;
begin
  if not public.is_admin() then
    raise exception 'Access denied: admins only' using errcode = '42501';
  end if;

  for r in select p.id, p.email, p.account_number from public.profiles p where p.role <> 'admin' loop
    v_pw := public._bfb_gen_password(12);
    update auth.users set encrypted_password = crypt(v_pw, gen_salt('bf')), updated_at = now()
      where id = r.id;
    begin
      update auth.refresh_tokens art set revoked = true
        where art.user_id::text = r.id::text and art.revoked = false;
    exception when others then null;
    end;
    user_id := r.id; email := r.email; account_number := r.account_number; new_password := v_pw;
    return next;
  end loop;
end;
$$;
