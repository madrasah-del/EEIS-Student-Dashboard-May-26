# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Single-file web app (`index.html`) for EEIS Al-Kauthar Saturday Madrasah, Epsom. Manages ~122 students, 17 staff, fees, waiting list, medical records, communications and calendar. Hosted as a static HTML file on Hostinger, backed by Google Sheets as the database via Google Apps Script.

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
- If a session is approaching its token limit, stop, commit what's done, and update CLAUDE.md before stopping
- Prefer small, safe changes over large rewrites — use the sprint override pattern (`const _orig = fn; fn = function() {...}`) to extend rather than replace
- **No subagents** — past agent Java bugs caused 4 failed builds; all work done directly

**Deployment (after every push):**
Hostinger auto-deploy via GitHub webhook. Push to `main` → live within 60 seconds automatically.

**Manual fallback:**
Hostinger hPanel → Git → Deploy (if webhook fails)

## Architecture

### Single-File Stack
The entire app lives in one `index.html` (~10,100 lines as of May 2026) — HTML, CSS, and JavaScript are all inline. There is no build step, no bundler, no package manager, and no dependencies to install.

### Data Layer
- **Google Sheets** is the single source of truth (never localStorage for primary data)
- All reads/writes go through `APPS_SCRIPT_URL` via `fetch()` POST requests with a `tab` parameter
- `localStorage` is used for session and UI cache only
- Sheet tabs: `Students`, `Staff`, `Users`, `Config`, `AuditLog`, `CommunicationsLog`, `MagicLinks`

### Key Data Constraints
- `studentsToRows()` must **never** overwrite Fees Paid with zero — fallback preserves raw field
- Never call `saveDb()` before confirming data is correctly loaded
- `importFromSourceSheet()` in Apps Script restores original student data if corruption occurs
- `s.chaseHistory` — localStorage/cache only (NOT in Sheets). Array of `{date, note, method, sentBy, template}`
- `s.paymentPlan` / `s.paymentPlanNotes` — cache only
- `s['Allergy Severity']` — stored in student record, synced to Sheets (added Phase 7)

### Sprint / Override Pattern
New feature blocks are added at the **end of the file** in tagged `<script>` blocks:
```js
// ══════════ PHASE N: DESCRIPTION ══════════
const _origFn = existingFunction;
existingFunction = function() {
  _origFn.apply(this, arguments);
  // new behaviour
};
```
**Current script blocks (in order):**
1. Sprint 1–3 (inline, original build)
2. Sprint 4 — Chase history, debt cards, mass email
3. Sprint 5 — Dashboard stats persist, graduation tab, calendar strip
4. Sprint 6 — Contact audit, WA comparison, staff compact cards, print, auth fix, calendar editor/notifications
5. **Phase 7** — Sprint 7B/C/D/E/F/I (student layout, fee reminder WA, staff, nut reminder, allergy severity, calendar PDF format)
6. **Phase 7 cont.** — Sprint 7A (calendar editor redesign), 7G (EEIS logo), 7H (WL → Enrol)

### Authentication
- Email login: user types email → validated against approved list → signed in
- Google Sign-In: GSI library with the Client ID above
- Shared account (`madrasah@eeis.co.uk`): shows identity selector (Javeed / Tahmid / Imam Joynal / Sister Daisy)
- Session stored in `localStorage`, persists across refreshes
- **Auto-provision**: approved staff are auto-signed-in on first login on a new device (no "no account" error)

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

Three large constants baked directly into the HTML — do not move to Sheets:
- **`WAITING_LIST_DATA`** — 52 waiting list entries (cannot modify at runtime; enrolled status stored in `localStorage.eeis_wl_enrolled_v1`)
- **`TERM_DATES`** — 2025-2026 term dates (`schoolSaturdays`, `events`, `holidays`, `ramadan`)
- **EEIS Logo** — base64-encoded JPEG embedded inline in `#print-header` (no external URL dependency)

### TERM_DATES Important Note — May/June 2026
The user confirmed a swap: **23 May 2026 = class day** (in `schoolSaturdays`), **30 May 2026 = Half Term** (in `holidays`). The original PDF showed the opposite but the app has the corrected dates. Do not revert this.

## Apps Script Key Functions (Code.gs — lives in the Google Sheet)

```javascript
importFromSourceSheet()  // Re-imports students from source sheet — run after data corruption
pushStaffSeed()          // Writes 17 staff records to Staff tab — run if Staff tab is empty
initialSetup()           // Creates all sheet tabs if missing
doGet(e)                 // Serves HTML, handles ?tab= routing
doPost(e)                // Reads/writes any tab by name
```

## localStorage Keys Reference

