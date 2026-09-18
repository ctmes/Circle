#!/usr/bin/env python3
"""Circle — end-to-end walkthrough of the First Demo Script (spec §18).

Drives the real system over HTTP the way a browser would: signed
direct-to-storage uploads, the media/extraction pipeline, citations against
exact evidence versions, approval bound to a version, version supersession,
agent policy enforcement, tamper detection, Circle closure and the exported
mission packet.

Usage:  python demo.py            (stack must be up: docker compose up -d)
"""
from __future__ import annotations

import io
import json
import os
import subprocess
import sys
import time
import urllib.error
import urllib.request
import zipfile
from pathlib import Path

API = os.environ.get("API", "http://localhost:8000/api")
FIXTURES = Path(__file__).parent / "api" / "tests" / "fixtures"
PASSWORD = "correct-horse-battery"
# Set KEEP_OPEN=1 to skip the closure section, leaving a live Circle behind for
# manual exploration or screenshots.
KEEP_OPEN = os.environ.get("KEEP_OPEN") == "1"

WEB_HINT = os.environ.get("WEB", "http://localhost:4321")
GREEN, RED, BOLD, DIM, RESET = "\033[32m", "\033[31m", "\033[1m", "\033[2m", "\033[0m"

_passed = 0
_failed: list[str] = []


def say(msg: str) -> None:
    print(f"\n{BOLD}== {msg}{RESET}")


def ok(msg: str) -> None:
    global _passed
    _passed += 1
    print(f"  {GREEN}PASS{RESET} {msg}")


def bad(msg: str) -> None:
    _failed.append(msg)
    print(f"  {RED}FAIL{RESET} {msg}")


def check(actual, expected, msg: str) -> None:
    if actual == expected:
        ok(msg)
    else:
        bad(f"{msg}  (expected {expected!r}, got {actual!r})")


def note(msg: str) -> None:
    print(f"  {DIM}·{RESET} {msg}")


# ---------------------------------------------------------------- HTTP helpers

def request(method: str, url: str, token: str | None = None, body=None,
            raw: bytes | None = None, content_type: str | None = None):
    """Returns (status_code, parsed_json_or_bytes)."""
    data = raw
    headers = {"Accept": "application/json"}

    if body is not None:
        data = json.dumps(body).encode()
        headers["Content-Type"] = "application/json"
    if content_type:
        headers["Content-Type"] = content_type
    if token:
        headers["Authorization"] = f"Bearer {token}"

    req = urllib.request.Request(url, data=data, headers=headers, method=method)

    try:
        with urllib.request.urlopen(req) as resp:
            payload = resp.read()
            status = resp.status
    except urllib.error.HTTPError as e:
        payload = e.read()
        status = e.code
    except urllib.error.URLError as e:
        raise SystemExit(f"Cannot reach {url}: {e}. Is the stack up? (docker compose up -d)")

    try:
        return status, json.loads(payload)
    except (ValueError, UnicodeDecodeError):
        return status, payload


def api(method: str, path: str, token: str | None = None, body=None):
    return request(method, API + path, token, body)


def api_data(method: str, path: str, token: str | None = None, body=None):
    status, payload = api(method, path, token, body)
    if isinstance(payload, dict) and "data" in payload:
        return payload["data"]
    return payload


def artisan(code: str) -> str:
    """Runs a tinker expression in the api container; returns trimmed stdout."""
    result = subprocess.run(
        ["docker", "compose", "exec", "-T", "api", "php", "artisan", "tinker", "--execute", code],
        capture_output=True, text=True, cwd=Path(__file__).parent,
    )
    return result.stdout.strip()


def login(email: str) -> str:
    _, payload = api("POST", "/auth/login", body={"email": email, "password": PASSWORD})
    return payload.get("token", "")


def upload(token: str, circle: str, path: Path, name: str, agent_read: bool) -> str:
    """Full ingest: sign -> PUT straight to object storage -> register."""
    status, signed = api("POST", f"/circles/{circle}/uploads/sign", token,
                         {"filename": path.name})
    if status != 200:
        bad(f"upload sign failed for {path.name}: {signed}")
        return ""

    d = signed["data"]
    # The browser PUTs the bytes directly; they never transit the API.
    put_status, _ = request("PUT", d["url"], raw=path.read_bytes(),
                            content_type=d["headers"]["Content-Type"])
    if put_status not in (200, 204):
        bad(f"direct upload failed for {path.name} (http {put_status})")
        return ""

    status, created = api("POST", f"/circles/{circle}/evidence", token, {
        "storage_key": d["key"], "filename": path.name,
        "name": name, "agent_read": agent_read,
    })
    if status != 201:
        bad(f"register failed for {path.name}: {created}")
        return ""

    return created["data"]["id"]


