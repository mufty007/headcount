# Headcount Events Platform - API Documentation

## Overview

The Headcount Events Platform provides a RESTful API for managing events, members, attendance, and payments. All API endpoints return JSON responses.

## Base URL

- Development: `http://localhost/Headcount/api/`
- Production: `https://yourdomain.com/api/`

## Authentication

Most API endpoints require authentication. There are two authentication methods:

### Admin Authentication
- Session-based authentication
- Required for admin endpoints
- Set via `AuthMiddleware::requireAdmin()`

### Portal Authentication
- Session-based authentication for members
- Required for portal endpoints
- Set via `PortalAuthMiddleware::requireAuth()`

## Endpoints

### Authentication

#### POST /api/auth/login
Admin login

**Request:**
```json
{
  "email": "admin@example.com",
  "password": "password",
  "remember_me": false
}
```

**Response:**
```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "user_id": 1,
    "name": "Admin User"
  }
}
```

### Events

#### GET /api/events
List all events for organization

**Query Parameters:**
- `page` (int): Page number (default: 1)
- `per_page` (int): Items per page (default: 20)
- `status` (string): Filter by status (draft, published, cancelled, completed)
- `category` (string): Filter by category

**Response:**
```json
{
  "success": true,
  "events": [...],
  "total": 50,
  "page": 1,
  "per_page": 20,
  "total_pages": 3
}
```

#### GET /api/events/{id}
Get event details

**Response:**
```json
{
  "success": true,
  "event": {
    "id": 1,
    "title": "Event Title",
    "event_date": "2024-12-25",
    "start_time": "10:00:00",
    "location": "Event Location",
    ...
  }
}
```

#### POST /api/events
Create new event

**Request:**
```json
{
  "title": "Event Title",
  "description": "Event description",
  "event_date": "2024-12-25",
  "start_time": "10:00:00",
  "end_time": "12:00:00",
  "location": "Event Location",
  "category": "worship",
  "capacity": 100,
  "ticket_price": 0.00,
  "registration_required": true
}
```

### Members

#### GET /api/members/search?q={query}&event_id={id}
Search members for check-in

**Query Parameters:**
- `q` (string, required): Search query (min 2 characters)
- `event_id` (int, required): Event ID

**Response:**
```json
{
  "success": true,
  "members": [
    {
      "id": 1,
      "first_name": "John",
      "last_name": "Doe",
      "email": "john@example.com",
      "phone": "1234567890",
      "checked_in": false,
      "checked_in_at": null
    }
  ]
}
```

#### POST /api/members
Create new member

**Request:**
```json
{
  "first_name": "John",
  "last_name": "Doe",
  "email": "john@example.com",
  "phone": "1234567890",
  "gender": "male"
}
```

### Attendance

#### POST /api/checkin.php
Check in a member

**Request:**
```json
{
  "event_id": 1,
  "user_id": 2
}
```

**Response:**
```json
{
  "success": true,
  "message": "Check-in successful",
  "attendance_id": 123
}
```

#### POST /api/portal/checkin.php
Check in via QR code (admin only)

**Request:**
```json
{
  "qr_code": "base64data|hash",
  "event_id": 1,
  "family_member_id": 5
}
```

### RSVPs

#### POST /api/portal/rsvps
Create RSVP

**Request:**
```json
{
  "event_id": 1,
  "guests": 2,
  "family_member_ids": [5, 6]
}
```

**Response:**
```json
{
  "success": true,
  "message": "RSVP created successfully",
  "rsvp": {...},
  "family_rsvps": [...]
}
```

### Payments

#### POST /api/portal/payments/checkout
Create Stripe checkout session

**Request:**
```json
{
  "event_id": 1,
  "family_member_ids": [5]
}
```

**Response:**
```json
{
  "success": true,
  "checkout_url": "https://checkout.stripe.com/..."
}
```

## Error Responses

All errors follow this format:

```json
{
  "success": false,
  "message": "Error message",
  "errors": [
    {
      "field": "email",
      "message": "Invalid email format"
    }
  ]
}
```

## Status Codes

- `200` - Success
- `400` - Bad Request
- `401` - Unauthorized
- `403` - Forbidden
- `404` - Not Found
- `500` - Internal Server Error

## Rate Limiting

- Login endpoints: 5 attempts per 15 minutes
- API endpoints: 100 requests per minute per IP

## CSRF Protection

All state-changing operations (POST, PUT, DELETE) require a CSRF token:

- Header: `X-CSRF-Token: {token}`
- Or in body: `csrf_token: {token}`

Get token from: `GET /api/csrf-token`
