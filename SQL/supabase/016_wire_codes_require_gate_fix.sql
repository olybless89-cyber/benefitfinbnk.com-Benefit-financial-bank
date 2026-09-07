-- Benefit Financial Bank — fix wire-code gating for real this time (2026-09-07)
--
-- Root cause of "activated a code for a user, but their transfer went
-- through without ever asking for it":
--
--   The admin Wire Settings modal has always had TWO separate controls: the
--   three per-code Cost/Tax/Release toggles, AND a distinct "Global On/Off"
--   selector. Both `submit_wire_transfer` (here) and the dashboard's client
--   -side `wireEnabled()` treated a user's codes as active ONLY when that
--   separate selector was also set to true — but nothing in the UI made it
--   obvious that toggling a code ON was not, by itself, enough. An admin who
--   toggled a code ON, generated it, and saved — without also touching the
--   separate selector, which defaults to OFF — ended up with codes that
--   were saved and fully ready to share, but never actually requested from
--   the customer, and the transfer submitted straight through.
--
-- The admin.html UI (this same deploy) no longer exposes that second
-- control at all — toggling a code ON is now the only step, and it drives
-- the stored 'enabled' flag automatically. This migration makes the
-- SECURITY DEFINER RPC agree: a code is required the moment its `require`
-- flag is true, full stop, regardless of what 'enabled' says. That also
-- self-heals any user whose config already has this exact split (toggle
-- on, 'enabled' false) without needing an admin to reopen and resave them.
--
-- Idempotent: safe to re-run.

drop function if exists public.submit_wire_transfer(numeric, text, text, text);
create or replace function public.submit_wire_transfer(
    p_amount numeric,
    p_cost_code text,
    p_tax_code text,
    p_release_code text
  )
  returns table (amount numeric, type text, reference text, request_id uuid)
  language plpgsql
  security definer
  set search_path = public
  as $fz$
  declare
    uid uuid := auth.uid();
    wc jsonb;
    req_cost boolean;
    req_tax boolean;
    req_release boolean;
    wire_ref text;
    req_id uuid;
  begin
    if uid is null then
      raise exception 'Not authenticated' using errcode = '42501';
    end if;
    select wire_codes into wc from public.profiles where id = uid;
    if wc is null then
      wc := '{}'::jsonb;
    end if;

    -- A code is required the moment its own `require` flag is true — the
    -- separate 'enabled' flag is no longer part of the gate (see note
    -- above). Legacy configs saved before per-code toggles existed used a
    -- single 'enabled' flag to mean "all three required"; honor that only
    -- when there's no `require` block to read instead.
    if wc ? 'require' then
      req_cost := coalesce((wc -> 'require' ->> 'cost')::boolean, false);
      req_tax := coalesce((wc -> 'require' ->> 'tax')::boolean, false);
      req_release := coalesce((wc -> 'require' ->> 'release')::boolean, false);
    else
      req_cost := coalesce((wc->>'enabled')::boolean, false);
      req_tax := req_cost;
      req_release := req_cost;
    end if;

    if not (req_cost or req_tax or req_release) then
      raise exception 'International wire codes are not required for this user' using errcode = '44000';
    end if;
    if p_amount is null or p_amount <= 0 then
      raise exception 'Amount must be positive' using errcode = '22023';
    end if;
    if (req_cost AND (p_cost_code is null or btrim(p_cost_code) = ''))
       or (req_tax AND (p_tax_code is null or btrim(p_tax_code) = ''))
       or (req_release AND (p_release_code is null or btrim(p_release_code) = '')) then
      raise exception 'All required wire codes must be provided' using errcode = '22023';
    end if;
    if req_cost and wc->>'cost' is distinct from trim(p_cost_code) then
      raise exception 'Invalid cost-of-transfer code' using errcode = 'P0004';
    end if;
    if req_tax and wc->>'tax' is distinct from trim(p_tax_code) then
      raise exception 'Invalid tax code' using errcode = 'P0004';
    end if;
    if req_release and wc->>'release' is distinct from trim(p_release_code) then
      raise exception 'Invalid release code' using errcode = 'P0004';
    end if;
    update public.profiles p
       set balance = p.balance - p_amount,
           held_funds = p.held_funds + p_amount,
           updated_at = now()
     where p.id = uid and p.balance >= p_amount;
    if not found then
      raise exception 'Insufficient balance' using errcode = 'P0002';
    end if;
    wire_ref := 'WIRE-' || substr(md5(random()::text), 1, 8);
    insert into public.transfer_requests (user_id, amount, recipient_name, recipient_account, bank_name, reason, note, status)
    values (uid, p_amount, 'International Wire', 'Wire Desk', 'Benefit Financial Bank',
           'International wire (verified codes)', 'Cost: ' || p_cost_code || '; Tax: ' || p_tax_code || '; Release: ' || p_release_code, 'pending')
    returning id into req_id;
    insert into public.transactions (user_id, type, amount, status, description, reference, held)
    values (uid, 'hold', p_amount, 'pending', 'International wire fee hold', wire_ref, true);
    return query select p_amount::numeric, 'transfer', wire_ref::text, req_id::uuid;
  end;
  $fz$;

grant execute on function public.submit_wire_transfer(numeric, text, text, text) to anon, authenticated;

notify pgrst, 'reload schema';
