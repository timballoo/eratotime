# Eratotime — Requirements Document

**Working name: Eratotime.** Derived from Eratosthenes — the ancient Greek mathematician whose sieve finds primes by successively removing what doesn't qualify, leaving only what's genuinely left standing. "Erato" is literally the first five letters of "Eratosthenes," so the name is a clean truncation, not a reshuffling — and it happens to double as the name of the Muse of lyric poetry, a pleasant coincidence rather than something to lean on. The sieve logic is the same shape as this app's availability engine: template week, then overrides, then synced calendar busy-time, then pending-request holds, then buffers — each layer only removing time, never adding it — what survives all five passes is the open slot list (see section 2.2). Checked for collisions across trademarks, scheduling competitors, and domains before settling on it — came back clean on every front, unlike a few earlier candidates (Slotto turned out to already be a UK gambling brand, TimeToMeet was a direct hit on an existing meeting-scheduler product, and "Erastime" read too close to "Eras Tour").

**Revision history:** v2 (2026-08) — rewritten around the **request-submission model**. v1 specified direct Google Calendar event creation (the app creating events, invitees as attendees, tokenized reschedule/cancel). That model is retired: v2 has the app collect *requests* and notify the organizer, who creates the calendar event manually. This follows from the central problem the tool exists to solve (section 1.6), which v1's "accepted tradeoff" did not actually solve. The `db/eratotime_migration.sql` schema already reflected this direction; this document catches the spec up to it, and the Easy!Appointments review (Appendix B) is incorporated.

**Purpose of this document:** a complete functional and technical specification for an AI coding agent (Claude Code, Cursor, Codex, etc.) to build a personal, self-hosted Calendly-style *request* tool. This is a build spec, not a design brainstorm — where a decision has been made it's stated as a requirement, not a suggestion.

**Owner:** Dr. Stephen D. Jones. **Hosting:** Hostinger shared hosting (existing account, already running a PHP/JS application). **Stack:** PHP backend, vanilla JS (ES modules) frontend, no framework unless justified below.

**Naming applied throughout the build:** use "Eratotime" as the product/brand name wherever the organizer-facing UI needs one — the admin panel header/title tag, the public booking page's title tag and any "powered by" or footer credit, the sender display name on outbound email (e.g. `Eratotime <stephen@meertec.ltd>`, not a generic address — see 2.5's point about branded sending). Internal code (file names, function names, table names) can stay descriptive as already specified in sections 5–6; there's no need to rename `request_lib.php` to `eratotime_lib.php` or similar — the name is a branding layer on top of the existing technical structure, not a refactor of it.

**Build order:** Google Calendar *read-only sync* is the priority integration and should be built and tested first (auth + freebusy/busy-block sync). Microsoft/Outlook and CalDAV/Thunderbird are phase 2 — the architecture should anticipate them (a `calendar_sources` abstraction, not code hardwired to one provider) but the agent should not build them until Google sync is working and tested. No calendar *write* integration is built in v1 at all — the app never creates, updates, or deletes calendar events (see section 2.4).

---

## 1. Scope & Assumptions

