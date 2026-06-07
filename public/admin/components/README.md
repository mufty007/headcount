# Admin UI Components

Reusable PHP view components and Alpine.js patterns for the Headcount admin area. Use these to keep pages consistent and avoid duplicating markup.

## Layout variables (global)

Before including `header.php`, pages can set:

- **`$pageTitle`** – Browser tab and header title (e.g. `'Dashboard'`).
- **`$currentPage`** – Key for sidebar highlight (e.g. `'dashboard'`, `'events'`). Must match a key in `$navUrls`.
- **`$additionalCSS`** – Optional array of CSS file paths.
- **`$additionalJS`** – Optional array of JS file paths.

`includes/layout-vars.php` sets `$basePath`, `$adminBase`, `$assetsBase`, `$navUrls`, and `$currentPage` (from `$_GET['page']`) when not already set. Include it via `header.php`; no need to require it in every page.

---

## PHP components

All components live under `public/admin/components/`. Each expects certain variables to be set; pass them before `require`ing the partial.

### 1. Page header (`page-header.php`)

Top-of-page title, optional subtitle, and optional action buttons.

**Expects:**

| Variable | Type | Description |
|----------|------|-------------|
| `$pageHeaderTitle` | string | Main heading (e.g. "Events", "Members"). |
| `$pageHeaderSubtitle` | string | Optional description line. |
| `$pageHeaderActions` | string | Optional HTML for buttons/links (e.g. "New Event", "Export"). Can include Alpine attributes. |

**Example:**

```php
$pageHeaderTitle = 'Members';
$pageHeaderSubtitle = $totalMembers . ' active members';
$pageHeaderActions = '<button @click="openCreateModal()" class="btn-modern ...">Add Member</button>';
require __DIR__ . '/components/page-header.php';
```

---

### 2. Stat card (`stat-card.php`)

Single KPI card (bento style) with optional icon.

**Expects:**

| Variable | Type | Description |
|----------|------|-------------|
| `$statLabel` | string | Label (e.g. "Total Members"). |
| `$statValue` | string\|int | Main number or text. |
| `$statSublabel` | string | Optional subtext (e.g. "Active users"). |
| `$statAccent` | string | One of: `indigo`, `emerald`, `amber`, `sky`, `rose`, `gray`. |
| `$statIcon` | string | Optional: `calendar`, `users`, `chart`, `currency`, `ticket`. |

**Example:**

```php
$statLabel = 'Upcoming Events';
$statValue = $stats['upcoming_events'];
$statSublabel = 'Next 30 days';
$statAccent = 'indigo';
$statIcon = 'calendar';
require __DIR__ . '/components/stat-card.php';
```

---

### 3. Filter bar (`filter-bar.php`)

Horizontal filter form: dropdowns and/or search, with Apply and Reset.

**Expects:**

| Variable | Type | Description |
|----------|------|-------------|
| `$filterBarAction` | string | Form `action` URL (e.g. `$adminBase . '/?page=events'`). |
| `$filterBarFields` | array | List of fields: `name`, `type` (`search` or `select`), `label`, `value`, `options` (for select), `placeholder`. |
| `$filterBarSubmitLabel` | string | Button text (default `"Apply"`). |

**Example:**

```php
$filterBarAction = $adminBase . '/?page=events';
$filterBarFields = [
    ['name' => 'status', 'type' => 'select', 'label' => 'Status', 'value' => $status, 'options' => ['all' => 'All', 'published' => 'Published']],
    ['name' => 'search', 'type' => 'search', 'label' => 'Search', 'value' => $search, 'placeholder' => 'Search...'],
];
require __DIR__ . '/components/filter-bar.php';
```

---

### 4. Table wrapper (`table-wrapper.php`)

Standard table shell: header row + body rows or empty state.

**Expects:**

| Variable | Type | Description |
|----------|------|-------------|
| `$tableColumns` | array | Each: `key`, `label`, optional `class`, optional `raw`/`raw_key` for HTML content. |
| `$tableRows` | array | Array of row arrays (keys match `key` in columns). |
| `$tableEmptyMessage` | string | Shown when `$tableRows` is empty. |
| `$tableEmptyAction` | string | Optional HTML (e.g. "Add first item" link). |

**Example:**

```php
$tableColumns = [['key' => 'name', 'label' => 'Name'], ['key' => 'email', 'label' => 'Email']];
$tableRows = $members;
$tableEmptyMessage = 'No members found.';
require __DIR__ . '/components/table-wrapper.php';
```

---

### 5. Empty state (`empty-state.php`)

Centered icon, message, and optional CTA when there is no data.

**Expects:**

| Variable | Type | Description |
|----------|------|-------------|
| `$emptyMessage` | string | Main message. |
| `$emptyIcon` | string | Optional: `calendar`, `users`, `inbox`, `folder`. |
| `$emptyAction` | string | Optional HTML (e.g. button or link). |

**Example:**

```php
$emptyMessage = 'No events scheduled. Time to plan something new!';
$emptyIcon = 'calendar';
$emptyAction = '<a href="' . e($adminBase . '/?page=events&action=create') . '" class="btn-modern ...">Create Event</a>';
require __DIR__ . '/components/empty-state.php';
```

