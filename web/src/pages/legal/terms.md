---
layout: ../../layouts/Legal.astro
title: Terms of Service
description: The agreement between Circle and the organisations that use it — data ownership, agent authority, availability, liability and termination, under the law of New South Wales.
path: /legal/terms
docNo: LEG-01
summary: These Terms govern your organisation's use of Circle. They are written to be read — the clauses that matter most to you are data ownership (clause 7), what an agent is permitted to do (clause 9), and what happens to your content when a Circle closes (clause 14).
---

<p><strong>These Terms are a draft prepared for review. They have not been settled by a lawyer, and they must be before they are relied on.</strong> Clauses marked in red contain placeholders.</p>

## 1. Who this agreement is between

This agreement is between <span class="todo">[Legal entity name] Pty Ltd</span> <span class="todo">[ABN 00 000 000 000]</span> of <span class="todo">[registered office address]</span> (**"we"**, **"us"**, **"Circle"**) and the organisation that accepts these Terms (**"you"**, **"Customer"**).

Circle is a business-to-business service. It is not offered to individuals for personal, domestic or household use, and accounts are provisioned to an organisation rather than opened by self-service.

By accepting these Terms — by clicking to accept, by signing an order document that incorporates them, or by using the Service — you confirm that you are at least 18 years old and that you are authorised to bind the organisation you name. If you do not have that authority, do not accept.

Where you have signed a separate written agreement or order form with us, that document prevails over these Terms to the extent of any inconsistency.

## 2. What the Service is

Circle is a hosted platform for creating a **Circle**: a bounded, time-limited workspace in which two or more organisations collaborate on a single defined outcome. Within a Circle, the Service allows you to:

- admit organisations as **parties**, and people as members holding a defined Circle role;
- preserve uploaded files as immutable **evidence** with a content hash, an uploader, a version lineage and an access rule;
- record **claims** that cite exact locations within evidence;
- request and record **decisions**, **approvals** and **commitments**;
- structure work as a **goal tree** with responsible parties and recorded schedule changes;
- run **agents** within a declared mandate; and
- close the Circle and export an evidence and decision packet.

**What the Service is not.** Circle is not a system of record for your accounts, a legal or professional advisory service, a document authentication or forensic service, and not an independently anchored ledger. The audit chain within the Service is tamper-evidence for the Service's own event stream. It demonstrates that the Service's record has not been altered after the fact; it does not prove that the contents of any file are true, authentic or complete, and it is not a blockchain or a notarisation.

We may change, add to or remove features of the Service. Where a change materially reduces functionality you rely on, clause 17 applies.

## 3. Accounts, members and credentials

Organisations and their initial administrators are provisioned by us. You are responsible for:

- the accuracy of the organisation and party details you supply;
- who you invite into a Circle, and the Circle role you give them;
- the acts and omissions of your members as if they were your own; and
- keeping credentials confidential, and telling us promptly at <span class="todo">[security@circle.example]</span> if you believe a credential has been compromised.

Credentials are personal to a member. Accounts must not be shared between people, because attribution is the point of the product: a record that says two people used one login is worth nothing in a dispute.

## 4. Acceptable use

Your use of the Service is subject to the [Acceptable Use Policy](/legal/acceptable-use), which forms part of these Terms. In summary, you must not use the Service to break the law, to infringe anyone's rights, to upload malware, to attack or reverse engineer the Service, to circumvent the access controls that separate parties within a Circle, or to benchmark the Service for a competing product without our written consent.

We may suspend access — to a member, a Circle or an account — where we reasonably believe continued access poses a security risk, a legal risk, or a risk to other customers. We will tell you why, and we will restore access as soon as the cause is resolved.

## 5. Fees, invoicing and GST

Fees, the billing period and the term are set out in your order document. Unless it says otherwise:

- invoices are payable within <span class="todo">[30]</span> days of the invoice date;
- fees are stated exclusive of GST, and GST is payable in addition at the applicable rate on any taxable supply made under this agreement;
- we may charge interest on overdue amounts at <span class="todo">[the RBA cash rate plus 2%]</span> per annum, calculated daily; and
- fees are non-refundable except where these Terms or a non-excludable law require a refund.

We will give you at least <span class="todo">[60]</span> days' written notice before a price change takes effect, and a price change does not apply during a term you have already paid for. If you do not accept a price change, you may terminate at the end of the current term.

## 6. Pilots and evaluations

Where the Service is provided as a **pilot** or an evaluation, it is provided for the scope, purpose and period set out in the pilot document. During a pilot:

- the Service is provided **as is**, and clauses 11 and 12 apply in full;
- either party may end the pilot on <span class="todo">[14]</span> days' written notice; and
- on the end of the pilot you may export your Customer Data and any closed Circle's packet, and clause 14 governs what happens to it afterwards.

