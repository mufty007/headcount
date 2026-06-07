# Headcount Events Platform - Features

## App Overview
Headcount is an events management platform with:
- An **Admin** interface for creating and running events (publishing, check-in, reporting, and member management).
- A **Member Portal** for attendees to register/authenticate, browse events, RSVP (including paid ticketed events), manage RSVPs, and handle check-in via QR code.

## Feature List
### Authentication & Access
- Admin login/logout with session-based authentication
- “Remember me” for admin accounts (persistent login via remember tokens)
- Member portal authentication
  - Email + password login
  - “Magic link” login
- Admin/member session protection for portal endpoints
- CSRF protection on state-changing operations
- API authentication separation:
  - Admin endpoints require admin auth
  - Portal endpoints require portal/member auth

### Organization & Settings
- Update organization information (admin settings)
- Configure Stripe keys for ticket payments
- Configure SMTP email settings for transactional emails

### Event Management (Admin)
- Create events (title, description, date/time, location, category, capacity)
- Optional ticket pricing for paid events
- Event lifecycle/status handling:
  - Draft (not visible to members)
  - Published (visible/open for RSVPs)
  - Cancelled
  - Completed
- Duplicate events

### Member Management (Admin)
- Add members (name, email, optional phone/gender)
- Import members via CSV with column mapping and confirmation
- Search members by:
  - First name
  - Last name
  - Email
  - Phone

### RSVP System (Member Portal + API)
- Browse published events in the portal
- RSVP for an event (for self)
- RSVP family members from within the event RSVP flow
- RSVP status tracking in the portal (examples include Yes/No/Maybe)
- Ability to cancel RSVP (portal)
- Automatic RSVP creation after successful paid ticket purchase

### Ticket Payments (Stripe)
- Paid event checkout via Stripe checkout session
- Member checkout flow from the portal
- Payment history in the portal
- Receipt access after payment (view/download from the portal)
- Admin settings for Stripe payment keys

### Check-In & Attendance (Admin)
- Start/perform check-in for events
- Check-in members by:
  - Searching members by name/email/phone
  - QR code scanning
  - Family-member check-in options
- View checked-in members list including check-in time
- Undo/check-in reversal functionality

### QR Codes (Portal + Admin Check-In)
- Portal QR code page for the logged-in member
- QR code generation (including re-generation when returning to the page)
- QR code expiration window (24 hours)
- QR code used for fast event check-in by admin

### Reports (Admin)
- Generate reports from the admin UI for:
  - Attendance reports
  - Member reports
  - Event reports
- Select date ranges for reports
- Export reports as CSV or view them online

### Email & Communication
- Admin email templates management
  - Edit template content
  - Use template variables (e.g., member/event placeholders)
- Transactional emails sent via configured SMTP/portal email services
- Email delivery support (including webhook/integration for provider flows)
- Bulk email functionality (admin)
- Email logs / notification history via admin APIs

### Member Portal Features
- Member profile management (edit profile details, save changes)
- Profile photo upload (as supported by the portal)
- Communication preferences (select notification types such as reminders/announcements/RSVP confirmations)
- Family member management:
  - Add family members (relationship + optional DOB)
- “My RSVPs” page with filtering and event history
- Payment receipts page with ability to open/download receipts
- Calendar integration:
  - Add event to Google/Apple/Outlook calendars
  - Download ICS file
- Event feedback submission after events:
  - Star rating (1-5)
  - Optional comments

### APIs (REST/JSON)
- Admin APIs for managing:
  - Events
  - Members
  - Attendance/check-ins
  - RSVPs
  - Payments/checkout sessions
  - Settings and email templates
  - Reports/exports
- Portal APIs for:
  - Event browsing
  - RSVP creation and cancellation
  - Portal payments
  - Portal profile/family management
  - Portal feedback

### Operational & Security Support
- Rate limiting for login and API endpoints (per docs)
- Security-focused utilities (validation, output escaping guidance, and CSRF verification flow)

