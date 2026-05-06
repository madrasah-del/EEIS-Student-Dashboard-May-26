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

## Architecture

### Single-File Stack
The entire app lives in one `index.html` — HTML, CSS, and JavaScript are all inline. There is no build step, no bundler, no package manager, and no dependencies to install.

### Data Layer
- **Google Sheets** is the single source of truth (never localStorage)
- All reads/writes go through `APPS_SCRIPT_URL` via `fetch()` POST requests with a `tab` parameter
- `localStorage` is used for session caching only
- Sheet tabs: `Students`, `Staff`, `Users`, `Config`, `AuditLog`, `CommunicationsLog`, `MagicLinks`

### Key Data Constraints
- `studentsToRows()` must **never** overwrite Fees Paid with zero — there is a fallback to preserve the raw field value
- Never call `saveDb()` before confirming data is correctly loaded
- `importFromSourceSheet()` in Apps Script restores original student data from the source sheet if corruption occurs

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

## Deployment Workflow

There is no CI test suite. Deployment is manual:

1. Edit `index.html` in this repo (or on GitHub directly)
2. Commit to `main`
3. Hostinger hPanel → Git → Deploy
4. Live at `madrasah.eeis.store` within ~60 seconds

File location on server: `public_html/madrasah/index.html`

## Embedded Static Data

Two large constants are baked directly into the HTML — do not move them to Sheets:

- **`WAITING_LIST_DATA`** — 52 waiting list entries with DOB, level, parent contacts, queue positions, sibling/policy flags
- **`TERM_DATES`** — 2025-2026 term dates (T1: 13 Sep–20 Dec 2025 · T2: 10 Jan–14 Feb 2026 · Ramadan closure: 21 Feb–14 Mar 2026 · T3: 21 Mar–18 Jul 2026 · Half term: 23 May 2026 · Jalsa: 18 Jul 2026)

## Apps Script Key Functions (Code.gs — lives in the Google Sheet)

```javascript
importFromSourceSheet()  // Re-imports 122 students from source sheet — run after data corruption
pushStaffSeed()          // Writes 17 staff records to Staff tab — run if Staff tab is empty
initialSetup()           // Creates all sheet tabs if missing
doGet(e)                 // Serves HTML, handles ?tab= routing
doPost(e)                // Reads/writes any tab by name
```

## Google Cloud Console Notes

- Project name: **EEIS Dashboard**
- Authorised origin must include `https://madrasah.eeis.store`
- OAuth consent screen must be **External**, not Internal
- All 5 approved emails must be added as test users

## Known Issues

- Mobile font sizes still too small — headings and card titles need to be bolder and larger
- Google OAuth `origin_mismatch` if `madrasah.eeis.store` not yet added to Cloud Console
- Staff tab data falls back to seed data (`pushStaffSeed()`) if the Staff sheet tab is empty

## Development Phases

- ✅ Phase 1 — Auth, Sheets connection, session, identity selector
- ✅ Phase 2 — Mobile-first CSS, touch targets (font sizes still in progress)
- 🔲 Phase 3 — Student/contact record UX (call/WhatsApp action sheet, communication log on record, payment method dropdown fix)
- 🔲 Phase 4 — Fees & WhatsApp chasing (family view, 3 chase templates, auto-log, payment link cleanup)
- 🔲 Phase 5 — Students tab filters, waiting list level categories, Google Form sync
- 🔲 Phase 6 — Medical severity colours, calendar Today button, notification system, Time Machine backup (30 rolling snapshots), undo last 20 changes
