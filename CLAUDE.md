# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Single-file web app (`index.html`) for EEIS Al-Kauthar Saturday Madrasah, Epsom. Manages 122 students, 17 staff, fees, waiting list, medical records, communications and calendar. Hosted as a static HTML file on Hostinger, backed by Google Sheets as the database via Google Apps Script.

## Critical Constants — Never Change

```
SPREADSHEET_ID   = '1VCpochQycYldeN_f-W-mi-2AYDuevFbwWbnp9VOR800'
SOURCE_SHEET_ID  = '1zNnrvvd2PN_XhsZ6B701s5dEWwk_Lwhe8jRKawb4W_U'
GOOGLE_CLIENT_ID = '857597034835-66scr2u1den7dmf7bpqopqv2lhph6nco.apps.googleusercontent.com'
APPS_SCRIPT_URL  = 'https://script.google.com/macros/s/AKfycbzHTcn_eUQgVMgFQXJE5a3cg3U1jWkNwWacUbgWxn_lOsY8OWEXJ4ztBeQB_0j_7WOZWQ/exec'
PAYMENT_URL      = 'https://eeis.sumupstore.com/'
WAITINGLIST_FORM = 'https://docs.google.com/forms/d/1ftjkDMLPXMJCVJpSUvCt1k8aVCBhrhKg_0y5YVuGjws'
LIVE_URL         = 'https://madrasah.eeis.store'
GITHUB_REPO      = 'https://github.com/madrasah-del/EEIS-Student-Dashboard-May-26'
```

## Agile Development Workflow — IMPORTANT

This project follows **agile, checkpoint-based development**. Every significant sprint must end with a commit and push to GitHub so the live site can be tested before the next sprint begins.

**Rules:**
- Break all work into small, testable checkpoints — commit after each one
- Never batch more than one major feature area into a single commit
- Always push to GitHub and wait for confirmation that the live site works before continuing
- If a session is approaching its token limit, stop, commit what's done, and update CLAUDE.md + the plan file before stopping
- Prefer small, safe changes over large rewrites — use the sprint override pattern (`const _orig = fn; fn = function() {...}`) to extend rather than replace

**Deployment (after every push):**
Hostinger auto-deploy is now configured via GitHub webhook. Once pushed, the live site at `madrasah.eeis.store` updates within 60 seconds automatically.

**Manual fallback:**
Hostinger hPanel → Git → Deploy (if webhook fails)

## Architecture

### Single-File Stack
The entire app lives in one `index.html` (~7400 lines) — HTML, CSS, and JavaScript are all inline. There is no build step, no bundler, no package manager, and no dependencies to install.

### Data Layer
- **Google Sheets** is the single source of truth (never localStorage)
- All reads/writes go through `APPS_SCRIPT_URL` via `fetch()` POST requests with a `tab` parameter
- `localStorage` is used for session and UI cache only
- Sheet tabs: `Students`, `Staff`, `Users`, `Config`, `AuditLog`, `CommunicationsLog`, `MagicLinks`

### Key Data Constraints
- `studentsToRows()` must **never** overwrite Fees Paid with zero — fallback preserves raw field
- Never call `saveDb()` before confirming data is correctly loaded
- `importFromSourceSheet()` in Apps Script restores original student data if corruption occurs
- `s.chaseHistory` (array of chase log entries) is localStorage/cache only — not in Sheets columns. Data persists across refreshes but not cache clears. A Sheets column for this is future work.
- `s.paymentPlan` and `s.paymentPlanNotes` are also localStorage/cache only

### Sprint / Override Pattern
New feature blocks are added at the **end of the file** in tagged `<script>` blocks using the override pattern:
```js
// ══════════ PHASE N: DESCRIPTION ══════════
const _origFn = existingFunction;
existingFunction = function() {
  _origFn.apply(this, arguments);
  // new behaviour
};
```
This preserves backwards compatibility. The existing Sprint blocks are: Sprint 1–6 (pre-May 2026), Phase 4 (May 2026).

### Authentication
- Email login: user types email → validated against approved list → signed in
- Google Sign-In: GSI library with the Client ID above
- Shared account (`madrasah@eeis.co.uk`): shows identity selector (Javeed / Tahmid / Imam Joynal / Sister Daisy)
- Session stored in `localStorage`, persists across refreshes

