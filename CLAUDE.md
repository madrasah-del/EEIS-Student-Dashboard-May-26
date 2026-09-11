# CLAUDE.md

Guidance for Claude Code working in this repo.

## Project

Single-file web app (`index.html`, single HTML/CSS/JS file, no build step) for
EEIS Al-Kauthar Saturday Madrasah, Epsom. ~112 students, ~19 staff, fees,
waiting list, medical records, calendar. Hosted as a static file on
Hostinger, Google Sheets as the database via Apps Script, with a live
bidirectional sync to a OneDrive Excel workbook (see "Excel sync" below).
Feature history lives in `git log`, not here — don't re-derive it from this
file; check memory `eeis_madrasah_dashboard` for cross-session context this
file doesn't cover.

## Critical constants — never change

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

## Workflow rules

- Small commits, one feature area per commit, push after each checkpoint —
  Hostinger auto-deploys from `main` via webhook within ~60s.
- Verify a change deployed with `curl -s $LIVE_URL | grep -q '<unique string
  from the edit>'` in a wait-loop before checking live in the browser —
  cheaper than repeated screenshots.
- Syntax-check before every commit: extract each `<script>` block and run
  `new Function(code)` on it (see any recent commit for the exact snippet).
- Prefer `const _orig = fn; fn = function(){...}` overrides over rewriting —
  but see the "reassignment trap" pitfall below before trusting a `grep`
  hit is the version that actually runs.
- **No subagents** — past agent-generated JS had syntax errors that broke
  4 builds. All work done directly, in this session.
