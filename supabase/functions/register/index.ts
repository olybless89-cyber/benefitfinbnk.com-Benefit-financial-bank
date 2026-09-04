import { createClient } from "https://esm.sh/@supabase/supabase-js@2";

const supabase = createClient(
  Deno.env.get("SUPABASE_URL") ?? "",
  Deno.env.get("SUPABASE_SERVICE_ROLE_KEY") ?? Deno.env.get("SUPABASE_ANON_KEY") ?? ""
);

Deno.serve(async (req) => {
  if (req.method !== "POST") {
    return new Response(JSON.stringify({ error: "Method not allowed" }), {
      status: 405,
      headers: { "Content-Type": "application/json" },
    });
  }

  let body;
  try {
    body = await req.json();
  } catch {
    return new Response(JSON.stringify({ error: "Invalid JSON body" }), {
      status: 400,
      headers: { "Content-Type": "application/json" },
    });
  }

  const email = String(body.email ?? "").trim().toLowerCase();
  const password = String(body.password ?? "");
  const full_name = String(body.full_name ?? "").trim();

  if (!email || !email.includes("@") || password.length < 6) {
    return Response.json({ error: "Invalid email or password (min. 6 characters)" }, { status: 400 });
  }

  const pw = String(password);
  if (pw.length < 6 || pw.length > 72) {
    return Response.json({ error: "Password must be 6–72 characters" }, { status: 400 });
  }

  const { data, error } = await supabase.auth.admin.createUser({
    email,
    password: pw,
    email_confirm: true,
    user_metadata: { full_name },
  });

  if (error) {
    if (/(already|exists|registered)/i.test(error.message || "")) {
      return Response.json({ error: "An account with this email already exists." }, { status: 409 });
    }
    return Response.json({ error: error.message || "Registration failed" }, { status: 400 });
  }

  if (!data.user) {
    return Response.json({ error: "Account creation failed" }, { status: 500 });
  }

  // Mint a session for the new user (confirmation is bypassed via email_confirm: true).
  const { data: session, error: sessErr } = await supabase.auth.signInWithPassword({ email, password: pw });
  if (sessErr || !session?.session) {
    // Account exists but session could not be minted (rare): tell the user to log in.
    return Response.json({ user: { id: data.user.id, email: data.user.email } }, { status: 200 });
  }

  return Response.json({
    access_token: session.session.access_token,
    refresh_token: session.session.refresh_token,
    user: { id: session.session.user.id, email: session.session.user.email },
  }, { status: 200 });
});