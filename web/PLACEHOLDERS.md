# Before this site goes live

The public site and the seven legal documents are complete in structure and
wording. What is deliberately *not* complete is every fact that only you know —
the legal entity, the ABN, the hosting region, the email addresses, the notice
periods. Those are left as visible placeholders rather than filled with
something plausible, because a Terms of Service naming the wrong company or a
Privacy Policy describing retention you do not practise is worse than one that
is obviously unfinished.

Two safety nets are wired in and will remove themselves once you have filled
things in:

- **`robots.txt` disallows the entire site** while `SITE.origin` is a
  placeholder, and no canonical or Open Graph URL is emitted. This makes it
  impossible to accidentally get a draft indexed under the wrong hostname.
- **Every legal page carries a red "Not ready to publish" banner**, and is
  served `noindex`, while `SITE.legal.entity`, `SITE.legal.address` or
  `SITE.email.privacy` still contain brackets. The footer carries a matching
  warning on every page.

## How to find every remaining placeholder

```bash
# Anything bracketed in the site config
grep -n '\[' src/lib/site.ts

# Anything bracketed in the legal documents
grep -rn 'class="todo"' src/pages/legal/

# The security contact file
cat public/.well-known/security.txt
```

---

## 1. Blocking — the site cannot be published without these

### `src/lib/site.ts`

| Field | What it needs |
| --- | --- |
| `origin` | The real https origin, no trailing slash. Unlocks canonical URLs, Open Graph, the sitemap and indexing. |
| `legal.entity` | The company that contracts with customers. Appears in every legal document and the footer. |
| `legal.abn` | Australian Business Number. |
| `legal.address` | Registered office. APP 1.4 requires a privacy policy to identify the entity. |
| `legal.jurisdiction` | Currently **New South Wales**. Confirm — it should be where the entity is actually established. |
| `legal.dataRegion` | Where production data really sits. This is quoted on `/security` and in the Privacy Policy and DPA; it must be true. |
| `email.*` | Six addresses: general, sales, privacy, legal, security, support. They can all forward to one inbox, but they must all resolve. |

### `public/.well-known/security.txt`

Replace the contact address, the policy URL and the canonical URL, and set an
`Expires` date no more than twelve months out. **Diarise renewing it** — most
researchers and scanners treat an expired `security.txt` as no `security.txt`
at all.

---

## 2. Commercial and operational values in the legal documents

Every one of these is marked in red on the rendered page. The bracketed value is
a common default, not a recommendation — decide each one deliberately.

### `legal/terms.md`

- Payment terms (`30` days), interest on overdue amounts, price-change notice
  (`60` days), renewal and non-renewal notice (`30` days), cure period
  (`30` days), pilot termination notice (`14` days).
- **Liability cap**: currently fees paid in the prior `12` months, and
  `AUD $100` for a free pilot. This is the clause enterprise customers will try
  to move first; decide your floor before the first negotiation.
- Export window and deletion timelines (`30` days production, `60` days
  backups). These must match what your deletion process actually does — the
  same numbers appear in the Privacy Policy and the DPA and must agree.
- Dispute escalation meeting (`14` days).

### `legal/privacy.md`

- **Privacy Act status.** If the entity's annual turnover is $3 million or less
  it is a small business operator and not bound by the APPs by default. If so,
  elect under s 6EA to be treated as an organisation and say so. "We are exempt"
  does not survive enterprise procurement.
- Retention periods for authentication records, support correspondence,
  enquiries and billing.
- The overseas countries listed in clause 6 — must match the sub-processor list.
- An Article 27 representative, if you offer the Service to EEA or UK
  individuals without an establishment there.

### `legal/dpa.md`

- The governing law chosen for the Standard Contractual Clauses (currently
  Ireland). **Attach the SCCs in full, with the module and annexes completed** —
  incorporation by reference alone is not enough to rely on them for a transfer.
- Backup frequency, retention and restore-test cadence in Annex 2. State what is
  actually in place, not an aspiration; this annex is a contractual commitment.
- Penetration testing cadence and staff access review cadence.
- The third-party security report reference, once one exists.

### `legal/subprocessors.md`

**Reconcile this page against the running infrastructure and the environment
configuration before publishing, not against this file.** An omission here is a
breach of the DPA.

- Cloud infrastructure provider and region; CDN, if used.
- Transcription provider, if configured.
- Transactional email, error monitoring and payment providers.
- The retention tier in your executed model provider agreement. Buyers ask this
  first, and "zero retention" must be a term you actually hold.

### `legal/responsible-disclosure.md`

- A PGP key and fingerprint, referenced from `security.txt`.
- Bug bounty scope and rewards, if one is introduced.
- The acknowledgements list. An empty list is honest; a fabricated one is not.

### `legal/cookies.md` and `legal/acceptable-use.md`

- The privacy and legal email addresses.
- **Re-check the Cookie Notice whenever any script is added** to the site or the
  app. It currently states that there is no analytics, no advertising and no
  third-party cookies, which is true of the build as it stands. Adding a single
  analytics tag makes that statement false and triggers the consent obligations
  in clause 4.

---

## 3. Legal review

**None of these documents has been reviewed by a lawyer.** Each one opens by
saying so, and that notice should be deleted only by the person who reviewed it.
Priority order for a first review:

1. **Terms clause 12 (liability)** and **clause 9 (agents)**. Clause 9 allocates
   responsibility for automated action between the platform, the acting party
   and the approving person. It has no settled market standard, and it is the
   clause that matters most if an agent ever does something expensive.
2. **The DPA and its SCCs**, if you will have EEA or UK customers.
3. **Privacy Policy clause 1** — the s 6EA question above.
4. **Unfair contract terms.** Since November 2023 the regime applies to small
   business contracts with civil penalties attached. The unilateral-variation
   clause (Terms 17) and the liability cap are the usual exposure; both have
   been drafted with that in mind but neither has been tested.

---

## 4. Content that is missing on purpose

The site has **no customer logos, no testimonials and no case studies**, because
there are none. Those are the fastest possible way to lose a buyer who checks.
When you have real ones:

- A logo strip belongs directly under the hero on `src/pages/index.astro`.
- A testimonial with a specific number in it — "the handover pack that used to
  take eleven days" — belongs beside the final call to action. A quote saying
  the product is great is worth nothing; get the number.
- `SITE.stage` in `site.ts` carries the "in scoped pilots, not generally
  available" line that appears in the hero and the footer. Update it as that
  stops being true.

The `/product` page ends with a list of what is **not built yet**, taken from
the specification's own amendment. Keep it current as those items ship. It is
one of the more persuasive things on the site, and it stops being persuasive the
moment it is out of date.

---

## 5. Regenerating the Open Graph card

`public/og.png` is generated from markup so it stays in step with the design
system rather than drifting from it:

```bash
node og.mjs
```

Re-run it if the hero headline or the palette changes. It needs a network
connection for the webfont; without one it falls back to a local serif.
