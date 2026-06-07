# End-to-End Testing Plan
## Headcount Events Platform

**Estimated Time:** 4-6 hours  
**Date:** 2024  
**Version:** 1.0

---

## Table of Contents

1. [Overview](#overview)
2. [Test Environment Setup](#test-environment-setup)
3. [Test Scenarios](#test-scenarios)
4. [Test Execution Checklist](#test-execution-checklist)
5. [Test Data Requirements](#test-data-requirements)
6. [Defect Reporting](#defect-reporting)

---

## Overview

This document outlines comprehensive end-to-end testing scenarios for the Headcount Events Platform. E2E tests verify complete user workflows from start to finish, ensuring all integrated components work together correctly.

### Testing Objectives

- Verify complete user journeys work as expected
- Validate integration between frontend, backend, and database
- Ensure data integrity across operations
- Confirm security measures are functioning
- Validate error handling and edge cases

### Test Coverage Areas

1. **Authentication & Authorization**
2. **Member Management**
3. **Event Management**
4. **Attendance & Check-In**
5. **Payment Processing**
6. **Reporting & Exports**
7. **Email Communications**
8. **Member Portal**
9. **Admin Portal**

---

## Test Environment Setup

### Prerequisites

- PHP 8.0+ installed
- MySQL 5.7+ or MariaDB 10.3+ running
- Web server (Apache/Nginx) configured
- Test database created and migrated
- Test email account configured (SMTP2GO)
- Test Stripe account (test mode)

### Test Data Setup

1. Create test organization
2. Create test admin user
3. Create test member users (various statuses)
4. Create test events (past, current, future)
5. Create test tags and groups
6. Configure test payment settings

### Browser/Device Testing

- Chrome (latest)
- Firefox (latest)
- Safari (latest)
- Edge (latest)
- Mobile devices (iOS/Android)

---

## Test Scenarios

### 1. Authentication & Authorization

#### TC-AUTH-001: Admin Login - Valid Credentials
**Priority:** Critical  
**Estimated Time:** 5 minutes

**Steps:**
1. Navigate to `/admin/?page=login`
2. Enter valid admin email
3. Enter valid password
4. Click "Login" button

**Expected Results:**
- User is redirected to admin dashboard
- Session is created
- User information displayed in header
- No error messages

**Pass/Fail:** ☐

---

#### TC-AUTH-002: Admin Login - Invalid Credentials
**Priority:** High  
**Estimated Time:** 3 minutes

**Steps:**
1. Navigate to `/admin/?page=login`
2. Enter invalid email
3. Enter invalid password
4. Click "Login" button

**Expected Results:**
- Error message displayed: "Invalid email or password"
- User remains on login page
- No session created
- Failed login attempt logged

**Pass/Fail:** ☐

---

#### TC-AUTH-003: Admin Login - Account Lockout After Failed Attempts
**Priority:** High  
**Estimated Time:** 10 minutes

**Steps:**
1. Navigate to `/admin/?page=login`
2. Attempt login with wrong password 5 times
3. Attempt login with correct password on 6th attempt

**Expected Results:**
- After 5 failed attempts, account is locked
- Error message indicates account is locked
- After 30 minutes, account unlocks
- Failed attempts are logged

**Pass/Fail:** ☐

---

#### TC-AUTH-004: Session Management - Timeout
**Priority:** High  
**Estimated Time:** 15 minutes

**Steps:**
1. Login as admin
2. Wait 24 hours (or adjust session timeout)
3. Attempt to access admin page

**Expected Results:**
- Session expires after timeout period
- User is redirected to login page
- Session data is cleared

**Pass/Fail:** ☐

---

#### TC-AUTH-005: Logout
**Priority:** High  
**Estimated Time:** 3 minutes

**Steps:**
1. Login as admin
2. Click logout button
3. Attempt to access admin page directly

**Expected Results:**
- User is logged out
- Session is destroyed
- User is redirected to login page
- Cannot access admin pages without re-login

**Pass/Fail:** ☐

---

#### TC-AUTH-006: Member Portal Login
**Priority:** High  
**Estimated Time:** 5 minutes

**Steps:**
1. Navigate to `/portal/login.php`
2. Enter valid member email
3. Enter valid password
4. Click "Login"

**Expected Results:**
- User is redirected to member portal dashboard
- Member-specific data is displayed
- Cannot access admin areas

**Pass/Fail:** ☐

---

### 2. Member Management

#### TC-MEM-001: Create New Member
**Priority:** Critical  
**Estimated Time:** 10 minutes

**Steps:**
1. Login as admin
2. Navigate to Members page
3. Click "Add Member" button
4. Fill in required fields:
   - First Name
   - Last Name
   - Email
   - Phone
   - Date of Birth
5. Click "Save"

**Expected Results:**
- Member is created successfully
- Success message displayed
- Member appears in members list
- Member receives welcome email (if configured)
- Member record is saved in database

**Pass/Fail:** ☐

---

#### TC-MEM-002: Edit Member Details
**Priority:** Critical  
**Estimated Time:** 8 minutes

**Steps:**
1. Login as admin
2. Navigate to Members page
3. Click on a member
4. Click "Edit" button
5. Modify member information
6. Click "Save"

**Expected Results:**
- Changes are saved successfully
- Success message displayed
- Updated information appears on member details page
- Changes are reflected in members list
- Activity log records the change

**Pass/Fail:** ☐

---

#### TC-MEM-003: Delete Member
**Priority:** High  
**Estimated Time:** 5 minutes

**Steps:**
1. Login as admin
2. Navigate to Members page
3. Click on a member
4. Click "Delete" button
5. Confirm deletion

**Expected Results:**
- Confirmation dialog appears
- Member is deleted (or soft-deleted)
- Success message displayed
- Member removed from members list
- Related records handled appropriately

**Pass/Fail:** ☐

---

#### TC-MEM-004: Import Members from CSV
**Priority:** High  
**Estimated Time:** 15 minutes

**Steps:**
1. Login as admin
2. Navigate to Members page
3. Click "Import CSV" button
4. Upload valid CSV file with member data
5. Map CSV columns to database fields
6. Review import preview
7. Confirm import

**Expected Results:**
- CSV file is validated
- Column mapping interface works correctly
- Preview shows correct data
- Members are imported successfully
- Import summary displayed
- Duplicate detection works (if applicable)
- Error handling for invalid rows

**Pass/Fail:** ☐

---

#### TC-MEM-005: Search Members
**Priority:** High  
**Estimated Time:** 5 minutes

**Steps:**
1. Login as admin
2. Navigate to Members page
3. Enter search term in search box
4. Review results

**Expected Results:**
- Search works in real-time (or on submit)
- Results match search criteria
- Search works across name, email, phone
- Results are paginated if needed
- Clear search functionality works

**Pass/Fail:** ☐

---

#### TC-MEM-006: Filter Members by Status
**Priority:** Medium  
**Estimated Time:** 5 minutes

**Steps:**
1. Login as admin
2. Navigate to Members page
3. Select status filter (Active, Inactive, All)
4. Review filtered results

**Expected Results:**
- Filter applies correctly
- Only members with selected status are shown
- Filter persists across page navigation
- Counts are accurate

**Pass/Fail:** ☐

---

#### TC-MEM-007: Assign Tags to Member
**Priority:** Medium  
**Estimated Time:** 5 minutes

**Steps:**
1. Login as admin
2. Navigate to member details page
3. Click "Edit Tags"
4. Select one or more tags
5. Save changes

**Expected Results:**
- Tags are assigned successfully
- Tags appear on member details page
- Tags are searchable/filterable
- Tags persist after page refresh

**Pass/Fail:** ☐

---

#### TC-MEM-008: Assign Member to Group
**Priority:** Medium  
**Estimated Time:** 5 minutes

**Steps:**
1. Login as admin
2. Navigate to member details page
3. Click "Edit Groups"
4. Select one or more groups
5. Save changes

**Expected Results:**
- Groups are assigned successfully
- Groups appear on member details page
- Member appears in group member list
- Groups persist after page refresh

**Pass/Fail:** ☐

---

### 3. Event Management

#### TC-EVT-001: Create New Event
**Priority:** Critical  
**Estimated Time:** 15 minutes

**Steps:**
1. Login as admin
2. Navigate to Events page
3. Click "Create Event" button
4. Fill in event details:
   - Event Title
   - Description
   - Event Date
   - Start Time
   - End Time
   - Location
   - Capacity (optional)
   - Category
   - Price (if paid event)
5. Upload banner image (optional)
6. Click "Save" or "Publish"

**Expected Results:**
- Event is created successfully
- Success message displayed
- Event appears in events list
- Event details are saved correctly
- Banner image is uploaded and displayed
- Date/time validation works
- Price validation works

**Pass/Fail:** ☐

---

#### TC-EVT-002: Edit Event
**Priority:** Critical  
**Estimated Time:** 10 minutes

**Steps:**
1. Login as admin
2. Navigate to Events page
3. Click on an event
4. Click "Edit" button
5. Modify event details
6. Save changes

**Expected Results:**
- Changes are saved successfully
- Success message displayed
- Updated information appears on event page
- Changes are reflected in events list
- Activity log records the change

**Pass/Fail:** ☐

---

#### TC-EVT-003: Delete Event
**Priority:** High  
**Estimated Time:** 5 minutes

**Steps:**
1. Login as admin
2. Navigate to Events page
3. Click on an event
4. Click "Delete" button
5. Confirm deletion

**Expected Results:**
- Confirmation dialog appears
- Event is deleted (or soft-deleted)
- Success message displayed
- Event removed from events list
- Related attendance records handled appropriately

**Pass/Fail:** ☐

---

#### TC-EVT-004: Publish/Draft Event
**Priority:** High  
**Estimated Time:** 5 minutes

**Steps:**
1. Login as admin
2. Create or edit an event
3. Toggle between "Draft" and "Published" status
4. Save changes

**Expected Results:**
- Status changes successfully
- Draft events not visible to members
- Published events visible to members
- Status indicator displays correctly

**Pass/Fail:** ☐

---

#### TC-EVT-005: Duplicate Event
**Priority:** Medium  
**Estimated Time:** 5 minutes

**Steps:**
1. Login as admin
2. Navigate to Events page
3. Click on an event
4. Click "Duplicate" button
5. Review duplicated event
6. Save or modify as needed

**Expected Results:**
- Event is duplicated successfully
- All event details are copied
- New event has unique ID
- Date can be modified for new event
- Attendance records are NOT copied

**Pass/Fail:** ☐

---

### 4. Attendance & Check-In

#### TC-ATT-001: Check-In Member via Search
**Priority:** Critical  
**Estimated Time:** 10 minutes

**Steps:**
1. Login as admin
2. Navigate to Check-In page for an event
3. Search for member by name/email/phone
4. Select member from results
5. Click "Check In" button

**Expected Results:**
- Member is found in search
- Check-in is successful
- Success message displayed
- Attendance count increases
- Member appears in checked-in list
- Check-in timestamp recorded
- Real-time updates work

**Pass/Fail:** ☐

---

#### TC-ATT-002: Check-In via QR Code
**Priority:** High  
**Estimated Time:** 10 minutes

**Steps:**
1. Login as admin
2. Navigate to Check-In page for an event
3. Click "Scan QR Code" button
4. Scan member's QR code
5. Confirm check-in

**Expected Results:**
- QR code is scanned successfully
- Member is identified from QR code
- Check-in is processed
- Success message displayed
- Camera permissions work correctly

**Pass/Fail:** ☐

---

#### TC-ATT-003: Undo Check-In
**Priority:** High  
**Estimated Time:** 5 minutes

**Steps:**
1. Login as admin
2. Navigate to Check-In page for an event
3. Find a checked-in member
4. Click "Undo Check-In" button
5. Confirm action

**Expected Results:**
- Check-in is reversed
- Success message displayed
- Attendance count decreases
- Member removed from checked-in list
- Undo action is logged

**Pass/Fail:** ☐

---

#### TC-ATT-004: View Attendance List
**Priority:** High  
**Estimated Time:** 5 minutes

**Steps:**
1. Login as admin
2. Navigate to Check-In page for an event
3. View checked-in members list

**Expected Results:**
- All checked-in members are displayed
- List is sortable/searchable
- Check-in timestamps are shown
- List updates in real-time
- Export functionality works

**Pass/Fail:** ☐

---

#### TC-ATT-005: Check-In Duplicate Prevention
**Priority:** Medium  
**Estimated Time:** 5 minutes

**Steps:**
1. Login as admin
2. Check in a member for an event
3. Attempt to check in the same member again

**Expected Results:**
- Duplicate check-in is prevented
- Warning message displayed
- Member remains checked in once
- System handles gracefully

**Pass/Fail:** ☐

---

### 5. Payment Processing

#### TC-PAY-001: Create Paid Event
**Priority:** High  
**Estimated Time:** 10 minutes

**Steps:**
1. Login as admin
2. Create new event
3. Set event price > 0
4. Enable payment processing
5. Save event

**Expected Results:**
- Event is created with payment enabled
- Price is displayed correctly
- Payment button appears on event page
- Stripe integration is configured

**Pass/Fail:** ☐

---

#### TC-PAY-002: Member Payment Flow
**Priority:** Critical  
**Estimated Time:** 15 minutes

**Steps:**
1. Login as member (or access public event page)
2. Navigate to paid event
3. Click "Register & Pay" button
4. Complete Stripe payment form
5. Submit payment

**Expected Results:**
- Payment form loads correctly
- Stripe checkout works
- Payment is processed successfully
- Confirmation email sent
- Registration is recorded
- Payment record created
- Member can access event

**Pass/Fail:** ☐

---

#### TC-PAY-003: View Payment History
**Priority:** Medium  
**Estimated Time:** 5 minutes

**Steps:**
1. Login as admin
2. Navigate to Payments/Transfers page
3. View payment history

**Expected Results:**
- All payments are displayed
- Payment details are accurate
- Filters work correctly
- Export functionality works

**Pass/Fail:** ☐

---

### 6. Reporting & Exports

#### TC-RPT-001: Generate Attendance Report
**Priority:** High  
**Estimated Time:** 10 minutes

**Steps:**
1. Login as admin
2. Navigate to Reports page
3. Select report type: "Attendance Report"
4. Select date range
5. Select event(s)
6. Click "Generate Report"

**Expected Results:**
- Report is generated successfully
- Data is accurate
- Report is formatted correctly
- Export to CSV/PDF works
- Filters apply correctly

**Pass/Fail:** ☐

---

#### TC-RPT-002: Export Member List
**Priority:** Medium  
**Estimated Time:** 5 minutes

**Steps:**
1. Login as admin
2. Navigate to Members page
3. Apply filters (optional)
4. Click "Export" button
5. Select export format (CSV/Excel)

**Expected Results:**
- Export file is generated
- All member data is included
- Filters are respected
- File downloads successfully
- Data format is correct

**Pass/Fail:** ☐

---

### 7. Email Communications

#### TC-EMAIL-001: Send Event Announcement
**Priority:** High  
**Estimated Time:** 10 minutes

**Steps:**
1. Login as admin
2. Navigate to Events page
3. Select an event
4. Click "Send Announcement"
5. Select recipients
6. Customize message (optional)
7. Send email

**Expected Results:**
- Email is sent successfully
- Success message displayed
- Recipients receive email
- Email content is correct
- SMTP2GO integration works
- Email delivery is logged

**Pass/Fail:** ☐

---

#### TC-EMAIL-002: Send Event Reminder
**Priority:** Medium  
**Estimated Time:** 10 minutes

**Steps:**
1. Login as admin
2. Navigate to Events page
3. Select an event
4. Click "Send Reminder"
5. Select recipients (registered members)
6. Send reminder

**Expected Results:**
- Reminder is sent successfully
- Only registered members receive reminder
- Email content is appropriate
- Delivery is logged

**Pass/Fail:** ☐

---

### 8. Member Portal

#### TC-PORTAL-001: Member Views Dashboard
**Priority:** High  
**Estimated Time:** 5 minutes

**Steps:**
1. Login as member
2. Navigate to member portal dashboard

**Expected Results:**
- Dashboard loads successfully
- Upcoming events are displayed
- Member information is shown
- Navigation works correctly
- Member cannot access admin areas

**Pass/Fail:** ☐

---

#### TC-PORTAL-002: Member Views Events
**Priority:** High  
**Estimated Time:** 5 minutes

**Steps:**
1. Login as member
2. Navigate to Events page
3. Browse available events

**Expected Results:**
- Events list displays correctly
- Event details are shown
- Registration buttons work
- Filters work (if applicable)
- Member can only see published events

**Pass/Fail:** ☐

---

#### TC-PORTAL-003: Member Registers for Event
**Priority:** High  
**Estimated Time:** 10 minutes

**Steps:**
1. Login as member
2. Navigate to Events page
3. Click on an event
4. Click "Register" button
5. Confirm registration

**Expected Results:**
- Registration is successful
- Confirmation message displayed
- Registration appears in member's events
- Event capacity is updated (if applicable)
- Confirmation email sent

**Pass/Fail:** ☐

---

#### TC-PORTAL-004: Member Views Profile
**Priority:** Medium  
**Estimated Time:** 5 minutes

**Steps:**
1. Login as member
2. Navigate to Profile page
3. View profile information

**Expected Results:**
- Profile information displays correctly
- All member data is shown
- Edit functionality works
- Family relationships displayed (if applicable)

**Pass/Fail:** ☐

---

#### TC-PORTAL-005: Member Updates Profile
**Priority:** Medium  
**Estimated Time:** 8 minutes

**Steps:**
1. Login as member
2. Navigate to Profile page
3. Click "Edit" button
4. Update profile information
5. Save changes

**Expected Results:**
- Changes are saved successfully
- Success message displayed
- Updated information appears
- Changes are reflected in admin view

**Pass/Fail:** ☐

---

### 9. Admin Portal

#### TC-ADMIN-001: Admin Views Dashboard
**Priority:** High  
**Estimated Time:** 5 minutes

**Steps:**
1. Login as admin
2. Navigate to admin dashboard

**Expected Results:**
- Dashboard loads successfully
- Statistics are displayed correctly
- Recent activity is shown
- Quick actions are available
- Navigation works correctly

**Pass/Fail:** ☐

---

#### TC-ADMIN-002: Admin Views Activity Log
**Priority:** Medium  
**Estimated Time:** 5 minutes

**Steps:**
1. Login as admin
2. Navigate to Activity Log page
3. Review activity entries

**Expected Results:**
- Activity log displays correctly
- All activities are logged
- Filters work correctly
- Timestamps are accurate
- User actions are traceable

**Pass/Fail:** ☐

---

#### TC-ADMIN-003: Admin Manages Settings
**Priority:** High  
**Estimated Time:** 10 minutes

**Steps:**
1. Login as admin
2. Navigate to Settings page
3. Update organization settings
4. Update email settings
5. Update payment settings
6. Save changes

**Expected Results:**
- Settings are saved successfully
- Changes take effect immediately
- Validation works correctly
- Settings persist after logout/login

**Pass/Fail:** ☐

---

## Test Execution Checklist

### Pre-Testing
- [ ] Test environment is set up
- [ ] Test data is prepared
- [ ] All dependencies are installed
- [ ] Database is migrated to latest version
- [ ] Email service is configured
- [ ] Payment service is configured

### During Testing
- [ ] Execute all critical priority tests
- [ ] Execute all high priority tests
- [ ] Execute medium priority tests as time permits
- [ ] Document all defects found
- [ ] Take screenshots of issues
- [ ] Note browser/device for each test

### Post-Testing
- [ ] Compile test results
- [ ] Create defect report
- [ ] Review test coverage
- [ ] Document any blocked tests
- [ ] Provide recommendations

---

## Test Data Requirements

### Test Users
- Admin user: `admin@test.local` / `TestPass123!`
- Member user 1: `member1@test.local` / `TestPass123!`
- Member user 2: `member2@test.local` / `TestPass123!`
- Inactive member: `inactive@test.local` / `TestPass123!`

### Test Events
- Past event (yesterday)
- Current event (today)
- Future event (next week)
- Paid event ($10.00)
- Free event
- Event with capacity limit
- Draft event

### Test Data Files
- Valid CSV import file
- Invalid CSV import file
- Test banner images (various formats)

---

## Defect Reporting

### Defect Severity Levels

**Critical:** System crash, data loss, security breach, complete feature failure  
**High:** Major feature malfunction, significant data issue  
**Medium:** Minor feature issue, cosmetic problem  
**Low:** Typo, minor UI issue, enhancement suggestion

### Defect Report Template

```
**Defect ID:** DEF-001
**Test Case:** TC-MEM-001
**Severity:** High
**Priority:** High
**Status:** Open
**Reporter:** [Name]
**Date:** [Date]

**Summary:**
Brief description of the issue

**Steps to Reproduce:**
1. Step 1
2. Step 2
3. Step 3

**Expected Result:**
What should happen

**Actual Result:**
What actually happens

**Screenshots:**
[Attach screenshots if applicable]

**Browser/Device:**
Chrome 120 / Windows 11

**Additional Notes:**
Any additional context
```

---

## Test Metrics

### Coverage Metrics
- Total Test Cases: 50+
- Critical Priority: 15
- High Priority: 20
- Medium Priority: 15+

### Execution Metrics
- Tests Executed: ___
- Tests Passed: ___
- Tests Failed: ___
- Tests Blocked: ___
- Pass Rate: ___%

### Defect Metrics
- Critical Defects: ___
- High Defects: ___
- Medium Defects: ___
- Low Defects: ___
- Total Defects: ___

---

## Notes

- All tests should be executed in a test environment, never in production
- Test data should be reset between test runs if needed
- Document any environment-specific issues
- Keep detailed logs of all test executions
- Update this document as new test cases are identified

---

**Document Version:** 1.0  
**Last Updated:** 2024  
**Next Review:** After major feature releases