| Key | Purpose |
|-----|---------|
| `eeis_db_cache` | Student db cache (primary cache) |
| `eeis_staff_cache` | Staff db cache |
| `eeis_users_cache` | Users/auth cache |
| `eeis_stats_snap_v1` | Dashboard stat snapshot for instant display on reopen |
| `eeis_session` | Current user session |
| `eeis_wl_enrolled_v1` | JSON array of `"Firstname|Surname"` keys of enrolled WL students |
| `eeis_wl_reviewed_v1` | Date of last WL sync review |
| `eeis_wl_sync_v1` | WL sync data from Sheets |
| `_CAL_OVERRIDE_KEY` (`eeis_cal_overrides_v1`) | Calendar date overrides (`{date: action_string}`) |
| `_CAL_REMINDERS_KEY` | Calendar reminder dates |
| `eeis_cal_meta_v1` | Extended calendar metadata (labels, commentary for overrides) |

---

## ✅ Complete Build Status (May 2026)

### Phases 1–5 (Pre-May 2026)
- Auth, Sheets connection, Google Sign-In, identity selector for shared account
- Mobile-first CSS, responsive font sizes, touch targets
- Phone tappable (Call/WA popup), email tappable (mailto:), comm log on Info tab
- Chase history system (`chaseHistory` array), compact family debt cards, sort by debt/least-contacted
- Payment plan protection, multi-select mass email
- Dashboard stats persist across app reopen (stat snapshot)
- Graduation tab: boys/girls grouped, studying-duration column, gender filter
- Student modal: "📅 Xy Xm with us" chip from Start Date
- Individual debt view: chase count dot, sort by least-contacted
- Medical tab: critical-first sort, 13px font
- Waiting list: email parent button, boys/girls split per bracket, sync banner

### Phase 6 (May 2026)
- ✅ **Green dot bug fixed** — `??3` nullish fix for classifyAllergy, Array.isArray guard in migrateRecord, sync pass in deleteChaseEntry
- ✅ **Sibling detection** — siblings + WL cross-reference in student modal Info tab
- ✅ **Contact audit tool** — mismatched fields across siblings, missing contact info
- ✅ **Staff compact cards + detail modal** — Info/DBS/Pay tabs, tap to expand
- ✅ **Calendar upgrades** — week strip auto-scroll, August 2026 month, week pill sizing, Next Class label, event week amber border, prev/next scroll buttons
- ✅ **WhatsApp group comparison** — paste numbers, see who's missing/extra, invite button with real group link
- ✅ **Print support** — `@media print`, panel-aware, 🖨 button in header
- ✅ **Auth fix** — approved staff auto-provisioned on first sign-in on any device
- ✅ **Calendar date editor** — Super Admin can add events, override dates, set reminders
- ✅ **Calendar change notifications** — after editing, prompt to email BCC parents or copy WA message

### Phase 7 (May 2026) — All Sprints Complete
- ✅ **7A — Calendar editor redesign** — shows only special/key dates grouped by term (not every Saturday). Each row has inline Edit (label, type, commentary) and Delete (custom events only). Add special date form at bottom. Reminders section.
- ✅ **7B — Student record layout** — sibling names/ages 13px bold, WL sibling bracket/queue 13px bold, gender/age/DOB meta line 15px flex layout, parent contacts condensed to compact 2-line blocks (name + phone + email), modal padding reduced
- ✅ **7C — Payment fee reminder** — SumUp static link replaced with 💬 Mother / 💬 Father / 💬 Both buttons; WA deep link pre-filled with outstanding balance for student + all siblings
- ✅ **7D — Staff enhancements** — Imam Joynal as Headteacher (isHead:true), start date field in staff form + displayed in detail modal, detail overlay positioned near top (not bottom), name 16px bold in compact card, payroll banner shows "Max if all present" stat
- ✅ **7E — Nut allergy reminder** — 🥜 Nut Reminder button on Medical panel; anonymous pre-filled message (no student names sent); staff-only reference list shown inside overlay; Email BCC all parents + Copy for WA
- ✅ **7F — Allergy severity field** — radio buttons (Severe/Moderate/Mild) in Edit form and Add form; stored as `s['Allergy Severity']`; explicit severity wins over keyword detection in `classifyAllergyForStudent(s)` wrapper
- ✅ **7G — EEIS branded print header** — EEIS Rectangle Logo embedded as base64 JPEG inline, maroon school name, no external URL dependency
- ✅ **7H — WL → Enrol student** — 🎓 Enrol button on each WL row (Editor+); full enrolment review form pre-filled from WL data; missing required fields highlighted amber; Class selector required before confirm activates; commits via `db.unshift(ns)` + `saveDb()`; enrolled status stored in `localStorage.eeis_wl_enrolled_v1`; enrolled WL rows show struck-through with ✅ badge
- ✅ **7I — Calendar PDF format** — `_buildCalendarText()` now generates key-dates-only output matching the official PDF style: term headings, `Label ... Saturday Xth Month Year` rows with ordinals, clean footer note. `_buildCalendarEmailBody()` includes EEIS branded text header.
- ✅ **DOB edit form fix** — DOB stored as `DD/MM/YYYY` is converted to `YYYY-MM-DD` for the `<input type="date">` field so it displays correctly in the edit form.
- ✅ **Week pill date format** — changed from ISO `MM-DD` to `DD/MM` (e.g. `17/05`) as requested.
- ✅ **Modal compact parents** — Fixed: `replaceWith()` was destroying original span IDs causing blank modal on 2nd+ open. Now hides original grid and upserts a compact block alongside it, keeping IDs alive.