Ending a pilot does not oblige you to buy anything.

## 7. Your data is yours

**We claim no ownership of Customer Data.** "Customer Data" means everything you or your members put into the Service or generate through it: evidence and its originals, claims, decisions, approvals, commitments, goals, comments, party and membership records, audit events, and exported packets.

You grant us a non-exclusive, worldwide, royalty-free licence to host, copy, transmit, display, index, extract text from and otherwise process Customer Data **solely** to the extent necessary to:

- provide, maintain and secure the Service to you;
- perform the processing you instruct — including running an agent you have configured against evidence you have marked agent-readable; and
- comply with law.

That licence ends when the relevant Customer Data is deleted under clause 14.

**We do not use Customer Data to train models.** We do not use Customer Data to develop, train, fine-tune or improve any machine learning model, ours or a third party's, and we contract with our sub-processors on the same basis. We do not sell Customer Data and we do not disclose it for advertising.

**Aggregated data.** We may create statistics about how the Service is used — volumes, performance, error rates, feature usage — provided they are aggregated across customers and contain nothing that identifies you, your members, your counterparties or the content of any Circle. We own those statistics and may use them to operate and improve the Service.

**Between parties.** Where a Circle spans several organisations, each party remains responsible for its own Customer Data and for what it chooses to share. We are not the arbiter of any dispute between parties about who may see, use or keep what. We give effect to the access rules configured in the Service; we do not adjudicate them.

## 8. Evidence, the record, and what we do not warrant

The Service preserves originals, records hashes, and maintains a hash-chained audit log so that alteration of the Service's own record is detectable.

We warrant that we will operate those mechanisms as described in the documentation. **We do not warrant** that any evidence is authentic, accurate, complete, lawfully obtained or free of third-party rights; that any extracted text, transcript or optical character recognition output is correct; or that any claim, decision or agent output is true.

Machine-derived content — extraction, transcription, summaries, drafted claims — is labelled as derived in the Service. It is an interpretation, not evidence, and must not be relied on as a substitute for the original.

## 9. Agents and automated action

The Service can run software agents. The following limits are enforced by the Service and are also contractual terms.

**Authority.** An agent acts on behalf of a named party, and that party bears responsibility for what the agent does as if a person of that party had done it. An agent does not act on behalf of "the Circle", and it does not act on our behalf.

**Approval.** An agent may propose an action. An action with a side effect requires approval by a natural person holding authority for the party that bears it. An agent cannot approve any action, including one it proposed. You must not configure, script or otherwise arrange for approvals to be given without a human decision.

**Mandate.** Each agent has an execution mode and a declared list of tools classified by consequence. You are responsible for the mandate you declare, for the tools you enable, and for the credentials you connect. Where you connect your own credentials, you remain responsible for the acts performed with them, and we never hold your raw secret.

**Outputs.** Agent output is a draft. It is not advice, not a decision, and not a warranty by us of anything. You are responsible for reviewing agent output before acting on it, and for any consequence of acting on it.

**Third-party models.** Agent features are provided using third-party model providers listed in the [sub-processor list](/legal/subprocessors). Their availability, latency and behaviour are outside our control.

## 10. Our intellectual property

We own the Service, its software, interfaces, documentation, and the Circle name and marks. These Terms grant you a limited, non-exclusive, non-transferable, revocable right to access and use the Service during the term, for your internal business purposes, in accordance with these Terms.

You must not copy, modify, translate, decompile or create derivative works of the Service, except to the extent that restriction is prohibited by law; rent, resell or provide the Service to a third party as a service bureau; or remove any proprietary notice.

If you give us feedback or suggestions, we may use them without restriction and without owing you anything. Feedback is not Customer Data and must not contain confidential information you would rather we did not have.

## 11. Availability and support

We will use reasonable commercial efforts to keep the Service available, and we will give reasonable advance notice of planned maintenance where we can. Support is provided by email at <span class="todo">[support@circle.example]</span> during Australian business hours.

**Unless your order document contains a written service level agreement, no uptime commitment is made and no service credits are payable.** The Service depends on third-party infrastructure and model providers, and we are not liable for their outages.

## 12. Limitation of liability

**Australian Consumer Law.** Nothing in these Terms excludes, restricts or modifies any guarantee, right or remedy that cannot be excluded under the *Competition and Consumer Act 2010* (Cth), including the Australian Consumer Law, or any other law that cannot lawfully be excluded. Where the Australian Consumer Law applies and the supply is not of a kind ordinarily acquired for personal, domestic or household use, our liability for a failure to comply with a consumer guarantee is limited, at our option, to resupplying the services or paying the cost of having them resupplied.

