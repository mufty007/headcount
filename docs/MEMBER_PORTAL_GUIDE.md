# Headcount Events Platform - Member Portal Guide

## Getting Started

### Creating an Account

1. Go to **Member Portal** (`/portal/`)
2. Click **"Register"**
3. Fill in your information:
   - First name
   - Last name
   - Email
   - Phone (optional)
   - Password
4. Click **"Create Account"**
5. Check your email for confirmation

### Logging In

**Method 1: Email and Password**
1. Go to `/portal/login.php`
2. Enter email and password
3. Check "Remember me" to stay logged in
4. Click "Log In"

**Method 2: Magic Link**
1. Go to `/portal/login.php`
2. Enter your email
3. Click "Send Magic Link"
4. Check your email and click the link
5. You'll be automatically logged in

## Browsing Events

### Viewing Events

1. Go to **Events** page
2. See all upcoming published events
3. Click event to see details

### Event Details

Each event shows:
- Date and time
- Location
- Description
- Price (if paid event)
- RSVP status
- Attendee count

### RSVPing for Events

**For Yourself:**
1. Go to event details page
2. Click **"RSVP Now"** or **"Claim Free Spot"**
3. Confirmation message appears

**For Family Members:**
1. Go to event details page
2. Click **"RSVP Now"**
3. If you have family members, a selection modal appears
4. Check boxes for family members to RSVP
5. Click **"Confirm RSVP"**

### Paid Events

1. Click **"Secure Ticket"** button
2. You'll be redirected to Stripe checkout
3. Complete payment
4. RSVP is automatically created
5. Receipt sent to your email

## Managing Your RSVPs

### Viewing Your RSVPs

1. Go to **My RSVPs** page
2. See all events you've RSVPed for
3. Filter by status (Yes, No, Maybe)
4. See upcoming and past events

### Cancelling RSVP

1. Go to **My RSVPs** page
2. Find the event
3. Click **"Cancel RSVP"**
4. Confirm cancellation

## Your Profile

### Updating Profile

1. Go to **Profile** page
2. Click **"Edit Profile"**
3. Update your information:
   - Name
   - Email
   - Phone
   - Profile photo
4. Click **"Save Changes"**

### Communication Preferences

1. Go to **Profile** page
2. Scroll to **"Communication Preferences"**
3. Choose which emails you want to receive:
   - Event reminders
   - Event announcements
   - RSVP confirmations
4. Save preferences

## Family Members

### Adding Family Members

1. Go to **Family** page
2. Click **"Add Family Member"**
3. Enter information:
   - First name
   - Last name
   - Date of birth (optional)
   - Relationship
4. Click **"Save"**

### RSVPing for Family

When RSVPing for events:
- Select family members in the RSVP modal
- Family members with linked accounts get their own RSVP
- Others are included in your RSVP notes

## QR Code

### Viewing Your QR Code

1. Go to **QR Code** page
2. Your QR code is displayed
3. Download or screenshot to save
4. Show at event check-in

### Using QR Code

- QR codes expire after 24 hours
- New QR code is generated each time you visit the page
- Show QR code to admin at event for quick check-in

## Payments

### Viewing Payment History

1. Go to **Payments** page
2. See all your payments
3. Click payment to view receipt
4. Download receipt as needed

### Payment Receipts

- Automatically generated after payment
- Sent to your email
- Available in Payments page
- Includes event details and payment information

## Calendar Integration

### Adding to Calendar

1. Go to event details page
2. Click calendar icon
3. Choose calendar type:
   - Google Calendar
   - Apple Calendar
   - Outlook
   - Download ICS file
4. Event is added to your calendar

## Event Feedback

### Submitting Feedback

1. Go to event details page (after event)
2. Scroll to **"Event Feedback"**
3. Rate the event (1-5 stars)
4. Write comments (optional)
5. Click **"Submit Feedback"**

## Troubleshooting

### Can't log in?

- Check your email and password
- Try "Forgot Password" to reset
- Use magic link if password doesn't work
- Contact administrator if issues persist

### QR code not working?

- QR codes expire after 24 hours
- Visit QR Code page to generate new one
- Ensure QR code is clear and not damaged

### Can't RSVP?

- Event may be at full capacity
- Check event date hasn't passed
- Verify you're logged in
- Try refreshing the page

### Payment issues?

- Check Stripe payment was completed
- Verify email for receipt
- Contact administrator with payment ID
- Check Payments page for status