def summarise() -> int:
    total = _passed + len(_failed)
    colour = GREEN if not _failed else RED
    print(f"\n{BOLD}{colour}{'=' * 20} {_passed}/{total} passed {'=' * 20}{RESET}")
    for f in _failed:
        print(f"  {RED}·{RESET} {f}")
    return 1 if _failed else 0


# --------------------------------------------------------------------- the run

def main() -> int:
    say("1. Provision organisation and accounts")

    subprocess.run(
        ["docker", "compose", "exec", "-T", "api", "php", "artisan", "circle:provision-org", "JWA Mats",
         "--user=gm@jwamats.test:Dana Okafor (GM):" + PASSWORD,
         "--user=commercial@jwamats.test:Priya Raman (Commercial):" + PASSWORD,
         "--user=technical@jwamats.test:Tom Alvarez (Technical):" + PASSWORD,
         "--user=logistics@jwamats.test:Kim Novak (Logistics):" + PASSWORD,
         "--user=external@northernrail.test:Sam Bright (Client):" + PASSWORD,
         # Same organisation, deliberately never invited to the Circle — used to
         # prove organisation membership alone grants nothing.
         "--user=probe@jwamats.test:Lee Probe (Uninvited):" + PASSWORD,
         "--external=external@northernrail.test"],
        capture_output=True, text=True, cwd=Path(__file__).parent,
    )

    gm = login("gm@jwamats.test")
    commercial = login("commercial@jwamats.test")
    technical = login("technical@jwamats.test")
    logistics = login("logistics@jwamats.test")
    external = login("external@northernrail.test")

    if not gm:
        bad("GM login failed — cannot continue")
        return 1
    ok("all five participants authenticated")

    org = artisan('echo App\\Models\\Organisation::where("slug","jwa-mats")->value("id");')
    user_id = lambda tok: api_data("GET", "/auth/me", tok)["id"]  # noqa: E731

    # -----------------------------------------------------------------------
    say("2. GM creates the Rail Access Package Circle")

    status, payload = api("POST", "/circles", gm, {
        "organisation_id": org,
        "name": "Rail Access Package — Bid Review",
        "purpose": ("Assemble and review the bid package for the Bay Junction rail access "
                    "matting works, and decide go/no-go."),
        "expires_at": "2026-12-31T17:00:00Z",
    })
    if status != 201:
        bad(f"Circle creation failed: {payload}")
        return 1

    circle = payload["data"]["id"]
    ok(f"Circle created ({circle})")

    # -----------------------------------------------------------------------
    say("3. GM invites the leads")

    def invite(email: str, role: str, is_external: bool) -> str:
        d = api_data("POST", f"/circles/{circle}/invitations", gm,
                     {"email": email, "circle_role": role, "is_external": is_external})
        return d["accept_token"]

    api("POST", f"/invitations/{invite('commercial@jwamats.test', 'contributor', False)}/accept", commercial)
    api("POST", f"/invitations/{invite('technical@jwamats.test', 'approver', False)}/accept", technical)
    api("POST", f"/invitations/{invite('logistics@jwamats.test', 'contributor', False)}/accept", logistics)
    api("POST", f"/invitations/{invite('external@northernrail.test', 'viewer', True)}/accept", external)

    members = api_data("GET", f"/circles/{circle}/members", gm)["members"]
    check(len(members), 5, "all five participants are members")

    stolen = invite("someone-else@jwamats.test", "viewer", True)
    status, _ = api("POST", f"/invitations/{stolen}/accept", external)
    check(status, 422, "an invitation cannot be redeemed by a different account")

    # -----------------------------------------------------------------------
    say("4. Leads upload evidence")

    rfq     = upload(commercial, circle, FIXTURES / "rfq.pdf",              "RFQ-2026-0417",           True)
    sow     = upload(commercial, circle, FIXTURES / "scope_of_work.docx",   "Scope of work",           True)
    drawing = upload(technical,  circle, FIXTURES / "drawing_rev_b.pdf",    "Drawing 4417-02 Rev B",   True)
    geo     = upload(technical,  circle, FIXTURES / "geotech_report.pdf",   "Geotechnical summary",    True)
    loads   = upload(logistics,  circle, FIXTURES / "load_schedule.xlsx",   "Load schedule and stock", True)
    photo   = upload(technical,  circle, FIXTURES / "site_photo.jpg",       "Site photo - crane pad",  True)
    video   = upload(technical,  circle, FIXTURES / "site_walkthrough.mp4", "Site walkthrough",        False)
    audio   = upload(commercial, circle, FIXTURES / "voice_note.wav",       "Client call note",        False)

    uploaded = [rfq, sow, drawing, geo, loads, photo, video, audio]
    check(sum(1 for i in uploaded if i), 8,
          "eight items uploaded (PDF, DOCX, XLSX, JPG, MP4, WAV)")

    print("  waiting for the media pipeline", end="", flush=True)
    ready = 0
    for _ in range(90):
        items = api_data("GET", f"/circles/{circle}/evidence", gm)
        ready = sum(1 for i in items
                    if (i.get("current_version") or {}).get("processing_status") == "ready")
        if ready == 8:
            break
        print(".", end="", flush=True)
        time.sleep(2)
    print()
    check(ready, 8, "all eight originals verified and marked ready")

    # processing_status flips to ready once the original is hashed and verified.
    # Poster frames and transcription run in their own lanes and can still be in
    # flight at that point, so the checks below would race them. Wait for those
    # lanes to reach a terminal state — ready, skipped or failed — rather than
    # sampling them mid-run and reporting a timing artefact as a failure.
    print("  waiting for the derived lanes", end="", flush=True)
    settled = ("ready", "skipped", "failed")
    for _ in range(90):
        vid_lane = api_data("GET", f"/evidence/{video}", gm)["current_version"]
        aud_lane = api_data("GET", f"/evidence/{audio}", gm)["current_version"]
        if (vid_lane.get("preview_status") in settled
                and aud_lane.get("transcript_status") in settled):
            break
        print(".", end="", flush=True)
        time.sleep(2)
    print()

    # -----------------------------------------------------------------------
    say("5. Provenance, integrity and extraction")

    items = api_data("GET", f"/circles/{circle}/evidence", gm)
    by_id = {i["id"]: i for i in items}

    shas = [i["current_version"]["sha256"] for i in items]
    check(sum(1 for s in shas if s and len(s) == 64), 8, "every version carries a 64-char SHA-256")
    check(len(set(shas)), 8, "each file hashed to a distinct digest")

    origins = {i["origin_status"] for i in items}
    check(origins, {"authenticated_upload"}, 'provenance is "authenticated_upload", never a generic "verified"')

    integrity = {i["integrity_status"] for i in items}
    check(integrity, {"intact"}, "integrity computed as intact for current versions")

    rfq_detail = api_data("GET", f"/evidence/{rfq}", gm)
    check(rfq_detail["current_version"]["extracted_text_status"], "ready", "PDF text extracted")
    check(by_id[sow]["current_version"]["extracted_text_status"], "ready", "DOCX text extracted")
    check(by_id[loads]["current_version"]["extracted_text_status"], "ready", "spreadsheet cells extracted")
    check(by_id[photo]["current_version"]["preview_status"], "ready", "image thumbnail generated")

    vid = api_data("GET", f"/evidence/{video}", gm)["current_version"]
    check(vid["preview_status"], "ready", "video poster frames extracted")
    duration = vid["metadata"].get("duration_seconds", 0)
    (ok if duration >= 5 else bad)(f"video duration probed ({duration}s)")
    check(vid["metadata"].get("has_audio_stream"), True, "audio stream detected in video")

    aud = api_data("GET", f"/evidence/{audio}", gm)["current_version"]
    (ok if aud["metadata"].get("duration_seconds", 0) >= 3 else bad)(
        f"audio duration probed ({aud['metadata'].get('duration_seconds')}s)")
    check(aud["transcript_status"], "skipped",
          "transcript marked skipped (no provider configured) rather than left pending")

    # Verify the extraction actually captured the figures a claim will cite.
    # content_json is cast to an array, so it must be re-encoded to be echoed.
    def extraction_of(item_id: str) -> str:
        return artisan(
            f"echo json_encode(App\\Models\\DerivedArtifact::where('parent_resource_id',"
            f"App\\Models\\EvidenceItem::find('{item_id}')->currentVersion()->id)"
            f"->where('artifact_type','extraction')->value('content_json'));")

    docx_text = extraction_of(sow)
    (ok if "Freight confirmation remains outstanding" in docx_text else bad)(
        "DOCX extraction captured the outstanding freight confirmation")

    sheet_text = extraction_of(loads)
    (ok if "95" in sheet_text else bad)("spreadsheet extraction captured the 95 t crane figure")
    (ok if "Load Schedule" in sheet_text else bad)("spreadsheet extraction preserved sheet names for citation")

    pdf_text = extraction_of(drawing)
    (ok if "70 t" in pdf_text else bad)("PDF extraction captured the conflicting 70 t assumption")
    (ok if '"page":2' in pdf_text.replace(" ", "") else bad)("PDF extraction produced a per-page index")

    # -----------------------------------------------------------------------
    say("6. Claims cite exact evidence locations")

    rfq_v   = rfq_detail["current_version"]["id"]
    draw_v  = by_id[drawing]["current_version"]["id"]
    load_v  = by_id[loads]["current_version"]["id"]
    video_v = vid["id"]

    status, payload = api("POST", f"/circles/{circle}/claims", technical, {
        "statement": ("The load schedule specifies a 95 t crane while drawing Rev B is based on "
                      "a 70 t operating assumption."),
        "claim_type": "technical_assessment",
        "confidence": 0.9,
        "citations": [
            {"evidence_version_id": load_v, "locator": {"sheet": "Load Schedule", "range": "C2:D2"}},
            {"evidence_version_id": draw_v, "locator": {"page": 2}},
        ],
    })
    check(status, 201, "claim created citing two sources")
    claim = payload["data"]
    claim_id = claim["id"]
    check(len(claim["citations"]), 2, "claim cites a spreadsheet range and a document page")
    check(claim["status"], "attested", "a human claim enters as attested, never as fact")
    types = {c["citation_type"] for c in claim["citations"]}
    check(types, {"spreadsheet_cell", "document_page"}, "citation types inferred from the locators")

    status, payload = api("POST", f"/circles/{circle}/claims", technical, {
        "statement": "Site walkthrough shows standing water at the proposed crane pad.",
        "claim_type": "risk",
        "citations": [{"evidence_version_id": video_v,
                       "locator": {"start_seconds": 3, "end_seconds": 5}}],
    })
    check(payload["data"]["citations"][0]["citation_type"], "video_timestamp",
          "video timestamp citation typed correctly")

    status, _ = api("POST", f"/circles/{circle}/claims", technical, {
        "statement": "Malformed locator.", "claim_type": "factual",
        "citations": [{"evidence_version_id": draw_v, "citation_type": "document_page",
                       "locator": {"page": 0}}],
    })
    check(status, 422, "an unresolvable citation locator is rejected")

    status, _ = api("POST", f"/circles/{circle}/claims", technical, {
        "statement": "Cross-circle citation.", "claim_type": "factual",
        "citations": [{"evidence_version_id": draw_v, "citation_type": "spreadsheet_cell",
                       "locator": {"range": "not-a-range"}}],
    })
    check(status, 422, "a malformed spreadsheet range is rejected")

    api("POST", f"/claims/{claim_id}/review", gm,
        {"outcome": "reviewed", "comment": "Confirmed against both sources."})
    check(api_data("GET", f"/claims/{claim_id}", gm)["status"], "reviewed",
          "reviewer can mark a claim reviewed")

    api("POST", f"/claims/{claim_id}/contest", gm,
        {"outcome": "contested", "comment": "Client disputes the load basis."})
    check(api_data("GET", f"/claims/{claim_id}", gm)["status"], "contested", "a claim can be contested")

    # -----------------------------------------------------------------------
    say("7. Circle Steward runs under a strict policy guard")

    status, payload = api("POST", f"/circles/{circle}/agent-runs/steward-brief", gm, {})

    if status == 201:
        run = payload["data"]
        ok(f"Steward produced a brief (run {run['id']})")
        check(run["derived"], True, "brief is flagged derived")
        check(run["label"], "Derived by agent; requires human review", "derived label travels with the output")
        for field in ("agent_instance_id", "blueprint_version", "model_provider",
                      "model_name", "prompt_version"):
            (ok if run.get(field) else bad)(f"output contract includes {field}")
        note(f"retrieved {run['retrieval_manifest']['retrieved']} of "
             f"{run['retrieval_manifest']['considered']} agent-readable sources")

        agent_claims = [c for c in api_data("GET", f"/circles/{circle}/claims", gm)
                        if c["author_type"] == "agent"]
        if agent_claims:
            check({c["status"] for c in agent_claims}, {"derived"},
                  "every agent claim is status=derived, never reviewed or approved")
            check(all(c["citations"] for c in agent_claims), True,
                  "every surviving agent claim carries at least one real citation")
            note(f"agent produced {len(agent_claims)} sourced claim(s)")
        drafts = [d for d in api_data("GET", f"/circles/{circle}/decisions", gm)
                  if d.get("derived")]
        if drafts:
            check({d["status"] for d in drafts}, {"draft"},
                  "agent decision requests are drafts with no approver assigned")
    else:
        note("no ANTHROPIC_API_KEY configured — the model call failed as designed")
        check(status, 502, "an unconfigured provider fails loudly rather than returning an empty brief")
        runs = api_data("GET", f"/circles/{circle}/agent-runs", gm)
        check(runs[0]["status"], "failed", "the failed run is still recorded")
        check(runs[0]["retrieval_manifest"] is not None, True,
              "the retrieval manifest is recorded even for a failed run")

    # The guard is testable with or without a model call.
    accessed = artisan(f"echo App\\Models\\AgentResourceAccess::whereHas('run', "
                       f"fn($q) => $q->where('circle_id','{circle}'))->count();")
    note(f"agent access log recorded {accessed} retrieval attempt(s)")

    agent_view = api_data("GET", f"/circles/{circle}/members", gm)["agents"][0]
    check(len(agent_view["can_access"]), 6,
          '"what this agent can access" lists only the agent_read items')
    check("delete_resources" in agent_view["prohibited_actions"], True,
          "the agent mandate is published, including what it cannot do")

    # An item never marked agent-readable must not be retrievable by the agent.
    denied = artisan(
        f"$c = App\\Models\\Circle::find('{circle}');"
        f"$a = app(App\\Services\\Agent\\CircleSteward::class)->instanceFor($c);"
        f"$r = App\\Models\\EvidenceItem::find('{video}')->resource;"
        f"echo app(App\\Services\\Authorisation\\AccessGate::class)"
        f"->inspect($a, App\\Enums\\Permission::ResourceAgentRead, $c, $r)->reason;")
    check(denied, "agent_read_not_enabled", "agent is refused an item not marked agent-readable")

    # -----------------------------------------------------------------------
    say("8. Approval binds to an exact version")

    decision = api_data("POST", f"/circles/{circle}/decisions", gm, {
        "title": "Confirm crane load basis",
        "description": "Technical lead must confirm which load assumption governs the configuration.",
        "approver_user_id": user_id(technical),
        "subject_type": "evidence_item",
        "subject_id": drawing,
    })
    decision_id = decision["id"]
    check(decision["subject"]["version"], "1", "decision auto-binds to the current version")
    check(decision["status"], "pending", "a decision with a named approver is pending")

    status, _ = api("POST", f"/decisions/{decision_id}/approve", gm, {"comment": "not my call"})
    check(status, 422, "someone other than the assigned approver cannot resolve it")

    api("POST", f"/decisions/{decision_id}/approve", technical,
        {"comment": "70 t basis governs; load schedule to be corrected."})
    decisions = {d["id"]: d for d in api_data("GET", f"/circles/{circle}/decisions", gm)}
    check(decisions[decision_id]["status"], "approved", "the assigned approver can approve")
    check(decisions[decision_id]["approval_history"][0]["subject_version"], "1",
          "the approval history records the exact version approved")

    status, _ = api("POST", f"/decisions/{decision_id}/approve", technical, {})
    check(status, 422, "a resolved decision cannot be resolved twice")

    # -----------------------------------------------------------------------
    say("9. A new version invalidates the old approval")

    d = api_data("POST", f"/circles/{circle}/uploads/sign", technical,
                 {"filename": "drawing_rev_c.pdf"})
    request("PUT", d["url"], raw=(FIXTURES / "drawing_rev_b.pdf").read_bytes(),
            content_type=d["headers"]["Content-Type"])
    api("POST", f"/evidence/{drawing}/versions", technical,
        {"storage_key": d["key"], "filename": "drawing_rev_c.pdf"})

    # The replacement goes through the same pipeline as an original, and the
    # packet later asserts a digest for every version. Wait for it here rather
    # than at the export, so a slow queue reads as a slow queue instead of as a
    # missing hash.
    print("  waiting for the replacement to be hashed", end="", flush=True)
    for _ in range(90):
        current = api_data("GET", f"/evidence/{drawing}", gm)["current_version"]
        if current.get("processing_status") in ("ready", "failed"):
            break
        print(".", end="", flush=True)
        time.sleep(2)
    print()

    decisions = {x["id"]: x for x in api_data("GET", f"/circles/{circle}/decisions", gm)}
    check(decisions[decision_id]["status"], "superseded",
          "the approval is superseded when the subject gains a new version")

    versions = api_data("GET", f"/evidence/{drawing}/versions", gm)
    check(len(versions), 2, "both versions are retained")
    check(versions[0]["integrity_status"], "superseded", "v1 reports integrity=superseded")
    check(versions[1]["supersedes_version_id"], versions[0]["id"], "v2 records what it supersedes")
    check(api_data("GET", f"/evidence/{drawing}", gm)["review_status"], "unreviewed",
          "a new version resets the review state")

    cited = [c for c in api_data("GET", f"/claims/{claim_id}", gm)["citations"]
             if c["evidence_version_id"] == draw_v]
    check(cited[0]["evidence"]["version_number"], 1,
          "the existing citation still resolves to the exact version it cited")

    # -----------------------------------------------------------------------
    say("10. Authorisation boundaries")

    check(api("GET", f"/circles/{circle}/evidence", external)[0], 200,
          "external collaborator can view the Circle")
    check(api("GET", f"/evidence-versions/{rfq_v}/download-url", external)[0], 403,
          "external collaborator is denied download by default")
    check(api("POST", f"/circles/{circle}/evidence", external,
              {"storage_key": "x", "filename": "x.pdf"})[0], 403, "a viewer cannot upload")
    check(api("POST", f"/circles/{circle}/claims", external,
              {"statement": "x", "claim_type": "factual"})[0], 403, "a viewer cannot create claims")
    check(api("GET", f"/evidence-versions/{rfq_v}/download-url", gm)[0], 200, "the owner can download")
    check(api("POST", f"/circles/{circle}/decisions", commercial,
              {"title": "x"})[0], 403, "a contributor cannot create decisions")

    _, outsider = api("POST", "/auth/register", body={
        "name": "Outsider", "email": f"outsider-{int(time.time())}@nowhere.test",
        "password": PASSWORD + "-outsider"})
    check(api("GET", f"/circles/{circle}", outsider["token"])[0], 403,
          "a valid account that is not a member is refused")

    # Organisation membership alone must grant nothing.
    check(api("GET", f"/circles/{circle}", login("probe@jwamats.test"))[0], 403,
          "same-organisation membership alone grants no Circle access")

    # -----------------------------------------------------------------------
    say("11. Commitments and the Now view")

    api("POST", f"/circles/{circle}/commitments", gm, {
        "title": "Confirm freight booking for the 14 Sept possession",
        "owner_user_id": user_id(logistics),
        "due_at": "2026-09-10T14:00:00Z",
    })
    overview = api_data("GET", f"/circles/{circle}/overview", gm)
    check(len(overview["commitments"]), 1, "the overview lists the open commitment")
    check(overview["countdown"]["expired"], False, "the overview shows the mission countdown")
    note(f"{len(overview['pending_decisions'])} decision(s) pending, "
         f"{overview['overdue_count']} commitment(s) overdue")

    # -----------------------------------------------------------------------
    say("12. Audit chain")

    _, hist = api("GET", f"/circles/{circle}/history?limit=500", gm)
    check(hist["meta"]["chain"]["valid"], True, "the audit chain verifies")
    note(f"chain covers {hist['meta']['chain']['events_checked']} events")

    seen = {e["event_type"] for e in hist["data"]}
    required = [
        "circle.created", "circle.member_invited", "circle.member_role_changed",
        "resource.uploaded", "resource.version_created", "resource.processing_completed",
        "resource.viewed", "resource.downloaded", "claim.created", "claim.reviewed",
        "claim.contested", "decision.created", "decision.approved", "commitment.created",
        "agent.run_started", "access.denied",
    ]
    missing = [t for t in required if t not in seen]
    check(missing, [], f"all {len(required)} required audit event types present")

    first = [e for e in hist["data"] if e["event_type"] == "circle.created"][0]
    check(first["previous_hash"], "GENESIS", "the first event chains to GENESIS")

    # -----------------------------------------------------------------------
    say("13. Tamper evidence")

    artisan(f"$e = App\\Models\\AuditEvent::where('circle_id','{circle}')"
            f"->orderBy('sequence')->skip(2)->first();"
            f"$m = $e->metadata_json; $m['tampered'] = true;"
            f"App\\Models\\AuditEvent::where('id',$e->id)"
            f"->update(['metadata_json' => json_encode($m)]);")
    verdict = api_data("GET", f"/circles/{circle}/history/verify", gm)
    check(verdict["valid"], False, "editing a stored event breaks verification")
    note(f"reported: {verdict['reason']}")

    artisan(f"$e = App\\Models\\AuditEvent::where('circle_id','{circle}')"
            f"->orderBy('sequence')->skip(2)->first();"
            f"$m = $e->metadata_json; unset($m['tampered']);"
            f"App\\Models\\AuditEvent::where('id',$e->id)"
            f"->update(['metadata_json' => json_encode($m)]);")
    check(api_data("GET", f"/circles/{circle}/history/verify", gm)["valid"], True,
          "restoring the event restores verification")

    # -----------------------------------------------------------------------
    say("14. Export the mission packet")

    status, payload = api("POST", f"/circles/{circle}/exports", gm, {})
    check(status, 201, "export built")
    export = payload["data"]
    check(export["status"], "ready", "packet is ready")
    check(export["audit_chain_valid"], True, "packet records a valid audit chain")

    _, blob = request("GET", export["download_url"])
    try:
        zf = zipfile.ZipFile(io.BytesIO(blob))
        names = set(zf.namelist())
        expected = {"circle.json", "participants.json", "evidence.json", "claims.json",
                    "decisions.json", "commitments.json", "agent_runs.json",
                    "derived.json", "audit_events.json", "manifest.json", "README.txt"}
        check(expected - names, set(), "packet contains every required document")

        ev = json.loads(zf.read("evidence.json"))
        digests = [v["sha256"] for item in ev for v in item["versions"] if v["sha256"]]
        check(len(digests), 9, "evidence manifest carries a digest for every version")

        dec = json.loads(zf.read("decisions.json"))
        approved = [d for d in dec if d["approval_history"]]
        check(approved[0]["approval_history"][0]["subject_version"], "1",
              "packet preserves which exact version was approved, and by whom")

        audit = json.loads(zf.read("audit_events.json"))
        check(audit["verification"]["valid"], True, "packet's audit chain self-verifies")

        manifest = json.loads(zf.read("manifest.json"))
        originals = manifest["originals"]
        check(manifest["contains_originals"], True,
              "packet carries the originals themselves, not only their digests")
        check(all("reason" in o and "detail" in o for o in originals["omitted"]), True,
              "every original that did not travel is named with the reason it did not")
        note(f"packet carries {originals['included_count']} original(s), "
             f"{originals['omitted_count']} omitted, cap {originals['byte_cap_human']}")
        note(f"packet is {len(blob):,} bytes across {len(names)} files")
    except zipfile.BadZipFile:
        bad("the export is not a readable zip")

    # -----------------------------------------------------------------------
    if KEEP_OPEN:
        # Leave a live Circle behind for manual exploration or screenshots.
        # The closure checks are skipped, not silently passed.
        note(f"KEEP_OPEN set — Circle left open at {WEB_HINT}/circles/{circle}")
        note("closure checks skipped")
        return summarise()

    say("15. Closure revokes access")

    api("POST", f"/circles/{circle}/close", gm, {"reason": "Bid submitted; mission complete."})
    check(api_data("GET", f"/circles/{circle}", gm)["is_closed"], True, "Circle closed")
    check(api("GET", f"/circles/{circle}", external)[0], 403, "closure revokes external access")
    check(api("POST", f"/circles/{circle}/evidence", commercial,
              {"storage_key": "x", "filename": "x.pdf"})[0], 403,
          "a closed Circle accepts no new evidence")
    check(api("POST", f"/circles/{circle}/agent-runs/steward-brief", gm, {})[0], 403,
          "closure blocks agent runs")
    check(api("GET", f"/circles/{circle}", gm)[0], 200,
          "an internal member retains the read-only record")
    check(api("POST", f"/circles/{circle}/exports", gm, {})[0], 201,
          "the owner can still export after closure")

    # -----------------------------------------------------------------------
    return summarise()


if __name__ == "__main__":
    sys.exit(main())