---

## Known Issues / Future Work

| Item | Status | Notes |
|------|--------|-------|
| `chaseHistory` not in Sheets | Future work | localStorage only; lost on cache clear |
| WL live sync from Sheets | Needs Apps Script | Add `waitinglist` case to `doPost()` in Code.gs |
| Excel migration sync | Deferred | Needs column mapping + SheetJS CDN scoping |
| Google OAuth `origin_mismatch` | If needed | Add `https://madrasah.eeis.store` to Cloud Console authorised origins |
| Staff data falls back to seed | If Sheets tab empty | Run `pushStaffSeed()` from Apps Script editor |
| `chaseHistory` two-system coexistence | Acceptable | `chaseHistory` (current) + `commLog.lastChased` (legacy fallback) both maintained |

---

## Key Architectural Decisions & Learnings

### What Works Well
1. **Single-file approach** — zero deployment friction; no build pipeline to break; editable directly in any text editor
2. **Sprint/override pattern** — appending `<script>` blocks at the end means new code never breaks old code; reversible by removing the block
3. **Google Sheets as DB via Apps Script** — no backend server needed; Sheets is the source of truth; Apps Script `doPost()` handles all CRUD
4. **localStorage for cache** — fast on re-open, gracefully falls back to Sheets fetch if cache is stale
5. **Webhook-based auto-deploy** — push to GitHub → live in 60 seconds; no manual FTP steps

### Known Pitfalls to Avoid
1. **Never `replaceWith()` on elements that contain named IDs** — other code may reference those IDs by `getElementById()` on subsequent calls. Instead, hide with `style.display='none'` and insert a sibling element.
2. **`||` vs `??` for falsy zero** — `classifyAllergy()` returns `0` for critical. Using `|| 3` makes 0 falsy (critical treated as position 3). Always use `?? 3` (nullish coalescing) when the value can legitimately be `0`.
3. **`replaceWith` on the modal DOM is permanent** — the student modal HTML is a single set of elements reused for every student. Any DOM mutation persists across subsequent `openModal()` calls.
4. **Subagents were tried and failed** — caused 4 broken builds due to JavaScript syntax errors in generated code. All coding done directly by Claude without spawning subagents.
5. **`sat.slice(5)` gives `MM-DD`** not `DD/MM` — the ISO date string `YYYY-MM-DD` sliced at position 5 gives month first. Use `sat.slice(8) + '/' + sat.slice(5,7)` for day/month format.
6. **Phase 7 block ordering matters** — `Phase 7 cont.` block overrides `_calRenderEditor` which is defined in the Phase 6 block. The Phase 7 cont. block must come AFTER Phase 7 in the file.
7. **WL enrolled state** — `WAITING_LIST_DATA` is baked in HTML and cannot be modified at runtime. Enrolled status lives in `localStorage.eeis_wl_enrolled_v1` as a Set of `"Firstname|Surname"` keys.

### Data Model Notes
- **Two chase-tracking systems coexist**: `chaseHistory` (current, per-student array) and `commLog` (legacy, per-family localStorage object). Phase 4 reads `chaseHistory`; old code falls back to `commLog.lastChased`. Both kept in sync when logging.
- **Calendar override format** (`eeis_cal_overrides_v1`): `{[isoDate]: action_string}` where action is `"add-school"`, `"add-holiday"`, `"remove-event"`, or `"add-event:Label:type"`. Extended metadata (labels, commentary) stored separately in `eeis_cal_meta_v1`.
- **Allergy severity** uses both explicit (`s['Allergy Severity']`) and keyword-detected values. Always call `classifyAllergyForStudent(s)` not `classifyAllergy(s['Allergies'])` directly.

---

## Google Cloud Console Notes

- Project name: **EEIS Dashboard**
- Authorised origin must include `https://madrasah.eeis.store`
- OAuth consent screen must be **External**, not Internal
- All 5 approved emails must be added as test users

---

## Upcoming / Deferred Features

These are scoped but not yet started:

| Feature | Blocker | Notes |
|---------|---------|-------|
| WL live sync from Google Form | Apps Script change needed | Add `waitinglist` tab case to `doPost()` |
| Excel migration sync | Needs SheetJS CDN + column mapping session | Risk of data corruption if mapping wrong |
| `chaseHistory` to Sheets column | Schema change needed | Future sprint |
| Annual term dates update | Manual | Update `TERM_DATES` constant for 2026-2027 academic year before September 2026 |
