-- ─────────────────────────────────────────────────────────────────────────────
-- Admin: reset a customer's password (single user or all users at once) and
-- deliver the new password through the app's own channels.
--
-- Problem: the only password-reset path was the customer's own "forgot
-- password" email flow (forgot-password.html -> resetPasswordForEmail). There
-- was no way for an admin to set a new password for a locked-out customer, or
-- to force a reset for every customer at once (e.g. after a suspected
-- credential leak), from the admin panel.
--
-- This app has no real outbound SMTP (see README.md — "contact/webmail are
-- in-app only, no SMTP used"), so "email" here means the in-app Webmail
-- (support_tickets), exactly like every other admin -> user message
-- (admin_send_message, the Wire Codes share flow, etc). "Live chat" delivers
-- into the same live-chat session the chat widget / admin Live Chat panel
-- use (support_tickets, category='live_chat', appended via
-- admin_append_ticket_message from 007_admin_user_txn_and_livechat.sql).
--
-- Approach: SECURITY DEFINER functions, admin-guarded via public.is_admin()
-- (same pattern as every other admin_* RPC in this repo — see
-- 013_admin_user_transaction_management.sql), that write the bcrypt hash
-- straight into auth.users.encrypted_password via pgcrypto. No service_role
-- key or Supabase Admin API access is needed — the SQL editor / CI migration
-- pipeline is enough, same as every other fix in this repo.
--
-- Adds:
--   • public._bfb_gen_password(len)        — internal random-password helper
--       (not granted to anon/authenticated; only called from the functions
--       below, which run as the definer).
--   • public.admin_reset_user_password(target_id, new_password default null)
--       Resets one non-admin user's password (auto-generates one when
--       new_password is null — recommended, avoids the admin picking
--       something weak) and best-effort revokes their existing sessions.
--   • public.admin_reset_all_user_passwords()
--       Same, for every non-admin user at once. Returns one row per user
--       with their new password so the admin can review/send them.
--   • public.admin_deliver_password(target_user, p_message, channel, p_subject)
--       Delivers a message (built by the caller, e.g. "Your new password is
--       ...") to the target user via channel='webmail' or 'live_chat'.
--
-- Idempotent: safe to re-run.
-- ─────────────────────────────────────────────────────────────────────────────

create extension if not exists pgcrypto;

-- ── internal: random temp-password generator ──────────────────────────────
-- Unambiguous charset (no 0/O, 1/l/I) mixing upper/lower/digits. Not granted
-- to anon/authenticated — only ever called from the SECURITY DEFINER
-- functions below, which execute as the function owner regardless of who
-- calls them, so no grant is required for that internal call to succeed.
drop function if exists public._bfb_gen_password(int);
create or replace function public._bfb_gen_password(len int default 12)
returns text
language plpgsql
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

-- ── admin_reset_user_password ─────────────────────────────────────────────
-- Pass NULL (the default) to auto-generate a password. Blocks resetting your
-- own password (use the existing Security section for that) and resetting
-- another admin's password (same class of self/peer guard as
-- admin_delete_user in 013).
drop function if exists public.admin_reset_user_password(uuid, text);
create or replace function public.admin_reset_user_password(
  target_id uuid, p_new_password text default null
)
returns table(user_id uuid, email text, account_number text, new_password text)
language plpgsql
security definer
set search_path = public
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
  -- OLD password can't keep riding on it. auth.refresh_tokens' user_id column
  -- type has varied across GoTrue versions, so this is deliberately
  -- swallow-on-error and never blocks the actual password reset above.
  -- The table is aliased (art) and every column table-qualified: this
  -- function's OUT parameter is itself named user_id (RETURNS TABLE(user_id
  -- uuid, ...)), and an unqualified `user_id` in the UPDATE below is
  -- ambiguous between that PL/pgSQL variable and the table column — the same
  -- bug class as the historical "role"/"note" ambiguity bugs elsewhere in
  -- this repo. Unqualified, it silently resolves to the variable and the
  -- UPDATE's WHERE clause never matches any row, so this block would appear
  -- to succeed (swallowed by the exception handler) while actually revoking
  -- nothing. Verified against a local Postgres 16 instance.
  begin
    update auth.refresh_tokens art set revoked = true
      where art.user_id::text = target_id::text and art.revoked = false;
  exception when others then null;
  end;

  return query select target_id, v_email, v_account, v_pw;
end;
$$;

-- ── admin_reset_all_user_passwords ────────────────────────────────────────
-- Same as above, applied to every non-admin user. Returns one row per user
-- (id, email, account number, new password) so the admin panel can list them
-- for review before sending anything out.
drop function if exists public.admin_reset_all_user_passwords();
create or replace function public.admin_reset_all_user_passwords()
returns table(user_id uuid, email text, account_number text, new_password text)
language plpgsql
security definer
set search_path = public
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
    -- See the matching comment in admin_reset_user_password above: this
    -- function's OUT parameter is also named user_id, so the table must be
    -- aliased and qualified here too or the UPDATE silently matches nothing.
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

-- ── admin_deliver_password ────────────────────────────────────────────────
-- channel = 'webmail' inserts a closed ticket the same shape as
-- admin_send_message (message='Admin message', admin_reply=p_message) so it
-- renders in the customer's Webmail exactly like every other admin message.
-- channel = 'live_chat' finds the customer's most recent live-chat session
-- (or starts one, same shape the chat widget itself creates) and appends the
-- message via admin_append_ticket_message (007), so it shows up as a normal
-- chat bubble, live, the same way a manual reply would.
drop function if exists public.admin_deliver_password(uuid, text, text, text);
create or replace function public.admin_deliver_password(
  target_user uuid, p_message text, channel text,
  p_subject text default 'Your password has been reset'
)
returns void
language plpgsql
security definer
set search_path = public
as $$
declare
  v_ticket uuid;
  v_name text;
begin
  if not public.is_admin() then
    raise exception 'Access denied: admins only' using errcode = '42501';
  end if;
  if channel not in ('webmail', 'live_chat') then
    raise exception 'channel must be ''webmail'' or ''live_chat''' using errcode = '22023';
  end if;
  if not exists (select 1 from public.profiles p where p.id = target_user) then
    raise exception 'User not found' using errcode = 'P0002';
  end if;

  if channel = 'webmail' then
    insert into public.support_tickets (user_id, subject, message, category, admin_reply, status)
    values (target_user, p_subject, 'Admin message', 'security', p_message, 'closed');
    return;
  end if;

  -- live_chat
  select t.id into v_ticket from public.support_tickets t
    where t.user_id = target_user and t.category = 'live_chat'
    order by t.created_at desc limit 1;

  if not found then
    select coalesce(p.full_name, p.email) into v_name from public.profiles p where p.id = target_user;
    insert into public.support_tickets (user_id, category, subject, message, status)
    values (target_user, 'live_chat', 'Live chat — ' || coalesce(v_name, 'User'), '__chat_init__', 'open')
    returning id into v_ticket;
  end if;

  perform public.admin_append_ticket_message(v_ticket, p_message, p_message, 'open');
end;
$$;

grant execute on function public.admin_reset_user_password(uuid, text) to anon, authenticated;
grant execute on function public.admin_reset_all_user_passwords() to anon, authenticated;
grant execute on function public.admin_deliver_password(uuid, text, text, text) to anon, authenticated;

notify pgrst, 'reload schema';
