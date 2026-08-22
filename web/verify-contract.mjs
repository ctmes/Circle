/**
 * Contract check: every endpoint the UI calls, verified against the fields the
 * TypeScript interfaces in src/lib/api.ts actually read.
 *
 * A browser test would catch a blank page; this catches the more likely and
 * more insidious failure — the API renaming or dropping a field the UI quietly
 * depends on. Run with the stack up and demo.py already seeded.
 *
 *   node verify-contract.mjs
 */
const API = process.env.API ?? "http://localhost:8000/api";
const PASSWORD = "correct-horse-battery";

let pass = 0;
const fails = [];

const ok = (m) => { pass++; console.log(`  \x1b[32mPASS\x1b[0m ${m}`); };
const bad = (m) => { fails.push(m); console.log(`  \x1b[31mFAIL\x1b[0m ${m}`); };
const say = (m) => console.log(`\n\x1b[1m== ${m}\x1b[0m`);

async function call(method, path, token, body) {
  const res = await fetch(API + path, {
    method,
    headers: {
      Accept: "application/json",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...(body ? { "Content-Type": "application/json" } : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
  });
  const payload = res.status === 204 ? null : await res.json().catch(() => null);
  return { status: res.status, payload };
}

/** Asserts a dotted path exists on an object (null is a legitimate value). */
function has(obj, path, label) {
  const parts = path.split(".");
  let cur = obj;
  for (const part of parts) {
    if (cur === null || cur === undefined || !(part in cur)) {
      bad(`${label}: missing "${path}"`);
      return false;
    }
    cur = cur[part];
  }
  ok(`${label}: ${path}`);
  return true;
}

const login = async (email) =>
  (await call("POST", "/auth/login", null, { email, password: PASSWORD })).payload?.token;

const main = async () => {
  say("Authentication");
  const gm = await login("gm@jwamats.test");
  if (!gm) {
    bad("could not sign in as the GM — run `python demo.py` first to seed data");
    process.exit(1);
  }
  ok("signed in");

  const me = await call("GET", "/auth/me", gm);
  has(me.payload, "id", "GET /auth/me");
  has(me.payload, "name", "GET /auth/me");

  say("Organisations (used by the Open-a-Circle form)");
  const orgs = await call("GET", "/organisations", gm);
  if (Array.isArray(orgs.payload?.data) && orgs.payload.data.length) {
    has(orgs.payload.data[0], "id", "GET /organisations");
    has(orgs.payload.data[0], "name", "GET /organisations");
    has(orgs.payload.data[0], "slug", "GET /organisations");

    const org = await call("GET", `/organisations/${orgs.payload.data[0].slug}`, gm);
    has(org.payload?.data, "circles", "GET /organisations/{slug}");
  } else {
    bad("GET /organisations returned no organisations");
  }

  say("Circle list (CircleListView)");
  const list = await call("GET", "/circles", gm);
  const circles = list.payload?.data ?? [];
  if (!circles.length) {
    bad("no Circles visible — run `python demo.py` first");
    process.exit(1);
  }
  // Prefer an open Circle: the demo closes the one it creates last.
  const circle = circles.find((c) => !c.is_closed) ?? circles[0];
  for (const f of ["id", "name", "purpose", "status", "owner.name", "expires_at",
                   "is_closed", "is_expired", "my_role", "my_access.permissions"]) {
    has(circle, f, "GET /circles");
  }

  const id = circle.id;

  say("Overview (OverviewView)");
  const overview = (await call("GET", `/circles/${id}/overview`, gm)).payload?.data;
  for (const f of ["circle.name", "countdown.expired", "pending_decisions",
                   "commitments", "overdue_count", "recent_approvals"]) {
    has(overview, f, "GET /overview");
  }
  if ("next_decision" in (overview ?? {})) ok("GET /overview: next_decision");
  else bad("GET /overview: missing next_decision");
  if ("latest_brief" in (overview ?? {})) ok("GET /overview: latest_brief");
  else bad("GET /overview: missing latest_brief");

  say("Evidence register (ContextView)");
  const evidence = (await call("GET", `/circles/${id}/evidence`, gm)).payload?.data ?? [];
  if (!evidence.length) bad("no evidence returned");
  else {
    const item = evidence[0];
    for (const f of ["id", "name", "origin_status", "integrity_status", "review_status",
                     "classification", "agent_read", "downloadable", "uploader.name",
                     "version_count", "current_version.id", "current_version.sha256",
                     "current_version.lane", "current_version.processing_status",
                     "current_version.extracted_text_status", "current_version.preview_status",
                     "current_version.transcript_status", "current_version.byte_size"]) {
      has(item, f, "GET /evidence");
    }

    const detail = (await call("GET", `/evidence/${item.id}`, gm)).payload?.data;
    has(detail, "versions", "GET /evidence/{id}");
    has(detail, "used_by.claims", "GET /evidence/{id}");
    has(detail, "used_by.decisions", "GET /evidence/{id}");

    const dl = await call("GET", `/evidence-versions/${item.current_version.id}/download-url`, gm);
    if (dl.status === 200) has(dl.payload?.data, "url", "GET /download-url");
    else bad(`GET /download-url returned ${dl.status}`);
  }

  say("Claims (ClaimsView)");
  const claims = (await call("GET", `/circles/${id}/claims`, gm)).payload?.data ?? [];
  if (!claims.length) bad("no claims returned");
  else {
    const claim = claims[0];
    for (const f of ["id", "statement", "claim_type", "status", "author_type",
                     "derived", "citations", "reviews", "created_at"]) {
      has(claim, f, "GET /claims");
    }
    const cited = claims.find((c) => c.citations.length);
    if (cited) {
      for (const f of ["evidence_version_id", "citation_type", "locator", "evidence.version_number",
                       "evidence.integrity_status"]) {
        has(cited.citations[0], f, "GET /claims citation");
      }
    } else bad("no claim carried a citation");
  }

  say("Decisions (DecisionsView)");
  const decisions = (await call("GET", `/circles/${id}/decisions`, gm)).payload?.data ?? [];
  if (!decisions.length) bad("no decisions returned");
  else {
    for (const f of ["id", "title", "status", "approver.name", "created_by.name",
                     "subject.type", "subject.version", "approval_history", "derived"]) {
      has(decisions[0], f, "GET /decisions");
    }
  }

  say("Commitments (CommitmentsView)");
  const commitments = (await call("GET", `/circles/${id}/commitments`, gm)).payload?.data ?? [];
  if (!commitments.length) bad("no commitments returned");
  else {
    for (const f of ["id", "title", "status", "owner.name", "due_at", "is_overdue",
                     "derived", "updates"]) {
      has(commitments[0], f, "GET /commitments");
    }
  }

  say("People and agent (PeopleView)");
  const people = (await call("GET", `/circles/${id}/members`, gm)).payload?.data;
  has(people, "members", "GET /members");
  has(people, "pending_invitations", "GET /members");
  has(people, "agents", "GET /members");
  if (people?.members?.length) {
    for (const f of ["membership_id", "user.name", "user.email", "circle_role",
                     "is_external", "is_active", "permissions"]) {
      has(people.members[0], f, "GET /members member");
    }
  }
  if (people?.agents?.length) {
    for (const f of ["agent_instance_id", "name", "version", "mandate",
                     "allowed_actions", "prohibited_actions", "can_access"]) {
      has(people.agents[0], f, "GET /members agent");
    }
  } else bad("no agent listed");

  say("History and chain (HistoryView)");
  const history = (await call("GET", `/circles/${id}/history?limit=10`, gm)).payload;
  has(history, "meta.chain.valid", "GET /history");
  has(history, "meta.chain.events_checked", "GET /history");
  if (history?.data?.length) {
    for (const f of ["sequence", "id", "event_type", "actor_type", "occurred_at",
                     "summary", "metadata", "previous_hash", "event_hash"]) {
      has(history.data[0], f, "GET /history event");
    }
  } else bad("no audit events returned");

  const verify = (await call("GET", `/circles/${id}/history/verify`, gm)).payload?.data;
  has(verify, "valid", "GET /history/verify");
  has(verify, "reason", "GET /history/verify");

  say("Agent runs (Steward panel)");
  const runs = (await call("GET", `/circles/${id}/agent-runs`, gm)).payload?.data ?? [];
  if (runs.length) {
    for (const f of ["id", "status", "label", "derived", "model_provider", "model_name",
                     "prompt_version", "blueprint_version", "retrieval_manifest",
                     "resource_accesses", "output"]) {
      has(runs[0], f, "GET /agent-runs");
    }
  } else bad("no agent runs returned");

  say("Export (ExportView)");
  const exportRes = await call("POST", `/circles/${id}/exports`, gm, {});
  if (exportRes.status === 201) {
    for (const f of ["id", "status", "sha256", "byte_size", "audit_chain_valid",
                     "manifest.files", "manifest.contains_originals", "download_url"]) {
      has(exportRes.payload?.data, f, "POST /exports");
    }
  } else bad(`POST /exports returned ${exportRes.status}`);

  say("Policy refusals carry a readable reason");
  const stranger = (await call("POST", "/auth/register", null, {
    name: "Contract Probe",
    email: `contract-probe-${Date.now()}@nowhere.test`,
    password: PASSWORD + "-probe",
  })).payload?.token;
  const refused = await call("GET", `/circles/${id}`, stranger);
  if (refused.status === 403 && typeof refused.payload?.message === "string" && refused.payload.message.length > 10) {
    ok(`403 explains itself: "${refused.payload.message}"`);
  } else {
    bad(`expected an explanatory 403, got ${refused.status}: ${JSON.stringify(refused.payload)}`);
  }

  const total = pass + fails.length;
  const colour = fails.length ? "\x1b[31m" : "\x1b[32m";
  console.log(`\n\x1b[1m${colour}${"=".repeat(18)} ${pass}/${total} contract checks passed ${"=".repeat(18)}\x1b[0m`);
  for (const f of fails) console.log(`  \x1b[31m·\x1b[0m ${f}`);
  process.exit(fails.length ? 1 : 0);
};

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
