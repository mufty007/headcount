# Headcount Events Platform - User Guide

## Getting Started

### Admin Login

1. Navigate to `/admin/` or `/admin/login.php`
2. Enter your email and password
3. Check "Remember me" to stay logged in for 30 days
4. Click "Login"

### Dashboard Overview

The dashboard shows:
- **Upcoming Events**: Count of published events in next 30 days
- **Total Members**: Active member count
- **MTD Attendance**: Check-ins this month
- **Total Events**: Lifetime event count

## Managing Events

### Creating an Event

1. Go to **Events** page
2. Click **"Create Event"** button
3. Fill in event details:
   - Title (required)
   - Description
   - Date and time (required)
   - Location (required)
   - Category
   - Capacity (optional)
   - Ticket price (optional)
4. Click **"Save"** or **"Publish"**

### Requesting an Event (staff workflow)

Staff who cannot create events directly (or who want operational review first) can submit an **event request**:

1. Go to **Events → Request Event**
2. Fill in the title, description, date/time, location, budget, expected attendance, and target audience
3. Submit the request — reviewers with **Approve event requests** are notified by email and in-app
4. If the request is **sent back**, open it, make the requested updates, and resubmit
5. If it is **declined**, you will be notified; that request is closed
6. If it is **approved**, Headcount creates a **draft event** from the proposal. Complete remaining details (tickets, questions, visibility, checklist) and publish

Direct **Create Event** remains available to people granted **Manage events**. Approvers are selected in **Settings → Permissions** (`events.approve_requests`).

### Event Status

- **Draft**: Not visible to members
- **Published**: Visible and open for RSVPs
- **Cancelled**: Event cancelled
- **Completed**: Event finished

### Duplicating Events

1. Go to Events page
2. Find the event to duplicate
3. Click **"Duplicate"** button
4. Edit the duplicated event as needed

## Managing Members

### Adding a Member

1. Go to **Members** page
2. Click **"Add Member"** button
3. Fill in member information:
   - First name (required)
   - Last name (required)
   - Email (required)
   - Phone (optional)
   - Gender (optional)
4. Click **"Save"**

### Importing Members

1. Go to **Members** page
2. Click **"Import CSV"** button
3. Upload your CSV file
4. Map columns to database fields
5. Review and confirm import

### Member Search

Use the search bar to find members by:
- First name
- Last name
- Email
- Phone number

## Check-In Process

### Starting Check-In

1. Go to **Events** page
2. Find the event
3. Click **"Check-In"** button

### Checking In Members

**Method 1: Search**
1. Type member name, email, or phone in search box
2. Click member from results
3. Member is checked in

**Method 2: QR Code**
1. Click **"Scan QR Code"** button
2. Allow camera access
3. Point camera at member's QR code
4. Member is automatically checked in

**Method 3: Family Members**
- When scanning a QR code, if member has family members, you'll see options to check them in too

### Viewing Checked-In Members

- See list of checked-in members below search
- Shows check-in time
- Can undo check-in if needed

## Reports

### Generating Reports

1. Go to **Reports** page
2. Select report type:
   - Attendance Report
   - Member Report
   - Event Report
3. Choose date range
4. Click **"Generate Report"**
5. Export as CSV or view online

## Settings

### Organization Settings

1. Go to **Settings** page
2. Update organization information
3. Configure Stripe payment keys
4. Configure SMTP email settings
5. Save changes

### Email Templates

1. Go to **Email Templates** page
2. Select template type
3. Edit template content
4. Use variables: `{member_name}`, `{event_title}`, etc.
5. Save template

## Security

### Password Requirements

- Minimum 8 characters
- Recommended: Mix of letters, numbers, and symbols

### Remember Me

- Keeps you logged in for 30 days
- Secure token stored in database
- Automatically logs you in on return visit

### Logout

- Click your name in header
- Click **"Logout"**
- All sessions and remember tokens are cleared

## Troubleshooting

### Can't log in?

- Check email and password
- Account may be locked after 5 failed attempts
- Contact administrator to unlock

### QR code not scanning?

- Ensure camera permissions are granted
- Check browser supports camera access
- Try refreshing the page

### Search not working?

- Type at least 2 characters
- Check member is active
- Verify member belongs to your organization
