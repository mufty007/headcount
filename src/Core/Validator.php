<?php

namespace Headcount\Core;

/**
 * Validator Class
 * Handles input validation for forms and API requests
 */
class Validator
{
    /**
     * Validate email format
     */
    public static function email($email)
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Validate phone number
     */
    public static function phone($phone)
    {
        // Remove all non-digit characters
        $digits = preg_replace('/\D/', '', $phone);
        // Validate length (10-15 digits)
        return preg_match('/^\d{10,15}$/', $digits);
    }

    /**
     * Check if value is required (not empty)
     */
    public static function required($value)
    {
        return !empty(trim($value));
    }

    /**
     * Validate date format (Y-m-d)
     */
    public static function date($date)
    {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }

    /**
     * Validate time format (H:i:s or H:i)
     */
    public static function time($time)
    {
        $t = \DateTime::createFromFormat('H:i:s', $time);
        if (!$t) {
            $t = \DateTime::createFromFormat('H:i', $time);
        }
        return $t !== false;
    }

    /**
     * Validate date is not in the past
     */
    public static function dateNotPast($date)
    {
        if (!self::date($date)) {
            return false;
        }
        $eventDate = new \DateTime($date);
        $today = new \DateTime('today');
        return $eventDate >= $today;
    }

    /**
     * Validate end time is after start time
     */
    public static function timeAfter($endTime, $startTime)
    {
        if (!self::time($endTime) || !self::time($startTime)) {
            return false;
        }
        $end = \DateTime::createFromFormat('H:i:s', $endTime);
        $start = \DateTime::createFromFormat('H:i:s', $startTime);
        if (!$end) {
            $end = \DateTime::createFromFormat('H:i', $endTime);
        }
        if (!$start) {
            $start = \DateTime::createFromFormat('H:i', $startTime);
        }
        return $end > $start;
    }

    /**
     * Validate price (decimal between 1 and 10000)
     */
    public static function price($price)
    {
        $price = floatval($price);
        return $price >= 1 && $price <= 10000;
    }

    /**
     * Validate event data
     */
    public static function validateEvent($data)
    {
        $errors = [];

        if (!self::required($data['title'] ?? '')) {
            $errors[] = ['field' => 'title', 'message' => 'Title is required'];
        }

        if (!self::date($data['event_date'] ?? '')) {
            $errors[] = ['field' => 'event_date', 'message' => 'Invalid event date format'];
        } elseif (!self::dateNotPast($data['event_date'])) {
            $errors[] = ['field' => 'event_date', 'message' => 'Event date cannot be in the past'];
        }

        if (isset($data['start_time']) && isset($data['end_time'])) {
            if (!self::time($data['start_time'])) {
                $errors[] = ['field' => 'start_time', 'message' => 'Invalid start time format'];
            }
            if (!self::time($data['end_time'])) {
                $errors[] = ['field' => 'end_time', 'message' => 'Invalid end time format'];
            }
            if (self::time($data['start_time']) && self::time($data['end_time'])) {
                if (!self::timeAfter($data['end_time'], $data['start_time'])) {
                    $errors[] = ['field' => 'end_time', 'message' => 'End time must be after start time'];
                }
            }
        }

        if (isset($data['is_paid']) && $data['is_paid'] && isset($data['ticket_price'])) {
            if (!self::price($data['ticket_price'])) {
                $errors[] = ['field' => 'ticket_price', 'message' => 'Ticket price must be between $1 and $10,000'];
            }
        }

        return $errors;
    }

    /**
     * Validate member data
     */
    public static function validateMember($data)
    {
        $errors = [];

        if (!self::required($data['first_name'] ?? '')) {
            $errors[] = ['field' => 'first_name', 'message' => 'First name is required'];
        }

        if (!self::required($data['last_name'] ?? '')) {
            $errors[] = ['field' => 'last_name', 'message' => 'Last name is required'];
        }

        if (isset($data['email']) && !empty($data['email'])) {
            if (!self::email($data['email'])) {
                $errors[] = ['field' => 'email', 'message' => 'Invalid email format'];
            }
        }

        if (isset($data['phone']) && !empty($data['phone'])) {
            if (!self::phone($data['phone'])) {
                $errors[] = ['field' => 'phone', 'message' => 'Invalid phone number format'];
            }
        }

        return $errors;
    }
}
