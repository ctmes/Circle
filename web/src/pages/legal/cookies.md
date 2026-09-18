---
layout: ../../layouts/Legal.astro
title: Cookie Notice
description: What Circle stores in your browser — a session token and a theme preference — and what it does not.
path: /legal/cookies
docNo: LEG-06
summary: Circle stores two things in your browser, both essential, and neither of them a tracking cookie. This notice says what they are, why they exist, and what would have to change before a consent banner is needed.
---

<p><strong>This notice is a draft prepared for review, and it describes the system as built.</strong> It must be re-checked whenever an analytics, advertising or support-widget script is added to either the website or the application — that is the change that makes most of clause 4 apply.</p>

## 1. Cookies, and the things that are not cookies

"Cookie" is the word the law uses, but the rules apply equally to other ways of storing or reading information on your device — including `localStorage`, `sessionStorage` and similar technologies. Circle uses `localStorage`, not cookies, for both of the items below. They are covered by this notice for that reason.

## 2. What Circle stores

<div class="table-rail">

| Name | Type | What it does | Why it is essential | Lifetime |
| --- | --- | --- | --- | --- |
| `circle.token` | Local storage | Holds the bearer token issued when you sign in | Without it you would be signed out on every page you open | Until you sign out, or clear site data |
| `circle-theme` | Local storage | Remembers whether you chose the dark theme | Without it the page would revert to light on every visit | Until you clear site data |

</div>

Both are **strictly necessary**: they exist to deliver a service you have asked for. Under Australian law and under Art. 5(3) of the ePrivacy Directive as it applies in the EEA and the UK, strictly necessary storage does not require consent, and neither of these can be switched off while you are signed in.

Neither is a tracking identifier. Neither is shared with anyone. Neither is readable by any other site.

## 3. What Circle does not store

At the date of this notice:

- **No analytics.** The public website and the application load no analytics script, and set no analytics cookie.
- **No advertising or tracking.** No advertising pixels, no remarketing tags, no cross-site identifiers, no fingerprinting, and no third-party cookies of any kind.
- **No social embeds.** Nothing on this site loads a script from a social network.

The only third-party request the site makes is to Google Fonts, for the two typefaces used on pages where the operating system does not supply them. That request discloses your IP address and user agent to Google as an unavoidable consequence of fetching a file, and it sets no cookie. <span class="todo">[If EEA visitors are a material audience, self-host the fonts. It removes this disclosure entirely and is a small change.]</span>

## 4. If that changes

We will not add analytics, advertising or any other non-essential storage to the website or the application without:

- updating the table in clause 2 and the version number on this page;
- for visitors in the EEA and the UK, asking for consent **before** the script loads, with rejecting as easy as accepting, and no non-essential storage set unless consent is given; and
- providing a way to withdraw consent that is as easy as giving it.

Until that happens, there is no consent banner on this site — because there is nothing to consent to, and a banner asking permission for nothing trains people to click through the ones that matter.

## 5. Managing storage in your browser

You can clear site data for this site at any time through your browser's settings — usually under "Privacy", "Site settings" or "Cookies and site data". Clearing it signs you out and forgets your theme choice. Nothing else is lost: your work lives in the Service, not in your browser.

Blocking storage entirely will prevent you from signing in, because there would be nowhere to keep the session token. The public website works normally without it, in light theme.

## 6. Questions

Write to <span class="todo">[privacy@circle.example]</span>. This notice should be read with the [Privacy Policy](/legal/privacy).
