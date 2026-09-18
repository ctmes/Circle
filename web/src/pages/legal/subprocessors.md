---
layout: ../../layouts/Legal.astro
title: Sub-processors
description: Every third party that can process customer content in Circle, what each one does, where it is located, and how changes to this list are notified.
path: /legal/subprocessors
docNo: LEG-04
summary: This is the complete list of third parties that can touch customer content. We give 30 days' notice before adding or replacing one, and customers may object on data protection grounds under clause 5 of the Data Processing Addendum.
---

<p><strong>This list is a draft and must be reconciled against the deployed system before publishing.</strong> A sub-processor list that omits a provider is a breach of the Data Processing Addendum, so check it against the running infrastructure and the environment configuration, not against this page.</p>

## 1. How to read this list

A **sub-processor** is a third party we engage that processes personal data on our behalf in providing the Service. Each is engaged under a written contract imposing obligations no less protective than our [Data Processing Addendum](/legal/dpa), and we remain fully liable to our customers for what they do.

Vendors that never touch customer content — accounting software, our own email, the tools we write code in — are not sub-processors and are not listed.

Two entries below are **conditional**: they process content only where a customer has switched the corresponding feature on. If you never enable an agent, no model provider ever sees anything of yours.

## 2. Infrastructure

<div class="table-rail">

| Sub-processor | Purpose | Data processed | Location |
| --- | --- | --- | --- |
| <span class="todo">[Cloud infrastructure provider]</span> | Compute, managed database, object storage for evidence, encrypted backups | All customer content and account data, at rest and in transit | <span class="todo">[ap-southeast-2 (Sydney), Australia]</span> |
| <span class="todo">[CDN / edge provider, if used]</span> | Serving the public website and static assets | Website request metadata, IP addresses. No customer content | <span class="todo">[Global edge; state the regions]</span> |

</div>

The database, the object store holding evidence originals, the queue and the backups all run within the infrastructure provider above. Object storage is never public: files are reachable only through short-lived signed URLs issued after an access check.

## 3. Model and processing providers — conditional

<div class="table-rail">

| Sub-processor | Purpose | Data processed | Location | Condition |
| --- | --- | --- | --- | --- |
| Anthropic PBC | The model behind agent features — drafted claims, summaries, decision requests | Only extracted text from evidence items explicitly marked agent-readable in a Circle, plus the Circle's structural context. Never the original binary, never an item the access gate refused | United States | Only where the customer enables an agent |
| <span class="todo">[Transcription provider]</span> | Speech-to-text for audio and video evidence | Audio extracted from evidence items | <span class="todo">[State the region]</span> | Only where transcription is configured. With no provider configured, transcripts are recorded as skipped rather than left pending |

</div>

**Model provider terms.** Content sent to a model provider is not used to train or improve any model. Our contract with each provider says so, and zero-retention or minimal-retention terms are used where the provider offers them. <span class="todo">[Confirm the specific retention tier in the executed agreement and state it here — buyers ask this question first.]</span>

**What the model actually receives.** Every candidate evidence item is put through the access gate individually before a run, and the retrieval manifest is recorded before the model is called. Items the gate refuses are logged as refusals and are not sent. The model returns structured output and cannot call anything; its citations are then validated against that manifest, and output citing evidence it was never shown is discarded.

## 4. Operational providers

<div class="table-rail">

| Sub-processor | Purpose | Data processed | Location |
| --- | --- | --- | --- |
| <span class="todo">[Transactional email provider]</span> | Invitations, password resets, security and service notices | Name, email address, message content. No customer content | <span class="todo">[State the region]</span> |
| <span class="todo">[Error monitoring provider, if used]</span> | Diagnosing faults | Stack traces, request metadata, user identifiers. Configured to scrub content | <span class="todo">[State the region]</span> |
| <span class="todo">[Payment / invoicing provider, if used]</span> | Invoicing and payment | Billing contact and payment details. No customer content | <span class="todo">[State the region]</span> |

</div>

## 5. Website analytics

Any analytics used on the public website is described in the [Cookie Notice](/legal/cookies). It runs on the marketing site only. It is not present in the application, and it never has access to customer content.

## 6. Changes to this list

We give at least **30 days' notice** before adding or replacing a sub-processor, by updating this page and emailing the address each customer nominates for the purpose.

To be notified, send the address you want used to <span class="todo">[privacy@circle.example]</span> with the subject "sub-processor notifications".

If you have a reasonable objection on data protection grounds, tell us within the notice period. Clause 5 of the [Data Processing Addendum](/legal/dpa) sets out what happens next, including your right to terminate the affected part of the Service without penalty if no alternative can be found.