- **No local file archiving of student/family data, ever** (GDPR-scoped
  children's data) — never write dated snapshots/backups to disk. If
  fresher access is needed, build a proper API integration instead.

## Architecture

- **Sheets is the DB**: all reads/writes go through `APPS_SCRIPT_URL` via
  `sheetsGet(tab)` / `sheetsPost(tab, rows)`. `localStorage` is cache only
  (`eeis_db_cache`, `eeis_staff_cache`, `eeis_session`, etc. — see code for
  the full key list, it's stable and rarely worth re-grepping).
- **Sprint/override pattern**: new features are appended as `<script>`
  blocks at the end of the file, each one reassigning earlier functions
  (`fn = function(){ _orig(); ...}` or a full replacement). Blocks are
  numbered/named informally ("Phase N", "Sprint N") in comments near each.
- **THE REASSIGNMENT TRAP** (burned real time twice this session — check
  this before trusting any `grep -n "function X"` hit): several core
  functions (`parseStaffRows`, `staffToRows`, `renderStaffGrid`, and likely
  others) are defined once early with `function X(){}`, then **reassigned
  outright** (not wrapped) by a *later* script block with `X = function(){}`.
  The early definition becomes dead code. Whichever assignment is LAST in
  file order is what actually runs. Always grep for ALL occurrences of a
  function name (not just the first) and read the file in order before
  editing — editing the dead early copy silently does nothing live.
- **Excel sync** (`excel-proxy.php`, `app-excel-write.php`,
  `app-excel-field-write.php`, `excel-admin.php` on the Hostinger server):
  - `excel-proxy.php` — public, read-only, returns the full workbook.
  - `app-excel-write.php` — public, narrow: payment-only, finds student by
    name, appends to the first free instalment slot (main tab) or
    updates paid/outstanding (G5 tab).
  - `app-excel-field-write.php` — public, narrow: general field sync with
    "newer wins" baseline-check (client sends old+new value; if Excel's
    current value no longer matches the client's baseline, Excel wins and
    the field is skipped). Whitelisted fields live in its `$COLS` array —
    **`Fees Due` IS included** (column H both tabs); only `Class` and
    `Fees Paid` are excluded (Class = tab-move logic not attempted here;
    Fees Paid = payments-only, use `app-excel-write.php`). Called via
    `_pushFieldsToExcel(student, baselineValues, fieldNames)` after a
    Sheets save succeeds; fails soft, never blocks the app-side save.
  - `excel-admin.php` — **admin-key-gated** (`?key=...`), broad write
    actions (`write_range`, `apply_style`, table/formatting ops). The key
    lives only in `ms_config.php` on the live server, **not in this repo,
    not available locally** — don't try to read it from a local checkout;
    if a task genuinely needs this endpoint, ask the user to run the curl
    command themselves or supply the key.
  - Bulk Excel pushes from a browser JS console are **slow** (~3-4s per
    field per student — a name-scan + cell read + write, each a Graph API
    round trip): batch in groups of ~4-8 calls per `javascript_exec` to
    stay under the 45s tool timeout, not all at once.
  - `app-excel-build-financial-charts.php` / `app-excel-build-family-log.php`
    — public, narrow, idempotent report builders (no admin key needed).
    First reads real per-payment data straight from the payment-slot
    columns, writes chart-ready tables + 2 charts onto the "Financial Log"
    tab (payments received, collections-vs-debt trend). Second groups
    students into families by shared parent phone/email and flags
    same-day sibling payments (likely one combined till payment split
    across children) onto its own "Family Payments Log" tab. Both are
    fired fire-and-forget from `refreshEverything()` on every manual
    refresh — slow (10-20s of Graph calls each), so never awaited.
- **Baked static data in `index.html`, not in Sheets**: `WAITING_LIST_DATA`
  (waiting-list array — editing it means editing this file's source, then
  deploy; enrolled-status overlay lives in `localStorage.eeis_wl_enrolled_v1`),
  `TERM_DATES`, the EEIS logo (base64 JPEG in `#print-header`).
- **Fee schedule** (confirmed from the Excel `Budget` tab, Sept 2026):
  £260 = continuing/existing student, £280 = new student (first year
  only), £175 = G5 flat (no new/existing split). A student's rate should
  drop from 280→260 once they're no longer in their first year — Phase 17
  in `index.html` runs this check automatically each August/September.

## Known pitfalls

0. **The IIFE-scope trap**: several script blocks wrap their whole body in
   `(function(){ ... })();`. A function declared inside one is invisible
   outside it — `typeof fn === 'function'` from another block silently
   evaluates `false` forever, it does NOT throw, so a guarded call site
   just quietly no-ops instead of erroring. This bit `_p10NormDate`
   specifically: a `_pushFieldsToExcel`/`parseStaffRows` call site guarded
   it with `typeof _p10NormDate === 'function'` and the guard was always
   false because the function lives inside the big IIFE around line 10315
   and was never exported. Fix (and the pattern to follow when adding a
   new cross-IIFE call): `window._p10NormDate = _p10NormDate;` right after
   the definition — same fix already applied to `_wlKey` for the same
   reason. If a `typeof X === 'function'` guard's fallback path is what
   actually runs in production and nobody notices, this is why — check
   `window.X` directly, don't trust that "no error" means "it ran."
0.5. **Staff data has no periodic refresh — student data does.** Student
   data auto-refreshes via `loadFromSheets()` when the local cache is
   empty or over an hour old; staff data had no equivalent, so a browser
   with any cached staffDb (however old) never noticed server-side
   changes on its own. Worse, the Staff panel's "☁ Sync" button used to
   be push-only (`pushStaffToSheets()`), so a stale local copy clicking
   "Sync" would silently overwrite a correct server-side change — this
   happened for real (a rate update got reverted this way). Fixed:
   `enterApp()` now unconditionally pulls fresh staff data via
   `refreshStaffFromSheets()` on every login, and the Sync button pulls
   instead of pushing (legitimate edits already push immediately from
   `saveStaffMember()`, so a dedicated sync action only ever needs to
   pull). If a "sync" button's actual direction isn't obvious from its
   label, check before assuming — "sync" is not self-documenting.
1. Never `replaceWith()` an element containing IDs other code
   `getElementById()`s later — hide it and insert a sibling instead.
2. `||` vs `??` for a value that can legitimately be `0` — `X || fallback`
   silently discards a real `0`. Two real bugs this session alone came from
   this exact pattern (`classifyAllergy` critical-severity, and a
   `parseInt(id) || loopIndex` that duplicated a staff card's content onto
   its neighbour because `parseInt('0')` is falsy).
3. The student/staff modal DOM is a single reused element set —
   `replaceWith` on it persists across every subsequent `openModal()` call.
4. `sat.slice(5)` on an ISO `YYYY-MM-DD` gives `MM-DD`, not `DD/MM` — use
   `slice(8) + '/' + slice(5,7)`.
5. **Date round-trips through Google Sheets shift by a day**: a plain
   `YYYY-MM-DD` written to a cell can come back as a full UTC ISO datetime
   (`...T23:00:00.000Z`), which is the PREVIOUS day in BST if read via the
   string's literal date substring. Always parse with local `Date` getters
   (`d.getFullYear()/getMonth()/getDate()`), not `str.slice(0,10)`. Reuse
   `_p10NormDate()` — don't re-derive this per field.
6. **UK phone numbers lose their leading 0** the same way (Sheets/Excel
   treats the value as numeric): a 10-digit number not starting with `0` is
   almost always missing that digit — safe to auto-fix by prepending `0`.
   A number that's the wrong length even *with* a leading 0 already present
   is a genuinely missing digit — don't guess, ask the family.
7. Auto-mode's tool classifier blocks some actions outright regardless of
   user chat approval (seen: `delete_table` on Excel, a direct `curl POST`
   write to the Apps Script endpoint) — no in-call workaround exists; fall
   back to a non-destructive alternative (e.g. drive the change through the
   actual app in a real browser tab instead of a raw HTTP call) and tell
   the user why.

## Deployment

Push to `main` → Hostinger auto-deploys via GitHub webhook, live in ~60s.
Manual fallback: Hostinger hPanel → Git → Deploy. File lives at
`public_html/madrasah/index.html` on the server.

## Google Cloud Console

Project **EEIS Dashboard**. Authorised origin must include
`https://madrasah.eeis.store`. OAuth consent screen: External. All 5
approved staff emails must be added as test users (see Apps Script /
Sheets `Users` tab for the current approved list — don't hardcode it here,
it changes).