### 1.1 In scope
- A **tenant-scoped booking site**: the schema and code are structured so every meaningful table is scoped by `tenant_id` from day one, but v1 ships and runs with exactly **one active tenant — you**. There is no self-serve sign-up, per-tenant billing, or tenant admin console in v1 (see 1.4 for the full posture and what's deferred).
- Public-facing booking page where invitees pick a meeting type (30 or 60 min) and an available slot.
- Availability computed as **layers**: a recurring template week (base), date-specific overrides on top, then synced calendar busy-time laid over that as a read-only external constraint, then pending-request holds, then buffers — see section 2.2 for the full model — producing the final list of open 30/60-min slots.
- **Request submission, not direct calendar creation.** When an invitee books a slot, the app: validates availability (server-side re-check, race-guarded), writes a **request record** (`request_log`) that **soft-holds** the slot for a configurable window, and sends notifications (confirmation to the invitee, a new-request notification to the organizer, optional WhatsApp). **The organizer then creates the calendar event manually** — the app does not touch the calendar API for writes. See sections 2.3–2.5. This is the fundamental model change from v1, and it exists to solve the identity problem (1.6).
- Calendar sources, in priority order:
  - **Phase 1 (build first): Google Calendar** (covers Gmail-linked calendars) — via Google Calendar API, OAuth2, **read-only** (busy-time sync). This is the only calendar source required for v1 to be usable.
  - **Phase 2 (later): Microsoft 365 / Outlook** — via Microsoft Graph API, OAuth2.
  - **Phase 2 (later): Thunderbird / generic CalDAV or ICS** — Thunderbird itself has no cloud API; it just consumes CalDAV servers or subscribed ICS URLs. So "syncing with Thunderbird" in practice means either (a) reading a CalDAV account the same calendar is stored on, or (b) subscribing to an ICS feed URL. Build **generic CalDAV support** (RFC 4791) plus **ICS feed subscription** as a fallback — this covers Thunderbird, iCloud, Fastmail, Nextcloud, and any other CalDAV/ICS source without writing bespoke integrations per provider.
  - Design the `calendar_sources` abstraction (section 5/6) so phase 2 providers slot in without reworking the availability engine — but don't build their provider code yet.
- Admin panel to manage meeting types, connected calendars (read-only sync status), working hours, the request log, and a short organizer profile.
- Time zone handling (invitee's browser timezone vs organizer's fixed timezone).

### 1.2 Out of scope (v1)
- Multiple organizers / team round-robin booking.
- Payment collection at booking time.
- Native mobile app (mobile web only, responsive).
- **Any calendar write integration** — no `events.insert`/`update`/`delete`, no attendee management, no `.ics`/iTIP invites, no tokenized self-service reschedule/cancel. All of that is retired in v2; changes and cancellations are handled manually by the organizer (2.4).
- Microsoft/Outlook and CalDAV/Thunderbird calendar sources — phase 2, not v1 (see 1.1).

### 1.3 Assumptions to confirm with the coding agent before it starts
State these explicitly in the agent's initial context so it doesn't silently pick different ones:
- Schema is tenant-scoped from day one (1.4), but exactly one active tenant exists in v1 — you. No sign-up flow, no per-tenant auth UI, no billing.
- Google Calendar is the only live calendar source in v1, and it is **read-only**: busy-time sync for availability. **No write scope is requested, ever.**
- The app creates **no calendar events, no `.ics`/iTIP invites, and no `ORGANIZER`-derived anything**. The SMTP `From:` header on every outbound email always comes from `global_settings.mailbox_destination` (`stephen@meertec.ltd`). The Gmail address (`meertec.ltd@gmail.com`) must **never appear in anything the app itself sends or renders** — it is only ever present in the admin panel as the sync account label.
- Booking creates a **request record** with a **soft-hold**, not a calendar event. The organizer manually creates the calendar event afterwards and marks the request fulfilled (2.4/2.6). The app owns the request record, not the calendar event.
- **Organizer identity is under the organizer's control, not the app's** — because the app never creates events, whichever calendar the organizer creates the event in determines what the invitee sees (this is the decision in 1.6, still open).
- No user accounts/login system for invitees.
- Admin panel is protected by a single password/passphrase for your tenant specifically (or reuse whatever auth pattern already exists on the Hostinger account) — not a full multi-user/multi-tenant auth system yet, even though the `tenant_admins` table exists (1.4).

### 1.4 Multi-tenancy posture — tenant-ready schema now, single tenant live

The eventual goal is that someone else could run **their own fully separate instance-in-effect** — own calendar connection, own branding, own booking page — on the same codebase/deployment, rather than a pooled/shared-availability mode (confirmed: isolated tenants, not team/round-robin scheduling — see section 9 for why round-robin is a different, unrelated feature).

**What to build now (modest, worth doing at this stage):**
- Every table in section 5 gets a `tenant_id` column and every query is scoped by it — including a `tenants` table itself (id, slug, display_name, active, created_at) with exactly one seeded row for you.
- `calendar_sources` (already modeled as a list, section 5) becomes naturally per-tenant — each tenant connects their own Google account via its own OAuth consent, which Google's OAuth flow already supports without any extra work (one registered app, many separate users authorizing independently).
- URL routing needs a tenant identifier. **Recommend path-based routing** (`/t/{tenant-slug}/book/{meeting-type-slug}`) over subdomain-based (`{tenant-slug}.yourdomain.com`) for now — subdomains would need per-tenant DNS/SSL provisioning on Hostinger for every new tenant, which is real ongoing ops work; a path prefix needs none of that and can move to subdomains later without a data model change if it's ever worth it.
- Create a `tenant_admins` table (id, tenant_id, username, password_hash) now, even though v1 only ever has one row in it and the admin panel still checks against a single configured password for you specifically — this avoids a schema change later, but **do not build a sign-up/login UI, password reset flow, or multi-user session handling yet**; that's explicitly deferred (below).

**What this deliberately does NOT include yet (genuinely bigger, separate phase):**
- Self-serve tenant sign-up/onboarding flow.
- Per-tenant admin authentication (real login, not a single shared passphrase) and session management.
- Per-tenant OAuth *onboarding UI* (a tenant clicking "connect their Google Calendar" themselves, vs. it being set up manually for you).
- Any billing/plan/quota logic.
- Any UI for you to manage other tenants (a "super-admin" tenant list) — not needed while there's exactly one tenant.

This split matters because the first list is cheap to do now and expensive to retrofit later (queries not scoped by tenant are a classic source of cross-tenant data leaks if bolted on after the fact), while the second list is a genuinely separate, larger project that shouldn't be pulled into v1 just because the schema is tenant-ready — there's no user-facing benefit to building tenant sign-up for a tenant count of one.

### 1.5 Concrete v1 deployment values (the seeded tenant)

Confirmed, real values for the single seeded tenant — use these directly rather than placeholders when scaffolding the database and config:

- **Domain:** `www.meertec.ltd` — a distinct domain, not a subfolder of any other existing app. There is no shared session, login system, or SSO to inherit from anything else on this domain; Eratotime is a standalone deployment here, full stop.
- **Google Calendar account to sync (read-only):** `meertec.ltd@gmail.com` — this is the account the OAuth consent flow (3.2) should be run against for the seeded tenant's `calendar_sources` row. Its busy time is what blocks slots. It is **not** a client-facing identity — it must never appear in app-sent emails or the booking page.
- **All app email comes from:** `stephen@meertec.ltd` — the client-facing organizer identity, used for the booking page, confirmation emails to invitees, and new-request notifications to the organizer, via PHPMailer/SMTP.
- These three values should be the actual seed data in the first migration/setup script, not left as `TODO`/placeholder strings for the agent to guess at or leave unconfigured.

### 1.6 The central problem — the identity tension

This project exists to resolve a specific tension, and the whole design follows from it:

> **Face the client as `stephen@meertec.ltd`, while doing the actual calendar management (syncing with other calendars, blocking busy time) on `meertec.ltd@gmail.com`.**

A personal/free Google account cannot send calendar invites under an alias, and it cannot be renamed to `stephen@meertec.ltd` — Google deliberately ties Calendar-invite sender identity to the actual account. So any design where *the app* creates calendar events in the Gmail account leaks that Gmail address to the invitee as the event organizer.

**How v2 resolves this:** the app **stops creating calendar events entirely**. The invitee's booking produces a *request* that the app holds and emails to the organizer. The organizer then creates the event manually — in whichever calendar they choose. The invitee-facing identity is therefore **decided by the organizer at event-creation time, not by the app**. The Gmail account's only remaining role is its busy time feeding the availability engine (read-only), where its identity is invisible to invitees.

This leaves one genuinely open decision that the app cannot settle for you — **the calendar-of-record**: which calendar/identity the organizer actually creates meetings in, given that events must land somewhere that keeps availability accurate. Options and the decision gate are in Appendix A. The build does not need this resolved to proceed through Phases 0–2; it must be resolved **before Phase 3** (which chooses the sync target) — see section 10.

The one operational rule that keeps everything consistent regardless of that decision: **any calendar you actually add meetings to must be a synced `calendar_sources` entry (so its busy time blocks slots), or you must block that time manually in the availability grid.** The app has no way to know about meetings it isn't told about.

---

## 2. User-Facing Functional Requirements

### 2.1 Meeting types
- v1 ships with two default meeting types — a 30-minute and a 60-minute slot — but the underlying model is generic (not hardcoded to two types) so more can be added later without a schema change. Each meeting type has:
  - Name, description, duration (minutes), colour/label.
  - **Location/meeting details** — a simple field describing where the meeting happens: a phone number, a video call link (Zoom/Meet/Teams), or a physical address/free text. This is per meeting type (a "30-min call" might always be phone, a "60-min session" always video) and gets included in the new-request notification email to the organizer (2.5) and in the Google Calendar quick-add link (2.5), so the organizer can create the event correctly at a glance.
  - Buffer time before/after (minutes), configurable per type.
  - Minimum notice required before booking — default **1 day** (no bookings within 24 hours of the slot).
  - Maximum booking horizon — default **14 days** (bookable window is roughly 1–2 weeks out; make this configurable per meeting type in case 30/60-min types ever need different horizons, but both default to 14 days).
  - Daily booking cap per type (optional — e.g. max 3 meetings of this type per day).
  - Active/inactive toggle and a unique slug for its booking URL (`/book/intro-call`).
  - Questions to ask the invitee at booking time (name, email always required; optionally custom fields — company, phone, free-text notes).
  - **"Add guests"** — an optional repeatable field on the booking form letting the invitee list one or more additional email addresses to include. These are stored on the request and forwarded to the organizer in the notification email (since the app creates no event, it cannot add them as attendees itself; the organizer adds them when creating the event).
- **Global cap, across all meeting types combined** (e.g. "no more than 4 meetings per day, regardless of type") — sits alongside the per-type daily cap above, protecting the whole week rather than just one meeting type from being overloaded. Configured once in admin (not per meeting type), and checked in addition to (not instead of) each meeting type's own cap. There is a daily cap and a weekly cap, both global.

### 2.2 Availability rules
- **Layered model, in order:**
  1. **Base layer — the recurring template week.** A weekly recurring working-hours pattern (e.g. Mon–Fri 09:00–17:30) that repeats indefinitely until changed. This is the starting point for every week's availability.
  2. **Override layer — date-specific exceptions.** For a particular date or range, either fully block it (holiday) or open extra availability beyond the template (an ad-hoc Saturday, say). This layer only affects the specific dates it targets; every other date still follows the base template.
  3. **External constraint layer — synced calendar busy time, laid on top.** Whatever's left after layers 1–2 gets further reduced by anything the synced calendar(s) show as busy (synced via cron into the local `calendar_blockouts` cache — never a live call per page view; see 3.5/4.4). This layer is read-only and purely subtractive: it can only remove time, never add it back.
  4. **Soft-hold layer — pending requests occupy their slot.** A `request_log` row that is `pending` (and not yet expired) blocks its booked interval, so a second invitee cannot take the same slot while the first request is unresolved (2.3). This is what prevents double-booking, and it replaces v1's "the calendar event itself blocks the slot" mechanism — the app must not rely on the organizer having created the event yet.
  5. **Buffers.** Per-meeting-type buffer time before/after is subtracted last.
- **Implementation approach (borrowed from the Easy!Appointments review, Appendix B):** for a given date, resolve the schedule from layer 1 (per day-of-week), then apply layer 2 (an exact-date override replaces the day's schedule, or blocks it outright — it does not merge). Build the day's free intervals by subtracting layer 3 and layer 4 busy intervals from the schedule via a **sorted-merge interval-subtraction routine** (not Easy!Appointments' fragile append-while-iterating array splits). A slot start is bookable iff its full footprint (`start − buffer_before` … `end + buffer_after`) lies entirely inside a free interval and does not overlap any other slot footprint, blockout, or soft-hold. Then slice onto the 30-minute slot grid.
- **Timezone/DST handling (borrowed from the EA review):** keep all schedule data timezone-naive (wall-clock in the organizer's timezone — stored as `TIME`/`DATE` values and manipulated as plain times). Only the "is it still bookable right now" checks (min-notice, max-horizon) use real timestamps in the organizer's timezone, where PHP's `DateTime` handles DST correctly. A 9am London start stays 9am London through BST/GMT transitions, and DST-shifted days (23/25-hour days) need no special handling in the grid because all slot math is wall-clock.
- **Caps:** per-type daily cap and global daily/weekly caps are enforced by counting `request_log` rows with status `pending` or `fulfilled` whose interval falls in the period (soft-holds count, so a pending request also counts against caps).
- **Admin UI for layers 1–2 is a visual weekly grid** (see 2.6 for full detail): a 7-day grid of slot-length cells (30-min granularity) where the organizer clicks/drags to toggle a cell open or blocked, with blocked cells greyed out. Editing the default week edits the base template (layer 1); navigating to a specific week and editing there creates a date override (layer 2) without touching the template. Layer 3 (synced calendar busy time) renders on the same grid but read-only and visually distinct, so it's clear at a glance which cells you've blocked yourself versus which are blocked because your calendar says you're busy. Layer 4 (pending soft-holds) also renders distinctly and read-only, so you can see at a glance which slots are currently held by unresolved requests.
- Final availability = base template ∩ (date overrides applied) − (synced busy time) − (pending soft-holds) − (buffers), sliced into slot-length increments, subject to min-notice, max-horizon, and caps.

### 2.3 Public booking flow (request submission)
1. Invitee opens `/t/{tenant-slug}/book/{meeting-type-slug}` (30-min or 60-min). The page shows a short organizer bio and photo above the picker (a couple of sentences plus a headshot, configured once in admin) — cheap to build and it's the first thing a stranger sees before requesting your time.
2. Sees a **read-only** calendar/date picker showing only dates with at least one open slot, computed as in 2.2, in the invitee's **detected local timezone** (with a manual timezone override dropdown).
3. Selects a date → sees list of open time slots for that day.
4. Selects a slot → a prompt/modal collects invitee details: name, email, any custom questions for that meeting type (e.g. topic/notes), and an optional **"add guests"** field for extra email addresses.
5. Submits → the app, in a transaction (see 2.2 and 4.2):
   - Re-checks availability server-side for the requested slot (the anti-double-booking race guard — a second person may have taken it since the page was rendered).
   - Writes a `request_log` row (status `pending`) that **soft-holds** the interval for a configurable window (`global_settings.request_hold_hours`, default 24 — after which, if still unactioned, the slot reopens but the request stays in the log).
   - Queues `notification_outbox` rows: confirmation to the invitee, new-request notification to the organizer, and (if enabled) WhatsApp to the organizer. All three are independent, retryable channels (4.5).
   - **Does not create any calendar event.** No Google API write, no attendee, no `.ics`.
6. The invitee sees an on-screen confirmation with the request details.

### 2.4 Changes & cancellations — manual, organizer-owned (replaces v1's reschedule/cancel)
v1 shipped self-service reschedule/cancel via tokenized links backed by the app's own calendar-event write. In v2 the app owns **requests, not events**, so:
- **Cancellations by the invitee** are **not supported in v1** — there is no tokenized cancel link, no `events.delete`. A cancelled slot becomes free only when the organizer marks the request cancelled (2.6) or the soft-hold expires (2.3). An invitee who needs to cancel contacts the organizer directly (the booking page can show contact details for this, configured in admin). The mechanics of self-service cancellation are noted as a future enhancement (section 9).
- **Reschedules** are likewise handled manually: the organizer cancels/reopens and the invitee re-books.
- **The organizer's workflow** for every new request: open the notification email (or the admin request log) → click the Google Calendar **quick-add link** (2.5) → create the event in the chosen calendar → mark the request `fulfilled` in admin (2.6). Marking `fulfilled` ends the soft-hold's blocking role.
- **Operational consistency rule (from 1.6):** the event the organizer creates must land in a synced calendar source, or the time must be blocked manually in the grid — otherwise the slot stays open for re-booking. The app does the best it can: it can't see events it isn't told about.

### 2.5 Notifications & email delivery
- **Confirmation email to the invitee** (sent on successful request submission via PHPMailer/SMTP from `stephen@meertec.ltd`): invitee name, meeting type/duration, requested date/time (in the invitee's timezone), location/meeting details, and a short note that the organizer will confirm by sending the calendar invitation. This is the only channel the app has to the invitee (there is no Google-generated invite in v2), so its reliability matters — see the retry cron in 4.5 and the failed-notification warning in 2.6.
- **New-request notification email to the organizer** (also from `stephen@meertec.ltd`, sent to `global_settings.mailbox_destination`): invitee name/email, guests, meeting type/duration, requested date/time (in the organizer's timezone), answers to custom questions, location/meeting details, and a **Google Calendar quick-add link** (`https://calendar.google.com/calendar/render?action=TEMPLATE&text=...&dates=...&location=...&details=...`) pre-filled with everything the organizer needs to create the event in one click. This link is a plain web URL — no `.ics` attachment, and it contains no reference to `meertec.ltd@gmail.com` (Phase 5's Definition of Done verifies that: the Gmail address appears nowhere in any email the app sends).
- **Organizer identity is clean in v2, by construction.** The app never creates events, so it never surfaces `meertec.ltd@gmail.com` to an invitee. Every email the app sends comes from `stephen@meertec.ltd`. The only remaining question — which calendar the organizer *manually* creates events in, and therefore what shows on the invitee's calendar invite — is the open calendar-of-record decision (1.6 / Appendix A), not something the app controls or leaks.
- **Optional WhatsApp notification, per tenant, toggleable in admin.** On a new request, additionally send a short WhatsApp message (invitee name, meeting type, requested date/time) to a configured phone number. Reuse the existing CallMeBot/TextMeBot pattern already working on the FIFA sweepstake project on this account. Treat the three channels (invitee email, organizer email, WhatsApp) as independent, not one depending on another.
- **Delivery mechanism (email):** use **PHPMailer** (Composer package `phpmailer/phpmailer`) configured to send via Hostinger's provided SMTP credentials for the domain's mailbox, not PHP's built-in `mail()`. HTML body with a plain-text `AltBody` (borrowed from EA's email code).
- **No reminder emails from this app, by design.** Once the organizer creates the Google Calendar event with the invitee as attendee, Google Calendar's own reminder settings are the correct source of truth.
- **Eratotime IS a system of record for requests.** The `request_log` table is authoritative for "who asked for what, when". The calendar event (created by the organizer) is the invitee's operational reference. The two are linked only by the organizer's workflow and the request status in admin.

### 2.6 Admin panel
- Password-protected.
- **Organizer profile**: short bio text and a photo upload, shown on the public booking page (2.3). One config, not per meeting type.
- Meeting type CRUD (section 2.1), including the location/meeting-details field, per-type daily cap, and the global daily/weekly cap settings.
- **Availability editor — weekly grid view** (this is the main way working hours and overrides get set).
  - Layout: 7 columns (Mon–Sun) × rows in slot-length increments (30-min granularity, e.g. 08:00, 08:30, 09:00 … through the working day), one screen per week.
  - Each cell is clickable/toggleable between **open** and **blocked**; blocked cells render greyed out. Click-and-drag over multiple cells to toggle a range in one gesture, since toggling cell-by-cell for a whole day would be tedious.
  - **Two layers, visually distinguished:**
    1. The **recurring weekly template** (section 2.2) — editing this week's grid in "template mode" changes the pattern that repeats every week going forward (e.g. always-blocked Wednesday afternoons).
    2. **Date-specific overrides** for one particular week — navigating to a specific week (prev/next-week controls, or jump-to-date) and toggling cells there creates one-off overrides for those exact dates (e.g. blocking out a holiday week, or opening extra availability on a normally-closed day) without touching the recurring template.
  - A third visual state, **read-only and not togglable**: cells already blocked by synced calendar busy time (section 2.2/3) should render distinctly from organizer-set blocked cells (e.g. a different shade or a small icon) — so at a glance you can tell "blocked because I said so" from "blocked because my calendar says I'm busy."
  - A fourth visual state, also **read-only**: cells held by pending (unexpired) request soft-holds (e.g. a hatched pattern or small dot), so you can see which slots are currently reserved by requests you haven't actioned.
  - This grid is the same underlying data as the meeting-type booking page's read-only calendar view, just editable here and read-only there.
- Connected calendars: Google Calendar connection status (phase 1, read-only); manual "sync now" button; last-sync timestamp and status (success/error).
- **Failed-notification warning**: if any notification channel has failed repeatedly, surface this prominently on the admin dashboard — an invitee thinks they've booked a meeting and you have no idea it happened.
- **Request log**: a list of requests (invitee, requested slot, meeting type, answers, timestamp, status: `pending`/`fulfilled`/`cancelled`/`expired`), with actions to **mark fulfilled** or **mark cancelled** (this is how the organizer closes the loop in the 2.4 workflow). Read-only in the sense that request content is never edited.

### 2.7 Embedding on the personal business site
The booking page needs to be embeddable on a separate personal business site, not just linked to. **The simplest and most robust approach is a plain `<iframe>` embed**, not a JS-widget-loader like Calendly's — for a single-organizer tool this is the right tradeoff:
- The booking page already lives at its own URL on the Hostinger domain (`/t/{slug}/book/{slug}`), so it's inherently separate code from whatever the business site is built with — nothing further needs to be architected to "separate" it.
- An iframe embed (`<iframe src="https://www.meertec.ltd/t/meertec/book/intro-call">`) can't leak CSS or JS between the host site and the booking page in either direction.
- Practical requirements to make this work well:
  - The booking page must render correctly standalone with no dependency on any parent-page asset — confirm this is already the case, since it's the same page used directly.
  - **Responsive height**: an iframe has a fixed height by default, but the booking page's content height changes as the invitee moves through date → slots → form. Use a small `postMessage` handshake — the booking page posts its current content height to the parent window on load and on major state changes, and a short embed snippet (a few lines of JS on the business site) listens for that message and resizes the iframe accordingly.
  - Confirm the Hostinger site doesn't send an `X-Frame-Options: DENY` or restrictive `Content-Security-Policy: frame-ancestors` header on the booking page path — if one exists (from a shared `.htaccess` rule across the account), it needs an explicit exception for this path so the iframe isn't blocked by the browser.
- **What not to build**: a full JS-loader widget (inline/popup/popup-widget modes, like Calendly's official embed snippet) is more engineering than this single-organizer use case justifies. A plain iframe plus the resize handshake above gets the same practical result — the page displays inline on the business site — for a fraction of the effort.

---

## 3. Calendar Sync — Detailed Requirements

Google Calendar is the priority integration and should be fully built, tested, and working before any phase 2 provider is started. **All sync in v1 is read-only** — the app fetches busy time to block slots; it never writes events.

### 3.1 Recommended PHP libraries for Google Calendar

Two viable approaches — pick one and note the tradeoff for the agent:

- **`google/apiclient` (official Google API PHP Client, Composer package `google/apiclient`).** This is the recommended default. It handles the OAuth2 authorization-code flow, access-token refresh using a stored refresh token, and exposes a typed `Google\Service\Calendar` class. Since v2 needs no write methods, the required surface is just `events->list()` (and optionally `freebusy->query()`) plus the OAuth plumbing. Pros: battle-tested, handles token refresh edge cases for you, well documented. Cons: it's a fairly large dependency if you pull in the full client; as of recent versions you can require just the core (`google/apiclient` plus the `Calendar` service class) rather than the old all-services bundle, which keeps the Composer footprint reasonable. This is the one to use unless the Composer install proves impractical on the Hostinger plan.
- **Lightweight alternative — `league/oauth2-google` (thephpleague) for just the OAuth2 handshake and token refresh, combined with raw `curl`/`file_get_contents` REST calls directly against the Calendar API v3 `/events` endpoint.** Pros: much smaller footprint, full visibility into exactly what's being sent, easier to reason about on constrained shared hosting. Cons: you're writing and maintaining your own request/response handling for the sync surface. Worth doing only if the full Google client proves too heavy or awkward to install on Hostinger.
- **Recommendation for the agent:** start with `google/apiclient` (narrowed to core + Calendar service). Fall back to the `league/oauth2-google` + raw REST approach only if Composer/dependency size becomes a real problem on the Hostinger account — confirm Composer works on this hosting plan before committing to the heavier option (see 4.1).
- Either way, confirm at build time whether the Hostinger plan allows running `composer install` via SSH, or whether `vendor/` needs to be built locally and deployed/committed — this determines how dependencies get onto the live server.

### 3.2 Google Calendar (OAuth2) — build this first

- Use Google Calendar API v3 for **reading busy time** (availability sync). **No write methods are called anywhere in v1.**
- OAuth2 flow: standard authorization code flow via the chosen library (3.1), with **`access_type=offline`** (so a refresh token is returned), **`prompt=consent`** and **`max_auth_age=0`** on the consent URL (forces fresh consent each connect — borrowed from the EA review). Store the refresh token **encrypted at rest** (see section 6/4.2). Access tokens refreshed on demand lazily on sync.
- **Scope:** a single scope — `https://www.googleapis.com/auth/calendar.readonly`. This covers both `events.list` for busy blocks and (if preferred) `freebusy.query`. No write scope is requested.
- **Busy-block source (recommended: `events.list`, not `freebusy.query`).** The EA review found Easy!Appointments never used `freebusy.query`; it imported external events from `events.list`. For our purposes `events.list` is the better primary source: it returns actual event objects (all-day flags, cancelled status, stable IDs for dedup) rather than opaque busy intervals, and it has no freebusy multi-calendar quirks. Fetch with `timeMin`/`timeMax` (RFC3339), `singleEvents=true`, `maxResults=2500`, explicit pagination up to a bounded page count with a warning log if the cap is hit (borrowed from EA's pagination-safety pattern). Treat every non-cancelled timed event as a busy block; handle all-day events as a full-day block (00:00–24:00 in the organizer's timezone; keep the exclusive `+1 day` end convention when dealing with Google's date-only representation).
- Setup prerequisite the agent should document clearly, not attempt to automate: registering a project in Google Cloud Console, enabling the Calendar API, configuring the OAuth consent screen, and creating OAuth2 credentials (client ID/secret) with the correct authorized redirect URI pointing at the Hostinger domain.
- Support multiple Google calendars under one Google account (e.g. personal + work), each toggleable independently in the admin panel, even though only one provider (Google) is live in v1 — the `calendar_sources` table already models this per section 5.

### 3.3 Phase 2 (build later, not in v1): Microsoft 365 / Outlook

- Use Microsoft Graph `/me/calendar/calendarView` (or `/getSchedule`) for the relevant date range, read-only in the same sense as Google.
- OAuth2 via Azure AD app registration (a manual one-time setup step, same category as the Google Cloud Console step above).
- Scope: `Calendars.Read`. Same multi-calendar toggle requirement as Google.
- Recommended library: `microsoft-graph/msgraph-sdk-php` (official), or plain OAuth2 via `league/oauth2-client` with the `stevenmaguire/oauth2-microsoft` provider plus raw REST calls to Graph, mirroring the lightweight Google alternative above.

### 3.4 Phase 2 (build later, not in v1): CalDAV / ICS (covers Thunderbird and others)

- **CalDAV path:** implement a minimal CalDAV client using `PROPFIND`/`REPORT` over HTTPS with basic auth or app-specific password (most CalDAV providers — iCloud, Fastmail, Nextcloud, self-hosted Radicale/DAViCal — use this). `sabre/dav`'s client library (Sabre/VObject + Sabre/DAV client, both pure PHP, Composer-installable) is the recommended building block rather than writing raw XML parsing from scratch.
- **ICS feed path (simpler, build this one first when phase 2 starts):** allow the organizer to paste a published ICS feed URL (e.g. from Thunderbird's calendar published via WebDAV, or any calendar's public/secret ICS link). Fetch and parse periodically via cron; treat all VEVENTs in range as busy blocks. Use Sabre/VObject for ICS parsing (robust against malformed feeds, which are common).

### 3.5 Sync architecture (applies to Google now, and any phase 2 source later)
- All calendar sync is **pull-based**, run on a schedule via cron — not real-time/webhook-based (Hostinger shared hosting cannot reliably run persistent webhook listeners or long-lived processes).
- Cron frequency: every 5–15 minutes is reasonable for a personal scheduler (tighter intervals raise API call volume against Google's rate limits for no real benefit at this scale).
- Each sync run: fetch busy blocks for a rolling window (e.g. now → +90 days), store as normalized rows in a local `calendar_blockouts` table (source, external_uid, start_utc, end_utc, last_synced_at), **idempotently upsert by `external_uid`**, and delete/expire rows no longer returned by the source (so cancelled/removed Google events stop blocking availability). The unique key on `(calendar_source_id, external_uid)` makes re-runs safe — borrowed from EA's "pre-fetch and relink, don't re-insert" lesson, minus their fragile timestamp matching (we key on the event ID, which Google guarantees stable).
- **Events created by the organizer** (manually, in a synced calendar) will appear in the next sync and correctly block that slot. No special handling needed — the sync treats all events identically regardless of origin.
- Sync failures (expired token, provider outage, network error) must not silently disappear — log to the activity log and surface a warning in the admin dashboard, and **fail closed for availability purposes if the source has not synced successfully within N hours** (i.e. better to show fewer available slots than to double-book because a sync silently died). Make N configurable, default 24h.
- Design the sync orchestration (`calendar_sync_lib.php`) as a loop over active `calendar_sources` rows, calling a per-provider function — this is what makes adding Microsoft/CalDAV in phase 2 additive rather than a rewrite.
- **OAuth robustness (borrowed from the EA review):** the OAuth callback verifies the state parameter with `hash_equals` (CSRF), runs in a popup window that signals success to the parent via `postMessage('oauth_success')` (no URL-polling race), and on 401 the sync marks the source failed and surfaces an actionable "re-connect" message rather than failing silently.

### 3.6 (removed)
v1 had a section here on cancellation/reschedule via the Calendar API (`events.delete`/`events.update`). v2 has no calendar write path — see section 2.4 for how changes and cancellations are handled manually, and section 3.5 for why sync consistency still matters (it keeps `calendar_blockouts` correct whether or not the organizer creates events in a synced calendar).

---

## 4. Non-Functional Requirements

### 4.1 Hosting constraints (Hostinger shared hosting)
Design around the same constraints as previous work on this hosting account:
- **Dedicated database, physically co-located but architecturally independent.** Eratotime gets its own MySQL database (own schema/name, e.g. `meertec_erato`, own DB user and credentials) — not tables sharing a schema with any other Meertec app, even though it may live on the same MySQL server/hosting account as those apps for now. This matters because Eratotime has the one genuinely unauthenticated public write path in the system (the request-submission endpoint, 4.2) — a separate database limits the blast radius of anything going wrong there to Eratotime's own data, not whatever else Meertec hosts.
  - **No cross-database SQL, ever** — no `otherdb.table` joins, no foreign keys spanning databases, nothing in any query that assumes another Meertec database is reachable from this connection.
  - Keep connection details (host/user/password/dbname) entirely in Eratotime's own config, with nothing hardcoded that assumes co-location with any other database. The intent: if this ever moves to a different hosting account, only the connection string in one config file should need to change — no query should need touching.
- No long-running background processes — all scheduled work (calendar sync, notification retry, request-log cleanup) runs via **cron-triggered PHP scripts**, not daemons.
- Any request likely to take longer than typical shared-hosting gateway timeouts (calendar sync, especially on first connect) should be broken into an AJAX-driven flow with a progress indicator rather than one long synchronous request.
- Use `set_time_limit()` generously on cron/sync scripts, and keep OAuth token refresh and per-provider fetch each individually time-bounded so one slow provider can't exhaust the whole script's budget.
- If outbound HTTPS calls to Google/Microsoft/CalDAV hosts hit SSL verification issues under Hostinger's PHP/cURL config (as has been seen before on this hosting account, and as Easy!Appointments itself papered over with `verify => false`), the standard workaround (`CURLOPT_SSL_VERIFYPEER => false`) is available but should be a **documented fallback, not default** — try with verification on first, since disabling it removes protection against MITM on OAuth token exchanges (higher stakes than a previous read-only use case). **Do not copy EA's unconditional `verify => false`.**
- No shell access assumed beyond what's already available; Composer dependencies should be vendored/installed at build/deploy time and committed, or installed once via SSH if available on the Hostinger plan — confirm which is the case before assuming Composer runs on the live server.
- **Check the Hostinger plan's MySQL database count limit** before assuming a dedicated database is free to repeat for every future Meertec app.

### 4.2 Security
- **Tenant isolation is a security requirement, not just a data-modeling one**: every query must filter by `tenant_id` (1.4/5) — a missing scope check is a cross-tenant data leak, not just a bug, so this deserves the same rigor as the other items below even while there's only one tenant in practice.
- OAuth refresh tokens and CalDAV credentials **must be encrypted at rest** (e.g. `sodium_crypto_secretbox` with a key stored outside the web root, in an env file not served publicly), not stored plaintext in `config.php` or the database. **This is a deliberate divergence from Easy!Appointments, which stores its `google_token` as a plaintext JSON blob in the DB** (Appendix B).
- Admin panel behind authentication; rate-limit or lock out repeated failed logins.
- **The request-submission endpoint is the single unauthenticated write path, and it needs layered protection (borrowed/improved from the EA review):**
  - **Per-IP rate limiting** using a file-cache counter (no Redis needed on shared hosting) — e.g. 10 submissions / 10 minutes / IP on the submit endpoint, plus the coarse global limit on all requests. EA's pattern at `rate_limit_helper.php` is the model; ours must also cover the submit endpoint specifically (EA's booking submit only had the coarse global limiter).
  - **ALTCHA proof-of-work widget** on the booking form (Composer package `altcha-org/altcha`) as the invisible anti-bot layer — the invitee's browser solves a small SHA-256 challenge; the server verifies the HMAC-signed solution at submit time. **Critical footgun to avoid (from the EA review):** the ALTCHA HMAC key must be generated once and persisted in config/settings at install time, not regenerated per request — EA's per-request regeneration made challenges unverifiable.
  - **Honeypot field** (a hidden text field bots fill) as a cheap first filter.
  - **Manual CSRF verification** with `hash_equals` on the submit endpoint (pattern: EA `Booking.php` `verify_csrf_token`), since the public path is excluded from any framework-wide CSRF.
  - **Server-side re-validation of everything** — availability re-check inside the transaction (2.3), field whitelisting (`array_intersect_key` style, EA `Booking.php:413-416`), email format checks, and **server-side recomputation of all times** (never trust client-supplied times).
  - **Double-submission guard at the database:** the submit transaction takes a per-tenant serialization point (`SELECT ... FOR UPDATE` on the tenant's `global_settings` row) and re-checks overlap; a unique index on `(tenant_id, requested_start_utc, invitee_email)` is the last line of defence against identical duplicate submissions. This fixes EA's known check-then-insert race (Appendix B), which the EA code itself acknowledges.
- All booking form input server-side validated and sanitized (email format, duration bounds, XSS-safe rendering of custom-question answers in confirmation emails, notification emails, and the admin request log).
- HTTPS enforced site-wide (Hostinger provides free SSL — confirm it's active and HTTP is redirected).

### 4.3 Timezones
- Store all times in the database in UTC (`*_utc` columns) — but see 4.3 note below about the availability engine's wall-clock layer.
- Organizer's timezone is a fixed config value per tenant (stored in `global_settings`, e.g. `Europe/London`), used for that tenant's working-hours rules and admin display.
- The availability engine keeps **schedule-level data timezone-naive** in the organizer's timezone (2.2): `working_hours` times, override times, and the blockout/soft-hold busy intervals are compared as wall-clock times; only the min-notice/max-horizon gates and the UTC storage conversions involve real timestamps. This is the DST-safe design borrowed from the EA review — a 9am London start stays 9am London through BST/GMT, and DST-shifted days need no special-casing in the grid.
- Invitee's timezone auto-detected client-side (`Intl.DateTimeFormat().resolvedOptions().timeZone`) with a manual override dropdown, since browser detection can be wrong (VPNs, misconfigured OS).

### 4.4 Performance
- Availability computation for a given date range should be a single efficient query/merge against pre-synced local `calendar_blockouts` and `request_log` rows (not a live call out to Google per page view) — this is what makes pull-sync worthwhile: the public booking page must be fast and never wait on an external API.
- The admin grid loads per-week merged data in one AJAX call (2.6).

### 4.5 Resilience — request submission must be durable
The critical path is: write `request_log` → queue `notification_outbox` rows. There is no calendar-event step (unlike v1), which removes the v1 failure mode of "event created on Google but no local record". The remaining risks:
- **`request_log` write is the durable step.** It happens first, inside the transaction. If it fails, the invitee sees an error and the slot stays free.
- **Notifications are fire-and-forget with retry.** Each channel is an independent `notification_outbox` row (status `pending`/`sent`/`failed`). If a send fails, the request still exists; the invitee still saw the on-screen confirmation. Log the failure and let the retry cron handle it.
- **A retry cron** (`cron/retry_notifications.php`) periodically retries failed outbox rows with backoff up to a bounded number of attempts or time window (e.g. give up after 24 hours), at which point log a **visible warning in the admin dashboard** (2.6) — if the invitee's confirmation email can't be delivered, you need to know, because there is no Google-generated invite as a fallback channel in v2.
- **A cleanup cron** (`cron/cleanup.php`) purges `request_log` rows older than `request_log_retention_days` (default 30) and expired soft-holds past the retention window — borrowed from EA's `Cleanup` cron concept, applied to the request log.

### 4.6 Code quality and dependency security scanning — Sonar and Snyk, mandatory
Both tools are required for this project, not optional nice-to-haves:
- **Sonar (SonarQube or SonarCloud)** for static analysis — code smells, duplicated logic, complexity hotspots, and PHP-specific correctness issues (e.g. unvalidated input reaching a query, unreachable code, missing null checks) across every `*_lib.php`, `/api/*.php`, and `/providers/*.php` file. SonarCloud is the lower-friction option for a project this size (hosted, no server to run yourself) if the repository can be public or a free/trial private-repo tier suffices; self-hosted SonarQube is the fallback if not. **This requires a one-time external setup step** (creating the Sonar project, generating a token) that the agent cannot do itself — flag it the same way as the Google Cloud Console and Hostinger SMTP setup steps.
- **Snyk** for dependency vulnerability scanning — specifically the Composer dependency tree (`google/apiclient`, `phpmailer/phpmailer`, `altcha-org/altcha`, and `sabre/dav`/`sabre/vobject` once phase 2 CalDAV work starts), since a scheduling tool handling OAuth tokens and PII is exactly the kind of target where an unpatched dependency CVE matters. Also **requires a one-time external setup step** (Snyk account, API token) to flag alongside the others.
- **Where these run:** if the codebase lives in a git repository with CI available (e.g. GitHub Actions, given the project's likely home on GitHub), wire both into a simple pipeline — Snyk test and a Sonar scan on every push/PR, failing the build on new high/critical findings. If no CI pipeline exists yet, both should still be run manually before each phase's completion and certainly before Phase 7 go-live — don't treat "no CI yet" as a reason to skip scanning entirely, just as a reason it's a manual step for now rather than automated.
- **Baseline expectation for go-live (Phase 7):** no unaddressed Snyk high/critical findings in the dependency tree, and Sonar's quality gate (or a manual review of its findings, if no formal gate is configured) passing before the site goes live on `www.meertec.ltd`.

---

## 5. Data Model (suggested tables)

Every table below carries a `tenant_id` foreign key (see 1.4), even though v1 only ever populates one tenant. Every query in every `*_lib.php` module scopes by it — treat "add `WHERE tenant_id = ?`" as non-negotiable on every read/write, not an optional hardening pass. **The migration script (`db/eratotime_migration.sql`) is authoritative over this section** — if the SQL and this section ever disagree, the SQL wins and this section should be fixed.

- `tenants` — id, slug, display_name, active, created_at. Exactly one seeded row in v1.
- `tenant_admins` — id, tenant_id, username, password_hash. Exactly one row in v1; the admin panel still authenticates against this tenant's single row rather than a general login system (see 1.4 for what's deferred).
- `meeting_types` — id, tenant_id, slug, name, description, duration_min, location_details (text — phone/video link/address), buffer_before_min, buffer_after_min, min_notice_hours (default 24), max_horizon_days (default 14), daily_cap (per-type), active, sort_order.
- `global_settings` — id, tenant_id, organizer bio, organizer photo path, global daily cap, global weekly cap, whatsapp_enabled (bool), whatsapp destination number, mailbox_destination (`stephen@meertec.ltd`), organizer timezone, request_hold_hours (default 24 — soft-hold window, 2.3), request_log_retention_days (default 30). One row per tenant (one row total in v1).
- `meeting_type_questions` — id, meeting_type_id, label, type (text/textarea/select), required, sort_order. (Scoped indirectly via `meeting_type_id` → `tenant_id`; no separate `tenant_id` needed here provided every query joins through the parent.)
- `working_hours` — id, tenant_id, day_of_week, start_time, end_time.
- `availability_overrides` — id, tenant_id, date, is_blocked (bool), start_time (nullable), end_time (nullable), note.
- `calendar_sources` — id, tenant_id, provider (google/microsoft/caldav/ics), label, credentials_encrypted, calendar_identifier (e.g. Google calendar ID), ics_url (nullable), active, last_synced_at, last_sync_status, last_sync_error.
- `calendar_blockouts` — id, calendar_source_id, external_uid, start_utc, end_utc, synced_at. (Scoped indirectly via `calendar_source_id` → `tenant_id`.) Unique on `(calendar_source_id, external_uid)` for idempotent sync.
- `request_log` — id, tenant_id, meeting_type_id, invitee_name, invitee_email, guest_emails (JSON array, optional), custom_answers (JSON), requested_start_utc, requested_end_utc, status (`pending`/`fulfilled`/`cancelled`/`expired`), soft_hold_expires_at, sent_at (created), updated_at. **This is the authoritative local record** — the soft-hold it represents is what keeps a slot from double-booking until the organizer acts (2.3/2.4). Unique on `(tenant_id, requested_start_utc, invitee_email)` as the duplicate-submission guard (4.2). Pending rows with `soft_hold_expires_at` in the past are treated as non-blocking by the availability engine (the row may still show as `expired` in the admin log).
- `notification_outbox` — id, tenant_id, request_log_id, channel (`email`/`whatsapp`), recipient (email address or phone number), status (`pending`/`sent`/`failed`), attempts, last_attempt_at, next_retry_at, last_error (text, nullable). One row per channel per request (4.5) — decouples the durable request write from the retryable notification send.
- `activity_log` — id, tenant_id, event_type, detail (JSON/text), created_at.

## 6. Suggested File/Module Structure

Follow the same conventions as the existing PHP/JS project on this account (small focused `*_lib.php` modules, `config.php` for shared config, ES module frontend files) rather than introducing a new framework:

```
/config.php                  — DB creds, encryption key path, admin auth bootstrap, ALTCHA HMAC key
/tenant_lib.php              — resolves tenant from the URL path (/t/{tenant-slug}/...), loads that tenant's global_settings; every other module receives/uses tenant_id from here rather than re-deriving it
/availability_lib.php        — layered availability computation (template → overrides → blockouts → soft-holds → buffers), slot generation, cap/min-notice/max-horizon gating — pure and unit-testable against synthetic data, all scoped by tenant_id
/request_lib.php             — request submission (transaction: re-check, write request_log soft-hold, queue notification_outbox), fulfilment/cancellation status changes — all scoped by tenant_id
/calendar_sync_lib.php       — orchestrates per-provider sync, writes calendar_blockouts (Google only in v1) — iterates active calendar_sources across all tenants, each still fully isolated
/security_lib.php            — per-IP file-cache rate limiting, manual CSRF verification, honeypot + ALTCHA challenge/verify (borrowed/improved from EA)
/providers/google_provider.php        — v1: read-only busy-block fetch (events.list), OAuth token handling
/providers/microsoft_provider.php     — phase 2
/providers/caldav_provider.php        — phase 2
/notify_lib.php              — composes/sends emails via PHPMailer/SMTP (invitee confirmation, organizer new-request with Google Calendar quick-add link) and, if enabled, the WhatsApp message via CallMeBot/TextMeBot; read from notification_outbox
/admin.php                   — admin panel entry point, incl. request log and fulfil/cancel actions — operates on the single admin's own tenant only
/api/*.php                   — AJAX endpoints (slots, request-submit, admin grid, sync-now, request-log actions), all tenant-scoped via tenant_lib.php
/cron/sync_calendars.php     — cron entry point, loops calendar_sources across all tenants, calls calendar_sync_lib per source
/cron/retry_notifications.php — cron entry point retrying `pending` notification_outbox rows with backoff, marking `failed` past the retry window (4.5)
/cron/cleanup.php            — cron entry point purging request_log rows past retention and sweeping expired soft-holds (4.5)
/js/booking.js /calendar.js /admin.js   — ES module frontend, mirroring existing app's module split
/js/embed-resize.js          — small standalone script: postMessage height handshake for iframe embedding (2.7); loaded on the booking page, and the snippet given to the business site listens for it
/uploads/{tenant_id}/organizer-photo.*  — organizer photo, per tenant, served directly, referenced by global_settings
/vendor/                     — Composer deps (google/apiclient, phpmailer/phpmailer, altcha-org/altcha; Sabre/VObject added in phase 2)
```

Public booking URLs are path-scoped by tenant: `/t/{tenant-slug}/book/{meeting-type-slug}` (see 1.4 for why path-based over subdomain-based, for now).

## 7. API Endpoints (suggested)

- `GET /api/slots.php?type={slug}&month={yyyy-mm}` — available dates for a month.
- `GET /api/slots.php?type={slug}&date={yyyy-mm-dd}` — available time slots for a day.
- `POST /api/requests.php` — validate availability (re-check inside transaction), write request_log (soft-hold), queue notification_outbox rows, return confirmation details. Rate-limited, ALTCHA-verified, honeypot-checked, CSRF-verified (4.2).
- `POST /api/admin/sync_now.php` — trigger immediate sync for one or all calendar sources (admin-auth only).
- `GET /api/admin/availability_grid.php?week={yyyy-mm-dd}` — returns the merged grid data for a given week: recurring template state, any date-specific overrides for that week, read-only calendar busy blocks, and read-only pending soft-holds, per cell (admin-auth only).
- `POST /api/admin/availability_grid.php` — save cell toggles from the grid editor; body indicates whether the edit targets the recurring template or a date-specific override for the viewed week (admin-auth only).
- `GET /api/admin/requests.php?status={pending}` — request log listing (admin-auth only).
- `POST /api/admin/requests.php` — action on a request: `mark_fulfilled` or `mark_cancelled` (admin-auth only; this is the organizer's 2.4 workflow action).
- Admin CRUD endpoints for meeting types and calendar sources (admin-auth only).

## 8. Testing Requirements

- **Tenant isolation is the test-first priority, written before any other feature test** — cheap to write now while there's exactly one tenant (1.5), and the classic thing that's expensive to retrofit once there's real second-tenant data to accidentally leak. The test: seed two tenants with overlapping meeting-type slugs, calendar sources, and request entries, then confirm a query scoped to tenant A never returns a row belonging to tenant B, across every table in section 5 — not just the obvious ones. Write this before the availability engine test below, not after.
- Unit-testable availability engine: given a set of working hours, overrides, blockouts, soft-holds, and buffer rules, correct slot generation is the single most important thing to get right — write it so it can be tested with synthetic blockout data without needing live OAuth connections. **Include regression tests for the interval-subtraction bugs found in Easy!Appointments** (Appendix B): an event *fully contained inside* a candidate slot, back-to-back events, events spanning the workday boundary, all-day blocks, and buffer edges.
- Manual test matrix before go-live: booking across a DST transition date; booking at the edge of min-notice/max-horizon; **two near-simultaneous submissions for the same slot (the soft-hold + transaction guard prevents double-booking)**; **a second submission for the same slot while a request is still pending (blocked by the soft-hold)**; **a pending request's soft-hold expiring and the slot reopening**; a calendar source going stale/failing sync (confirm it fails closed, not open); the confirmation email and the organizer notification email arrive reliably via the SMTP/PHPMailer path rather than landing in spam; **the Gmail address appears nowhere in any app-sent email**; **the Google Calendar quick-add link in the organizer email is correct** (right times, right invitee, right location); an event the organizer creates in the synced calendar genuinely reflects back into the next sync and blocks the slot; the ALTCHA widget blocks a scripted submission without a valid solution; the per-IP rate limit trips.
- Local dev/testing via PHP's built-in server as usual; Google's OAuth flow will need a reachable HTTPS callback URL even in dev, so either use a tunnel (ngrok or similar) or test OAuth against a staging subdomain on the live Hostinger account rather than pure localhost.

## 9. Future Enhancements (out of scope for v1)

- **Multiple organizers / round-robin assignment within one tenant** — a genuinely different feature from the multi-tenancy in 1.4: this is pooled availability across several people *for one tenant*, where a request goes to whichever organizer is free (Calendly's team-scheduling mode). Not requested, not built — kept here only to distinguish it clearly from the isolated-tenants model actually being built (1.4), since the two are easy to conflate.
- **Full self-serve multi-tenant platform**: sign-up/onboarding flow, real per-tenant authentication and session management (replacing the single shared passphrase), a tenant-facing "connect your Google Calendar" OAuth flow, billing/quotas, and a super-admin view across tenants. The schema is ready for this (1.4) but none of the surrounding product is built in v1 — this is a separate, larger phase to scope properly if/when a second real tenant shows up, not something to build speculatively for a tenant count of one.
- **Self-service cancellation/reschedule for invitees** — v1 deliberately has no tokenized links or calendar-write path (2.4). If it's ever wanted, it needs the app to own events again (direct creation) or to send its own iTIP invites — which is exactly the v1 architecture that created the identity problem in 1.6. So this is not a "add a button" feature; it reopens the calendar-of-record decision. Noted here, not built.
- Payment collection (Stripe) at booking time for paid consultation slots.
- **Personalized/single-use booking links** (e.g. a link tied to one specific prospect rather than the general public page) — noted here as a possibility, deliberately **not specified further**: this is likely to be implemented differently from Calendly's version of the feature once it's actually needed, so there's no fixed design to build against yet. Do not build this speculatively.

## Appendix A — Organizer Identity and the Calendar-of-Record Decision (open)

Section 1.6 defines the central tension: **face the client as `stephen@meertec.ltd` while doing calendar management on `meertec.ltd@gmail.com`.** v2's request-submission model removes the app from the identity problem — the app never creates events, so it never shows `meertec.ltd@gmail.com` to an invitee, and every email it sends comes from `stephen@meertec.ltd`.

What remains open is the **calendar-of-record**: where the organizer *manually* creates the events, because that is what the invitee sees on their calendar invite, and because the created events must be visible to the availability engine (via a synced source or manual grid-blocking) to avoid double-booking. The options:

**Option 1 — Google Workspace with `stephen@meertec.ltd` as a genuine primary account.** Move the connected calendar off free Gmail entirely and onto a real Google Workspace account where `stephen@meertec.ltd` is the actual primary address. The organizer creates events in the Workspace calendar (which is also the synced source), invites show `stephen@meertec.ltd`, and everything is consistent. Costs a recurring Workspace subscription. If adopted, Phase 3's OAuth connects against the Workspace account instead of `meertec.ltd@gmail.com`. (Note: Microsoft 365 Family was checked and ruled out — custom domains are no longer supported on the Family tier since November 2023 — so Workspace or 365 Business are the only paid single-identity routes.)

**Option 2 — Accept `meertec.ltd@gmail.com` as the event-creation calendar.** The organizer keeps creating events in the existing Gmail calendar. Free, zero setup, sync stays trivially accurate (it's already the synced source), and the app never leaks the address. The cost is that the invitee's calendar invite shows `meertec.ltd@gmail.com` as organizer — the identity that v1's "accepted tradeoff" accepted, now visible only on events the organizer personally creates (mitigated by the request/confirmation emails and booking page all carrying `stephen@meertec.ltd`).

**Option 3 — Self-hosted CalDAV server (Baïkal) as the calendar of record, under `stephen@meertec.ltd`.** Baïkal is a lightweight, self-hosted CalDAV/CardDAV server — PHP + SQLite/MySQL, runs on shared hosting. It has a configurable `invite_from` setting that sends genuine calendar invitations under whatever address you configure. This is the only option that satisfies the identity requirement with zero recurring cost, but the calendar isn't natively visible in Gmail/Google Calendar apps — day-to-day viewing needs a CalDAV-compatible client (Thunderbird, Apple Calendar, DAVx⁵ on Android). Under this option, Phase 3's sync source is the Baïkal calendar (via the phase-2 CalDAV provider path, pulled forward) rather than Google.

**Decision gate:** this does **not** need to be resolved for Phases 0–2. It **must** be resolved **before Phase 3 starts**, because Phase 3's deliverable is the sync source that keeps availability accurate — and Option 1/3 change which account/server OAuth or CalDAV connects to. Until then, the v1 default is Option 2 (`meertec.ltd@gmail.com` as sync source and de facto event-creation calendar), with the operational rule from 1.6 in force: events must land in a synced source or be manually blocked.

**Why v1's "accepted tradeoff" (from the old spec) is retired:** v1 had the app create events directly in `meertec.ltd@gmail.com` and asked the organizer to accept the Gmail address showing as organizer, with an appendix of fix-options (Workspace, own iTIP, Baïkal). The request-submission model achieves the client-facing identity goal more simply — by never creating events in the app at all — and reduces the remaining identity exposure to a decision the organizer makes at event-creation time, which is exactly where it belongs.

## Appendix B — Build vs. Adopt + Easy!Appointments Review

**Decision: build custom — confirmed.** Easy!Appointments (1.6.0, GPL-3.0) was re-evaluated against the current requirements and is **not** a fit:
- It is architected for a **whole workforce** (providers, secretaries, admins, roles/permissions, multiple services, multiple calendars per provider) — far more sophisticated than this single-organizer request tool needs.
- Its core model is **direct appointment creation with calendar write-back** (`events.insert`, OAuth write scope, two-way sync, `.ics` invites) — the exact v1 model we have retired because it creates the identity problem (1.6). Adopting it would mean un-building its central feature.
- Its calendar sync has known sharp edges worth avoiding (below).
- What we'd lose: the tenant-ready schema, the WhatsApp/CallMeBot pattern proven elsewhere on this account, the five-layer availability grid, and the Gmail-address-never-leaks guarantee.

**Learning points from the Easy!Appointments source review (incorporated above, kept here for the record):**

*Adopted:*
- **Availability = "intervals minus obstacles".** Build free intervals from the weekly plan, subtract every blocking source, then fit the service duration onto a slot grid (`Availability.php`). Our layered model (2.2) follows this shape.
- **Timezone-naive schedule data, real timestamps only at the "still bookable now" gate** — makes slot math DST-proof by construction.
- **Exception-as-day-off encoding** via nullable times — one table covers both "open with custom hours" and "closed".
- **Server-side availability re-check at submit time** (anti-double-booking race guard) — we harden it with a transaction + unique index because EA's own check-then-insert is racy (acknowledged in their code).
- **Per-IP file-cache rate limiting** (no Redis on shared hosting); **manual CSRF via `hash_equals`**; **field whitelisting** (`array_intersect_key`); **server-side recomputation of all times**; **PHPMailer with HTML + plain-text AltBody**; **per-recipient try/catch notification fan-out**.
- **ALTCHA proof-of-work captcha** (already a Composer package in EA's `vendor/`) as the invisible anti-bot layer — with the fix for EA's HMAC-key footgun (persist the key at install).
- **OAuth pattern**: `access_type=offline` + `prompt=consent` + `max_auth_age=0`, state verified with `hash_equals`, popup completion via `postMessage('oauth_success')`, 401 handled as a re-connect signal.
- **Sync idempotency**: pre-fetch and reconcile rather than blind re-insert; explicit pagination bound on `events.list` with a warning log; all-day event handling with exclusive `+1 day` end.
- **Cleanup cron** for retention-based purging (EA: 90-day storage/customer purge; ours: 30-day request-log purge).

*Deliberately NOT copied (pitfalls to avoid):*
- **Plaintext OAuth token storage** (`user_settings.google_token` is a plaintext JSON blob) → we encrypt at rest (4.2).
- **`verify => false` on the HTTP client unconditionally** → verification on by default, documented fallback only (4.1).
- **The interval-split bugs** — append-while-iterating arrays, overlap SQL that misses fully-contained events, and the `is_entire_date_blocked` off-by-one → we use sorted-merge interval subtraction and add regression tests (section 8).
- **No buffer/gap concept** in EA → we have per-type buffers in the schema (2.1).
- **Blocking, synchronous sync with no quota budgeting** → AJAX-driven flow with progress indicator, time-bounded (4.1).
- **Hard-coded summary-string matching** for event reconciliation → we key on Google's stable event ID.

---

## 10. Phased Build Plan (for handoff across sessions/agents)

This section exists because different phases may be built by different LLM sessions or agents, not one continuous session with shared memory. **Every phase below is self-contained**: it states what must already be true (Prerequisites), what to build (Deliverables), how to know it's actually finished (Definition of Done), what to deliberately not touch (Out of Scope for This Phase), and what to write down for whoever picks up next (Handoff Notes). An agent starting mid-plan should be able to read just that phase's block plus sections 1–9 for context, without needing a transcript of earlier sessions.

**General handoff rule for every phase:** before finishing, write a short `PHASE_N_NOTES.md` (or append to a running `BUILD_LOG.md`) recording: what was actually built (vs. what was planned, if they diverged), any deviations from this spec and why, exact config/credential locations touched (not values), and anything the next phase's agent should know that isn't already obvious from the code. This is the substitute for shared memory between sessions.

### Phase 0 — Environment & Scaffolding
- **Prerequisites:** none — this is the starting point. Real seed values from 1.5 available (domain, email addresses).
- **Deliverables:** a dedicated MySQL database for Eratotime (own schema/name and DB user, per 4.1 — not shared with any other Meertec app's tables), full file/folder structure per section 6; all tables in section 5 created via the migration script inside that database; the single seeded tenant row populated with the real values from 1.5; `config.php` wired to the database with connection details self-contained (no assumptions baked in about co-location with other Meertec databases) and the encryption key + ALTCHA HMAC key generated and stored outside the web root; Composer initialized with `google/apiclient`, `phpmailer/phpmailer`, and `altcha-org/altcha` vendored or installed per whatever method 4.1 determines works on this Hostinger plan.
- **Definition of Done:** a test script can connect to the dedicated database, the seeded tenant row exists and is readable, and `tenant_lib.php` correctly resolves that tenant from a test request path. No query anywhere in the codebase references another database by name.
- **Out of scope for this phase:** no availability logic, no calendar integration, no UI beyond a bare confirmation the scaffold works. Resist building ahead into Phase 1/2 territory.
- **Handoff notes to record:** which Composer install method actually worked on Hostinger (SSH vs. vendor-and-commit); the final DDL for every table, since later phases should treat this as authoritative over the suggested schema in section 5 if anything had to change; the actual database name/user created, and the Hostinger plan's database count limit if it was checked (4.1).

### Phase 1 — Tenant Isolation Test (test-first, before any feature)
- **Prerequisites:** Phase 0 complete — schema exists, one real tenant seeded.
- **Deliverables:** a second, throwaway tenant seeded with deliberately overlapping data (same meeting-type slugs, similar calendar source labels); an automated test suite proving no query scoped to the real tenant ever returns the throwaway tenant's rows, and vice versa, across every table in section 5.
- **Definition of Done:** the isolation test suite passes and is committed as a permanent regression check — every subsequent phase should keep it passing, not just pass it once and forget it.
- **Out of scope for this phase:** don't build any user-facing feature yet; don't touch the real tenant's data as part of testing (use the throwaway tenant only).
- **Handoff notes to record:** confirm the throwaway tenant is either clearly marked as a permanent test fixture or fully removed — leaving an ambiguous half-real second tenant in the database would confuse Phase 6's admin panel later.

### Phase 2 — Availability Engine (core logic, no external integration)
- **Prerequisites:** Phases 0–1 complete.
- **Deliverables:** `availability_lib.php`'s layered availability computation (2.2) — template → overrides → simulated blockouts → simulated soft-holds → buffers — built and tested entirely against synthetic fixture data. No live Google connection required or used in this phase.
- **Definition of Done:** given fixture inputs, the function returns the correct slot list for: a plain week, a week with overrides, a week crossing a DST transition, edges of min-notice/max-horizon, buffer subtraction, a pending soft-hold blocking its interval, an expired soft-hold freeing its interval, and the interval-subtraction regression cases from section 8 (fully-contained event, back-to-back events, all-day block, workday-boundary spans). These are the tests named in section 8.
- **Out of scope for this phase:** no real Google Calendar API calls, no UI, no email/notification code.
- **Handoff notes to record:** the exact function signature/interface for the availability computation, so Phase 3 can wire real blockout data into it without changing the interface itself.

### Phase 3 — Google Calendar Read-Only Sync (OAuth + Sync)
- **Prerequisites:** Phases 0–2 complete; **the calendar-of-record decision (1.6 / Appendix A) resolved — this is the gate**; Google Cloud Console project, Calendar API enabled, and OAuth consent screen already configured (external manual step — if this hasn't been done, flag it and stop rather than guessing at credentials).
- **Deliverables:** the OAuth2 consent flow (read-only scope only) against whichever calendar-of-record account 1.6 resolved to (default: `meertec.ltd@gmail.com`); encrypted token storage; `providers/google_provider.php` (read-only busy-block fetch via `events.list`); `cron/sync_calendars.php`; populated `calendar_sources`/`calendar_blockouts` rows reflecting that real account's busy time. **No write scope, no event creation, no `.ics` — verify this in the review.**
- **Definition of Done:** manually trigger a sync and confirm the resulting blockouts genuinely match that calendar's real busy times; an event added to the synced calendar appears as a blockout on the next sync; an event removed from the calendar stops blocking on the next sync (idempotent upsert/delete by `external_uid`); the fail-closed-after-N-hours-stale behaviour (3.5) is tested and works.
- **Out of scope for this phase:** Microsoft/CalDAV integration (phase 2 *feature*, not this build phase — don't conflate the two "phase 2"s); no booking page, no request submission, no email sending.
- **Handoff notes to record:** where encrypted credentials live and how to rotate them if needed; whether the OAuth consent screen is in Google's "testing" or "production" publishing status, since testing-mode tokens can expire/require re-consent sooner; the calendar-of-record decision actually made.

### Phase 4 — Public Booking Page
- **Prerequisites:** Phases 0–3 complete — real calendar sync working.
- **Deliverables:** the `/t/{slug}/book/{meeting-type-slug}` page (2.3): organizer bio/photo, read-only date/slot picker, timezone detection, booking form with the ALTCHA widget, honeypot, and the iframe-embedding groundwork (2.7) including the `embed-resize.js` postMessage handshake. Plus the `GET /api/slots.php` endpoints. **No submission yet** — this phase renders availability only.
- **Definition of Done:** the page shows the correct open slots for the real tenant's availability; unavailable dates are greyed out; the page embeds cleanly in a test iframe with correct resizing.
- **Out of scope for this phase:** no request submission endpoint, no email sending.
- **Handoff notes to record:** the actual routing/templating approach used, so Phase 5 can hook the submission endpoint into the same flow without restructuring it.

### Phase 5 — Request Submission Flow
- **Prerequisites:** Phase 4 complete.
- **Deliverables:** `POST /api/requests.php` (2.3/4.2): transaction-wrapped availability re-check, `request_log` write with soft-hold, `notification_outbox` queueing; the security layer (per-IP rate limiting, ALTCHA verification, honeypot, CSRF) from `security_lib.php`; `notify_lib.php` (invitee confirmation email, organizer new-request email with the Google Calendar quick-add link, optional WhatsApp); `cron/retry_notifications.php`.
- **Definition of Done:** a full round-trip manual test — submit a real request, confirm both emails arrive (and the WhatsApp message if enabled), confirm the soft-hold blocks the slot from a second submission, confirm the Gmail address appears nowhere in any email or the booking page, confirm the quick-add link in the organizer email is correct, and confirm the slot frees up when the soft-hold expires.
- **Out of scope for this phase:** no admin fulfil/cancel actions yet — just submission and notification.
- **Handoff notes to record:** the actual request-submission transaction approach used, so Phase 6 can hook the fulfil/cancel actions into the same flow without restructuring it.

### Phase 6 — Admin Panel
- **Prerequisites:** Phases 4–5 complete.
- **Deliverables:** the admin panel (2.6) with meeting type CRUD, the four-state weekly grid editor (template / overrides / read-only busy / read-only soft-holds), calendar connection status and manual sync button, the request log with **mark fulfilled / mark cancelled** actions, the failed-notification warning, and the organizer profile editor.
- **Definition of Done:** the organizer can fully configure the real tenant's availability, meeting types, and profile with no direct database access needed; the fulfil action on a request correctly ends its soft-hold blocking and is reflected in the grid; the cancel action frees the slot immediately; a failing notification channel surfaces the dashboard warning.
- **Out of scope for this phase:** no multi-tenant admin UI or tenant switcher — single tenant only, per 1.4.
- **Handoff notes to record:** the actual admin auth mechanism implemented, for whoever eventually revisits per-tenant auth in the future enhancements.

### Phase 7 — Cron Hardening & Go-Live Checklist
- **Prerequisites:** Phases 0–6 complete — a fully functional single-tenant app, tested only manually/locally so far.
- **Deliverables:** `cron/sync_calendars.php`, `cron/retry_notifications.php`, and `cron/cleanup.php` actually scheduled on Hostinger (not just written); the security checklist in 4.2 verified item by item; the full manual test matrix in section 8 executed and passing; the booking page confirmed live and iframe-embeddable on `www.meertec.ltd` itself, not just a local test harness.
- **Definition of Done:** the site is genuinely live, reachable at the real domain, HTTPS-enforced, cron jobs firing on schedule, and every item in section 8's manual test matrix has been checked off against the real deployment.
- **Out of scope for this phase:** anything in section 9 (Microsoft/CalDAV, self-service cancellation, full multi-tenant platform) — this phase is go-live for v1 as scoped, not an opportunity to pull future enhancements forward.
- **Handoff notes to record:** a final `BUILD_LOG.md` summary of the whole build, since this is the natural point where the project moves from "being built" to "being maintained," and whoever picks up section 9 work later will want the full history in one place rather than seven separate phase notes.

---

- Confirm the assumptions in section 1.3 before writing code; don't silently choose different defaults.
- Follow the phased build plan in section 10 rather than improvising a different sequence — it defines prerequisites, deliverables, and definition-of-done per phase specifically so work can hand off cleanly between sessions or different LLMs.
- Prefer providing complete replacement files over diffs/patches when editing existing files, matching this project owner's established working style.
- Flag clearly, at each stage, anything that requires manual setup outside the codebase (Google Cloud Console OAuth app + consent screen, Hostinger SMTP credentials for PHPMailer, CallMeBot/TextMeBot API key for WhatsApp, Hostinger cron job configuration, Composer install method) — these are one-time external setup steps the agent cannot do itself.
- Treat `tenant_id` scoping (1.4/5) as mandatory on every query from the very first schema migration, not as something to add once a second tenant actually shows up — retrofitting it later is real, avoidable risk.
- **Treat the Gmail address (`meertec.ltd@gmail.com`) as invisible-to-invitees by construction**: it appears only in the admin panel as the sync-account label, never in any email, page, or link the app produces (1.3, 2.5, Phase 5 DoD).
- Apply the "Eratotime" branding (title tags, admin header, email sender name) per the naming note at the top of this document — a cosmetic layer on the existing file/table structure, not a reason to rename internals.