---

### 6. Event header (`event-header.php`)

Event context block: title, date, time, location, optional stats, optional actions or custom stats HTML.

**Expects:**

| Variable | Type | Description |
|----------|------|-------------|
| `$event` | array | At least `title`, `event_date`, `start_time`, `location`; optional `rsvp_count`, `checkin_count`. |
| `$eventStats` | array | Optional: `checked_in`, `rsvp_yes` (used if event has no rsvp_count/checkin_count). |
| `$eventActions` | string | Optional HTML for buttons (e.g. "Start Check-In", "Details"). |
| `$eventHeaderStatsHtml` | string | Optional. If set, this replaces the default RSVP/Checked-in counts (e.g. Alpine-bound stats). |

**Example:**

```php
$event = $nextEvent;
$eventStats = ['checked_in' => $nextEvent['checkin_count'], 'rsvp_yes' => $nextEvent['rsvp_count']];
$eventActions = '<a href="' . e($adminBase . '/?page=checkin&event_id=' . $nextEvent['id']) . '" class="btn-modern ...">Start Check-In</a>';
require __DIR__ . '/components/event-header.php';
```

---

### 7. Chip / badge (`chip.php`)

Single tag or status pill. Can render one or many.

**Single chip – expects:**

- `$chipLabel` (string)
- `$chipVariant` (optional): `default`, `indigo`, `emerald`, `amber`, `rose`, `gray`

**Multiple chips – expects:**

- `$chips` (array of `['label' => '...', 'variant' => '...']`)

**Example:**

```php
$chipLabel = 'Published';
$chipVariant = 'emerald';
require __DIR__ . '/components/chip.php';
// or
$chips = [['label' => 'Draft', 'variant' => 'gray'], ['label' => 'Virtual', 'variant' => 'indigo']];
require __DIR__ . '/components/chip.php';
```

---

### 8. Pagination (`pagination.php`)

Previous/Next and "Page X of Y" for list pages. Uses GET param `p` by default.

**Expects:**

| Variable | Type | Description |
|----------|------|-------------|
| `$paginationBaseUrl` | string | Base URL (e.g. `$adminBase . '/?page=members'`). |
| `$paginationCurrentPage` | int | Current 1-based page. |
| `$paginationTotalPages` | int | Total pages. |
| `$paginationTotal` | int | Optional total count for "X total". |

**Example:**

```php
$paginationBaseUrl = $adminBase . '/?page=members';
$paginationCurrentPage = $page;
$paginationTotalPages = $totalPages;
$paginationTotal = $totalCount;
require __DIR__ . '/components/pagination.php';
```

---

### 9. Modal base (`modal-base.php`)

Reusable modal wrapper (title, close, scrollable body). Used with Alpine or JS to toggle.

**Expects:** (set before include)

- `$modalName` – Alpine variable name (e.g. `'showEventModal'`).
- `$modalTitle` or `$modalTitleDynamic` / `$modalTitleRaw` – Title.
- `$modalContent` – HTML body.
- `$maxWidth` (optional) – Tailwind max-width class suffix (e.g. `'2xl'`, `'4xl'`).

---

## Alpine / JS (admin-app.js)

Loaded in the admin header. Provides:

- **`AdminApi`** – `AdminApi.get(url)`, `AdminApi.post(url, body)`, `AdminApi.standardMessage(data)` for consistent API calls and error messages.
- **`adminModal(opts)`** – Alpine data: `open`, `openModal()`, `closeModal()`, `toggle()`.
- **`adminFilterBar(opts)`** – Alpine data: `status`, `category`, `search`, `setStatus`, `setCategory`, `setSearch`, `apply`. Optional `onApply` in opts.
- **`adminPagination(opts)`** – Alpine data: `currentPage`, `totalPages`, `baseUrl`, `hasPrev`, `hasNext`, `prevUrl()`, `nextUrl()`, `pageUrl(p)`.
- **`offlineBanner(opts)`** – Alpine data: `isOffline`, `pendingSyncCount`, `syncingInProgress`, `init()` (listens online/offline). Optional `onInit`, `pendingSyncCount` in opts.
- **`asyncTableLoader(opts)`** – Alpine data: `loading`, `error`, `data`, `load()`, `init()`. Set `opts.url` or `opts.url()` and optionally `opts.onLoad`, `opts.loadOnInit`.

**Example (Alpine):**

```html
<div x-data="adminModal()">
    <button @click="openModal()">Open</button>
    <div x-show="open" x-cloak>...</div>
</div>
```

---

## File locations

- **Layout / nav:** `public/admin/includes/header.php`, `footer.php`, `layout-vars.php`
- **Components:** `public/admin/components/*.php`
- **Shared JS:** `public/js/admin-app.js`
- **Existing modal/confirm:** `public/js/modal.js`, `public/js/confirm.js`

When adding a new admin page, set `$pageTitle` and `$currentPage`, then include `includes/header.php`, use the components above where they fit, and include `includes/footer.php`.
