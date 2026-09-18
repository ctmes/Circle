---
layout: ../../layouts/Legal.astro
title: Responsible Disclosure
description: How to report a security vulnerability in Circle, what is in scope, what we commit to, and our safe-harbour undertaking for good-faith research.
path: /legal/responsible-disclosure
docNo: LEG-07
summary: If you have found a security problem in Circle, we want to hear about it and we will not take action against you for finding it in good faith. This page sets out how to report, what is in and out of scope, and what we commit to in return.
---

## 1. How to report

Email <span class="todo">[security@circle.example]</span>. <span class="todo">[Publish a PGP key and its fingerprint here, and reference it from /.well-known/security.txt.]</span>

Please include:

- what you found, and where — a URL, an endpoint, a parameter;
- the steps to reproduce it, ideally as a short sequence or a script;
- what an attacker could achieve with it;
- any accounts, IPs or timestamps you used, so we can find your activity in our logs and separate it from a real attack.

Report in whatever language you are comfortable writing; we will manage.

## 2. What we commit to

- **Acknowledgement within 2 business days.**
- **An initial assessment within 5 business days**, telling you whether we have reproduced it and our provisional severity.
- **Progress updates at least every 10 business days** until it is resolved.
- **Credit**, if you want it, in our disclosure acknowledgements. Tell us the name or handle you want used.
- **No legal action** against you for research that follows this policy. See clause 5.

We do not currently run a paid bug bounty. <span class="todo">[If one is introduced, state the scope, the reward table and the platform here.]</span>

## 3. In scope

- The Circle application and its API.
- The public website.
- The infrastructure we operate that serves them.

We are particularly interested in anything that crosses a boundary the product exists to hold:

- **Cross-tenant or cross-Circle access** — reading or writing anything belonging to another organisation.
- **Bypassing the access gate** — obtaining a resource the policy checks should have refused, or reaching an evidence original without a valid signed URL.
- **Party isolation failures** — reading a party-scoped comment thread from outside that party, including as the convener.
- **Agent boundary failures** — causing an agent to retrieve an item it was refused, to act beyond its declared execution mode or tool list, to act on behalf of a party that did not authorise it, or to have a side-effecting action proceed without a human approval.
- **Audit integrity failures** — altering, deleting or reordering audit events, or producing an export packet that verifies but was not produced by the Service.
- Authentication and session handling, privilege escalation, injection, SSRF, and remote code execution.

## 4. Out of scope

- Denial of service, load testing, and anything that degrades the Service for other people.
- Social engineering of our staff, our customers or our suppliers, and physical attacks.
- Findings from automated scanners with no demonstrated impact.
- Missing security headers, cookie flags, TLS configuration preferences, or SPF/DKIM/DMARC observations, without a working exploit.
- Rate limiting on unauthenticated endpoints, absent a demonstrated impact.
- Vulnerabilities in a third-party service we use — report those to that provider; tell us as well, and we will follow up.
- Anything requiring a rooted or compromised device, or a browser or plugin that is no longer supported.
- Self-XSS, clickjacking on pages with no sensitive action, and reports that a signed-in user can see their own data.

## 5. Safe harbour

If you make a good-faith effort to follow this policy, we will treat your research as authorised conduct. We will not initiate or support legal action against you under the *Criminal Code Act 1995* (Cth), computer misuse law, contract, or the anti-circumvention provisions of copyright law, and we will say so if a third party raises it.

To stay within that undertaking:

- Use only accounts you own or have written permission to test. Do not access, modify, download or retain another person's or another organisation's data.
- If you encounter data that is not yours, stop, do not save it, and tell us what you saw so we can assess the exposure.
- Do not degrade the Service, and do not run destructive or high-volume tests against production.
- Do not use a finding for any purpose other than demonstrating it to us.
- Give us a reasonable time to fix it before telling anyone else — see clause 6.

If you are unsure whether something is in scope or whether a test is acceptable, ask us first.

## 6. Coordinated disclosure

We ask for **90 days** from your report before public disclosure, or until a fix is deployed and affected customers are notified, whichever comes first. If we need longer we will explain why and agree a date with you. If we cannot fix something, we will tell you that too, and why.

We will not ask you to stay quiet indefinitely, and we will not require an NDA as a condition of accepting a report.

## 7. Customer notification

Where a vulnerability affected customer data, we notify the affected customers under clause 7 of the [Data Processing Addendum](/legal/dpa) and, where the Notifiable Data Breaches scheme requires it, the Office of the Australian Information Commissioner.

## 8. Acknowledgements

<span class="todo">[List the people who have reported valid findings, with their permission. An empty list is honest; a fabricated one is not.]</span>