Subject to the paragraph above:

- **Neither party is liable** to the other for loss of profit, loss of revenue, loss of anticipated savings, loss of goodwill, loss of opportunity, business interruption, or any indirect or consequential loss, however caused, even if the loss was foreseeable.
- **Our total aggregate liability** arising out of or in connection with this agreement, whether in contract, tort (including negligence), statute or otherwise, is limited to the fees you paid us in the <span class="todo">[twelve (12)]</span> months immediately before the event giving rise to the liability. For a pilot supplied at no charge, our total aggregate liability is limited to <span class="todo">[AUD $100]</span>.
- **We are not liable** for loss arising from the accuracy or inaccuracy of Customer Data or agent output, from a decision you or a counterparty made using the Service, from a dispute between parties to a Circle, or from your failure to keep credentials secure.

Neither party's liability is limited for death or personal injury caused by its negligence, for fraud, or for a party's breach of clause 4 or clause 13.

## 13. Confidentiality

Each party must keep the other's confidential information confidential, use it only for this agreement, and protect it with at least reasonable care. Customer Data is your confidential information. This obligation does not apply to information that is public through no breach, was already known without obligation, is independently developed, or must be disclosed by law — and where disclosure is compelled, the disclosing party must, if lawful, give the other party notice and a reasonable chance to object.

## 14. Term, closure, and what happens to your data

**Term.** This agreement runs for the term in your order document and, unless that document says otherwise, renews for successive periods of the same length unless either party gives written notice of non-renewal at least <span class="todo">[30]</span> days before the end of the current period.

**Termination for cause.** Either party may terminate immediately by written notice if the other materially breaches this agreement and does not remedy the breach within <span class="todo">[30]</span> days of being told about it, or becomes insolvent.

**Closing a Circle.** Closing a Circle is a product action, not a termination. On closure, the Circle becomes read-only, all agents in it are disabled, and the export packet becomes available to the convener and to each party.

**Export.** You may export your Customer Data at any time during the term, and for <span class="todo">[30]</span> days after termination, through the Service's export functions.

**Deletion.** After that export window, we will delete Customer Data from our production systems within <span class="todo">[30]</span> days, and from encrypted backups within a further <span class="todo">[60]</span> days as those backups expire on their ordinary cycle. We may retain Customer Data for longer where a law requires it, or where it is subject to a legal hold, and in that case we will keep it only for that purpose and delete it when the requirement ends.

**Survival.** Clauses 7 (as to ownership), 10, 12, 13, 14 and 16 survive termination.

## 15. Indemnity

You indemnify us against any claim, loss, liability or reasonable cost we suffer arising from your breach of clause 4 (Acceptable use), your infringement of a third party's rights through Customer Data, or a claim brought against us by a party to one of your Circles about content you shared or an action your agent took. This indemnity does not apply to the extent the claim arises from our own breach or negligence.

We will tell you promptly of any claim covered by this indemnity, give you control of its defence (except that any settlement admitting our fault or requiring payment by us needs our written consent), and give you reasonable assistance at your cost.

## 16. Governing law and disputes

This agreement is governed by the law of <span class="todo">[New South Wales]</span>, Australia. Each party submits to the non-exclusive jurisdiction of the courts of that State and the courts hearing appeals from them.

Before starting proceedings — other than for urgent interlocutory relief — a party must give written notice of the dispute and the parties must have their senior representatives meet, in person or by video, within <span class="todo">[14]</span> days to try in good faith to resolve it.

## 17. Changes to these Terms

We may change these Terms. For a change that materially and adversely affects you, we will give you at least **30 days' written notice** by email to your account administrators and by a notice in the Service. If you do not accept the change, you may terminate this agreement, without penalty, by written notice before the change takes effect, and we will refund fees you have paid for any period after termination.

Continuing to use the Service after a change takes effect means you accept it. We will keep previous versions available on request, and the version number and effective date in the title block above tell you which version you are reading.

## 18. General

**Notices** to us go to <span class="todo">[legal@circle.example]</span>; notices to you go to the email addresses of your account administrators. **Assignment**: neither party may assign this agreement without the other's consent, except to a successor of substantially the whole of its business, on notice. **Subcontracting**: we may use sub-processors in accordance with the [Data Processing Addendum](/legal/dpa). **Severability**: if a provision is unenforceable, it is read down or severed and the rest continues. **Waiver**: a failure to enforce a right is not a waiver of it. **Entire agreement**: this agreement, the policies it incorporates, and your order document are the whole agreement and replace any earlier understanding. **Force majeure**: neither party is liable for a failure caused by an event beyond its reasonable control, provided it tells the other and mitigates. **No partnership**: nothing here creates a partnership, joint venture, employment or agency relationship.
