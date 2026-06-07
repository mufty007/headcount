# Member Detail Page Implementation Plan

## Overview
When clicking on a member in the members page, navigate to a dedicated member detail page showing comprehensive information about that member.

## Current State
- **Existing File**: `public/admin/member_details.php` exists but uses different routing
- **Current Routing**: Members page links to `?page=member-details&id={id}` (expects `member-details.php`)
- **Current Stats**: Basic stats exist in `MemberService::getMemberStats()` but need enhancement

## Requirements

### 1. Page Layout
- **Left Sidebar Card** (Small, fixed width ~300px):
  - Member's full name (prominent)
  - Contact information:
    - Email address
    - Phone number
    - Member since date
  - Status badge (active/inactive/deleted)
  - Tags and Groups (if any)
  
- **Main Content Area** (Remaining width):
  - Statistics cards/overview
  - Detailed information sections

### 2. Statistics to Display

#### Core Statistics (Cards/Grid):
1. **Events Attended**
   - Total count of events where member checked in
   - Query: `COUNT(*) FROM attendance WHERE user_id = ? AND checked_in_at IS NOT NULL`

2. **Events Signed Up For**
   - Total count of events where member RSVP'd "yes"
   - Query: `COUNT(*) FROM rsvps WHERE user_id = ? AND status = 'yes'`

3. **No-Shows**
   - Events where member RSVP'd "yes" but didn't check in
   - Query: Count RSVPs with status='yes' that don't have corresponding attendance record
   - Formula: `(RSVP yes) - (Events attended)`

4. **Email Status**
   - Whether member is receiving emails
   - Check: 
     - If member has email address
     - Recent email logs sent to this member (from `email_logs` table)
     - Display: "Receiving emails" / "Not receiving emails" / "No email on file"

#### Additional Statistics:
5. **Attendance Rate**
   - Percentage: (Events attended) / (Events signed up) * 100
   - Only calculate if they've signed up for events

6. **Last Attendance**
   - Most recent event they checked into
   - Date and event name

7. **Last RSVP**
   - Most recent RSVP response
   - Date and event name

### 3. Detailed Sections

#### Recent Activity Tables:
1. **Recent Attendance History**
   - Table showing last 10-15 events attended
   - Columns: Event Name, Date, Time, Status (Checked In)

2. **Recent RSVP History**
   - Table showing last 10-15 RSVP responses
   - Columns: Event Name, Event Date, Response (Yes/No/Maybe), Response Date

3. **No-Show Events** (if applicable)
   - Table showing events where they RSVP'd yes but didn't attend
   - Columns: Event Name, Event Date, RSVP Date

### 4. Implementation Steps

#### Step 1: Fix Routing & File Naming
- [ ] Rename `member_details.php` to `member-details.php` (match routing)
- [ ] OR update routing to handle both names
- [ ] Ensure the link in `members.php` works correctly

#### Step 2: Enhance MemberService
- [ ] Update `getMemberStats()` method to include:
  - `total_attended`: Count of events checked into
  - `total_signed_up`: Count of RSVPs with status='yes'
  - `no_shows`: Count of RSVPs='yes' without attendance
  - `attendance_rate`: Calculated percentage
  - `email_status`: Check email logs and email field
  - `last_attendance`: Most recent attendance record
  - `last_rsvp`: Most recent RSVP record
  - `no_show_events`: Array of events RSVP'd but not attended

#### Step 3: Update Member Detail Page Layout
- [ ] Create two-column layout (sidebar + main)
- [ ] Build left sidebar card with contact info
- [ ] Create statistics cards grid
- [ ] Add activity tables sections

#### Step 4: Add Email Status Logic
- [ ] Query `email_logs` table for this member
- [ ] Check if member has valid email address
- [ ] Determine email receiving status
- [ ] Display appropriate status badge/message

#### Step 5: Style & Polish
- [ ] Match existing design system (bento-card, status-badge, etc.)
- [ ] Ensure responsive design (mobile-friendly)
- [ ] Add loading states if needed
- [ ] Add breadcrumb navigation

### 5. Database Queries Needed

```sql
-- Events attended
SELECT COUNT(*) FROM attendance 
WHERE user_id = ? AND checked_in_at IS NOT NULL

-- Events signed up (RSVP yes)
SELECT COUNT(*) FROM rsvps 
WHERE user_id = ? AND status = 'yes'

-- No-shows: RSVP yes but no attendance
SELECT COUNT(*) FROM rsvps r
LEFT JOIN attendance a ON r.event_id = a.event_id AND r.user_id = a.user_id
WHERE r.user_id = ? AND r.status = 'yes' AND a.id IS NULL

-- Email status: Check recent emails sent
SELECT COUNT(*) FROM email_logs 
WHERE recipient_user_id = ? AND status = 'sent'
AND sent_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)

-- Recent attendance
SELECT a.*, e.title, e.event_date, e.start_time
FROM attendance a
JOIN events e ON a.event_id = e.id
WHERE a.user_id = ?
ORDER BY a.checked_in_at DESC
LIMIT 15

-- Recent RSVPs
SELECT r.*, e.title, e.event_date
FROM rsvps r
JOIN events e ON r.event_id = e.id
WHERE r.user_id = ?
ORDER BY r.created_at DESC
LIMIT 15

-- No-show events
SELECT r.*, e.title, e.event_date, e.start_time
FROM rsvps r
JOIN events e ON r.event_id = e.id
LEFT JOIN attendance a ON r.event_id = a.event_id AND r.user_id = a.user_id
WHERE r.user_id = ? AND r.status = 'yes' AND a.id IS NULL
ORDER BY e.event_date DESC
```

### 6. File Structure

```
public/admin/
  ├── member-details.php (or member_details.php - needs to match routing)
  └── includes/
      ├── header.php (already exists)
      └── footer.php (already exists)
```

### 7. Design Considerations

- **Consistent Styling**: Use existing design tokens (bento-card, status-badge, btn-modern)
- **Color Coding**:
  - Events attended: Green/Indigo
  - No-shows: Amber/Orange
  - Email status: Green (receiving) / Gray (not receiving)
- **Responsive**: Sidebar should stack on mobile
- **Loading States**: Show skeleton/spinner while loading data
- **Empty States**: Handle cases where member has no activity

### 8. Testing Checklist

- [ ] Click member name in members list → navigates to detail page
- [ ] All statistics display correctly
- [ ] Email status shows accurate information
- [ ] No-show calculation is correct
- [ ] Recent activity tables show data
- [ ] Page works for members with no activity
- [ ] Page works for members with no email
- [ ] Responsive design works on mobile
- [ ] Breadcrumb navigation works
- [ ] Edit/Delete buttons work (if present)

## Implementation Priority

1. **High Priority**: Core statistics (attended, signed up, no-shows)
2. **High Priority**: Left sidebar with contact info
3. **Medium Priority**: Email status
4. **Medium Priority**: Recent activity tables
5. **Low Priority**: Additional polish and enhancements

## Notes

- The existing `member_details.php` file has some structure but needs enhancement
- MemberService already has basic stats method that can be extended
- Email logs table exists and can be queried for email status
- Need to ensure routing matches the link format in members.php
