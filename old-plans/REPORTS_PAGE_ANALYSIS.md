# Reports Page Analysis & Improvement Recommendations

## Overview

The Reports page (`public/admin/reports.php`) provides analytics and insights: date-range filtering, summary stats, attendance trend and category charts, new vs returning attendees, top events, top attendees table, and CSV export. Below is an analysis of issues and recommended improvements.

---

## 1. UX / UI

| Issue | Recommendation |
|-------|----------------|
| **Reset button** | Reset uses `window.location.href='reports.php'`. When the app is loaded via `index.php?page=reports`, this can break (wrong URL). Use a reset that keeps the same router, e.g. link to `?page=reports` (or current admin base + `?page=reports`). |
| **Unused stats** | `total_rsvps` and `total_members` are queried but never shown. Either add small stat cards or a “RSVPs / Members” line, or remove the queries to avoid confusion and extra DB load. |
| **Inconsistent card styling** | Summary cards use `rounded-3xl` and modern-design; “New vs Returning”, “Top Events”, and “Top Attendees” use `rounded-lg`. Unify to `rounded-3xl` and the same border/shadow as the rest for a consistent look. |
| **Revenue block** | Revenue section uses a different style (gradient green). Either align with the stat card pattern or keep it as a clear “highlight” but use the same border/radius as other cards. |
| **Export feedback** | “Export Report” triggers a download with no loading state or success message. Add a short loading state and, if possible, a brief “Report downloaded” message so users know the action worked. |
| **Date presets** | Only custom date range is available. Add quick presets (e.g. “This week”, “This month”, “Last 30 days”) to reduce friction. |
| **Empty states** | When there’s no data, charts can look empty or show a single zero. Add explicit empty-state copy (e.g. “No attendance in this period”) and optional placeholder visuals. |
| **Top Events count** | Query fetches 10 events but only 5 are rendered. Either show all 10 or document/code the limit in one place. |

---

## 2. Technical

| Issue | Recommendation |
|-------|----------------|
| **Export URL** | `$apiBaseUrl` is built from `REQUEST_URI` in `reports.php`. Ensure this matches how the app is actually served (e.g. under `/headcount` vs `/`) so export always hits the correct API. Consider reusing the same base URL logic as in `header.php` for consistency. |
| **Export API** | `export-report.php` uses positional placeholders (`? AND ? AND ?`) while the rest of the app uses named parameters. Prefer named parameters for consistency and safety. |
| **Query structure** | Many separate try/catch blocks for each stat. Consider a small helper or grouped logic so fallbacks are consistent and the file is easier to maintain. |
| **Performance** | Every page load runs many queries. For large orgs, consider short-lived caching (e.g. 1–5 minutes) for report data or lazy-loading charts after initial paint. |

---

## 3. Export (export-report.php)

| Issue | Recommendation |
|-------|----------------|
| **CSV only** | Only CSV is supported. Adding PDF and/or Excel would improve usefulness for stakeholders. |
| **Content** | Export includes event-level attendance only. Consider adding a “Summary” section (e.g. total events, total attendance, unique attendees, date range) at the top of the CSV or in a second file/sheet. |
| **Security** | Export is admin-only (`AuthMiddleware::requireAdmin()`). No change required; optional: rate limit or audit log for export actions. |

---

## 4. Accessibility & Responsiveness

| Issue | Recommendation |
|-------|----------------|
| **Charts** | Chart containers have no `aria-label` or role. Add a short label describing the chart (e.g. “Attendance trend over time”) for screen readers. |
| **Date inputs** | Ensure each date input has an associated `<label>` (or `aria-label`) so the filter is clear when using a screen reader. |
| **Tables** | Top Attendees table already uses `overflow-x-auto`; ensure header cells and row structure are correct so tables make sense when linearized. |

---

## 5. Data & Content

| Issue | Recommendation |
|-------|----------------|
| **RSVPs / Members** | Display the already-computed “Total RSVPs” and “Total members” (e.g. in summary or a small secondary row) so the data is visible and the queries are justified. |
| **New vs Returning** | Definition (first attendance in selected range = “new”) is correct. Add a short subtitle or tooltip so the meaning is clear at a glance. |

---

## Priority Summary

**High impact, low effort**

1. Fix Reset button to use `?page=reports` (or current admin base).
2. Show RSVPs and total members (or remove their queries).
3. Unify card styling (rounded-3xl, same border/shadow).
4. Add export loading state and brief success feedback.

**Medium impact**

5. Add date range presets (This week / This month / Last 30 days).
6. Use named parameters in `export-report.php` and add a short summary block to the CSV.
7. Add empty-state messages for charts and tables.

**Nice to have**

8. Chart accessibility (aria-labels).
9. Optional caching or lazy-loading for report data.
10. PDF/Excel export options.