### Approved Staff
| Name | Email | Role |
|------|-------|------|
| Javeed Joosub | jjoosub@gmail.com | Super-Admin |
| Tahmid Choudhury | tahmid.c96@gmail.com | Editor |
| Imam Joynal | imam@eeis.co.uk | Editor |
| Sister Daisy | halima.miah@ymail.com | Editor |
| EEIS Madrasah | madrasah@eeis.co.uk | Super-Admin (shared) |

## Deployment

Auto-deploy via GitHub webhook → Hostinger. Push to `main` → live within 60 seconds.

File location on server: `public_html/madrasah/index.html`

## Embedded Static Data

Two large constants are baked directly into the HTML — do not move them to Sheets:
- **`WAITING_LIST_DATA`** — 52 waiting list entries
- **`TERM_DATES`** — 2025-2026 term dates

## Apps Script Key Functions (Code.gs — lives in the Google Sheet)

```javascript
importFromSourceSheet()  // Re-imports 122 students from source sheet — run after data corruption
pushStaffSeed()          // Writes 17 staff records to Staff tab — run if Staff tab is empty
initialSetup()           // Creates all sheet tabs if missing
doGet(e)                 // Serves HTML, handles ?tab= routing
doPost(e)                // Reads/writes any tab by name
```

## Phase Build Status (May 2026)

### ✅ Completed
- **Phase 1**: Auth, Sheets connection, session, identity selector
- **Phase 2**: Mobile-first CSS, font sizes (xs:13px, sm:15px, md:17px, lg:21px), touch targets
- **Phase 3 (partial)**: Phone tappable → Call/WA popup, email tappable → mailto:, comm log summary on Info tab, payment methods standardised
- **Phase 4**: Chase history system (`chaseHistory` array + `pushChaseEntry`/`getLastChase`/`renderChaseHistory`), compact family debt cards, sort by debt/least-contacted, payment plan protection, multi-select mass email, per-family WhatsApp/Email/Log Contact, ? Help overlay, GSI error callback
- **Phase 5 (May 2026)**:
  - Dashboard stats persist across app reopen (stat snapshot + post-init re-render)
  - Cache written on `pagehide` + `visibilitychange` (survives app close)
  - 🎓 Graduation tab: boys/girls grouped with divider, gender filter, "Studying" duration column
  - Student modal: "📅 Xy Xm with us" chip from Start Date
  - Individual debt view: Chases column (dot + count), sort by least-contacted
  - Medical: larger font (13px), sorted critical-first
  - Waiting list: Email button replaces Copy Msg
  - Calendar: next-event countdown in weeks+days

### 🔲 Planned Sprints (Phase 6)

| Sprint | Feature | Status |
|--------|---------|--------|
| A | Fix green dot persisting after chase log delete | Ready |
| B | Sibling detection + waiting-list cross-reference in student modal | Ready |
| C | Family contact consistency audit + missing-info audit | Ready |
| D | Staff tab: compact cards + click-to-detail modal | Ready |
| E | Calendar: auto-scroll to current week, August month, bigger chips | Ready |
| F | WhatsApp group comparison tool (manual paste-and-compare) | Ready |
| G | Waiting list live sync from Sheets | Needs Apps Script change |
| H | Excel migration sync | Deferred (needs scoping) |

## Known Issues / Next Fix

- **Green dot bug**: chase dot shows for families whose log was deleted — `lastChased` legacy fallback still triggers (Sprint A)
- **Staff cards** still large (full attendance tracker visible) — compact redesign is Sprint D
- `chaseHistory` not persisted to Sheets (localStorage only) — Sheets column needed in future
- Google OAuth `origin_mismatch` if `madrasah.eeis.store` not added to Cloud Console
- Staff data falls back to seed (`pushStaffSeed()`) if the Staff sheet tab is empty

## Data Model Notes (Phase 5+)

- `s.chaseHistory` — cache/localStorage only (NOT in Sheets). Array of `{date, note, method, sentBy, template}`. Survives refresh, lost on cache clear.
- `s.paymentPlan` / `s.paymentPlanNotes` — cache only
- **Stat snapshot** (`eeis_stats_snap_v1`) — localStorage; stores last rendered dashboard card values for instant display on reopen
- **Two chase-tracking systems coexist**: `chaseHistory` (current, per-student array) and `commLog` (legacy, per-family localStorage object). Phase 4 reads `chaseHistory`; old code falls back to `commLog.lastChased`. Both are kept in sync when logging new chases.

## Google Cloud Console Notes

- Project name: **EEIS Dashboard**
- Authorised origin must include `https://madrasah.eeis.store`
- OAuth consent screen must be **External**, not Internal
- All 5 approved emails must be added as test users
