<?php

namespace Headcount\Helpers;

/**
 * Validator Helper Class
 * Provides input validation functions
 */
class Validator
{
    /**
     * Validate email format (RFC 5321 max length 254; local part + @ + domain)
     */
    public static function email($email)
    {
        if ($email === null || $email === '') {
            return false;
        }
        if (!is_string($email)) {
            $email = (string) $email;
        }
        $email = trim($email);
        if ($email === '' || strlen($email) > 254) {
            return false;
        }
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Extract lowercase domain from an email address.
     */
    public static function emailDomain($email): ?string
    {
        if (!is_string($email)) {
            return null;
        }
        $email = trim(strtolower($email));
        $at = strrpos($email, '@');
        if ($at === false || $at === 0 || $at === strlen($email) - 1) {
            return null;
        }
        $domain = substr($email, $at + 1);
        return $domain !== '' ? $domain : null;
    }

    /**
     * Whether the email uses a known disposable / temporary domain.
     */
    public static function isDisposableEmail($email): bool
    {
        $domain = self::emailDomain($email);
        if ($domain === null) {
            return false;
        }

        static $blocked = null;
        if ($blocked === null) {
            $path = dirname(__DIR__, 2) . '/config/disposable-email-domains.php';
            $list = is_file($path) ? require $path : [];
            $blocked = [];
            if (is_array($list)) {
                foreach ($list as $d) {
                    if (is_string($d) && $d !== '') {
                        $blocked[strtolower(trim($d))] = true;
                    }
                }
            }
        }

        if (isset($blocked[$domain])) {
            return true;
        }

        // Match parent domains (e.g. foo.mailinator.com)
        $parts = explode('.', $domain);
        while (count($parts) > 2) {
            array_shift($parts);
            $parent = implode('.', $parts);
            if (isset($blocked[$parent])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the email domain has MX (or A/AAAA fallback) DNS records.
     */
    public static function emailDomainAcceptsMail($email): bool
    {
        $domain = self::emailDomain($email);
        if ($domain === null || !self::email($email)) {
            return false;
        }

        if (@checkdnsrr($domain, 'MX')) {
            return true;
        }
        if (@checkdnsrr($domain, 'A')) {
            return true;
        }
        if (@checkdnsrr($domain, 'AAAA')) {
            return true;
        }

        return false;
    }

    /**
     * Validate phone number
     */
    public static function phone($phone)
    {
        // Remove all non-digit characters
        $digits = preg_replace('/\D/', '', $phone);
        // Check if length is between 10 and 15 digits
        return strlen($digits) >= 10 && strlen($digits) <= 15;
    }

    /**
     * Check if value is required (not empty)
     */
    public static function required($value)
    {
        if (is_array($value)) {
            return !empty($value);
        }
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
    public static function endTimeAfterStart($startTime, $endTime)
    {
        if (!self::time($startTime) || !self::time($endTime)) {
            return false;
        }
        $start = new \DateTime($startTime);
        $end = new \DateTime($endTime);
        return $end > $start;
    }

    /**
     * Validate decimal/price
     */
    public static function decimal($value, $min = 0, $max = 10000)
    {
        if (!is_numeric($value)) {
            return false;
        }
        $value = (float)$value;
        return $value >= $min && $value <= $max;
    }

    /**
     * Validate integer
     */
    public static function integer($value, $min = null, $max = null)
    {
        if (!is_numeric($value) || (int)$value != $value) {
            return false;
        }
        $value = (int)$value;
        if ($min !== null && $value < $min) {
            return false;
        }
        if ($max !== null && $value > $max) {
            return false;
        }
        return true;
    }

    /**
     * Validate string length
     */
    public static function length($value, $min = null, $max = null)
    {
        $length = strlen($value);
        if ($min !== null && $length < $min) {
            return false;
        }
        if ($max !== null && $length > $max) {
            return false;
        }
        return true;
    }

    /**
     * Validate event data
     */
    public static function validateEvent($data)
    {
        $errors = [];

        if (!self::required($data['title'] ?? '')) {
            $errors[] = ['field' => 'title', 'message' => 'Event title is required'];
        }

        if (!self::date($data['event_date'] ?? '')) {
            $errors[] = ['field' => 'event_date', 'message' => 'Invalid event date format'];
        } elseif (!self::dateNotPast($data['event_date'])) {
            $errors[] = ['field' => 'event_date', 'message' => 'Event date cannot be in the past'];
        }

        if (!self::time($data['start_time'] ?? '')) {
            $errors[] = ['field' => 'start_time', 'message' => 'Invalid start time format'];
        }

        if (!empty($data['end_time']) && !self::time($data['end_time'])) {
            $errors[] = ['field' => 'end_time', 'message' => 'Invalid end time format'];
        } elseif (!empty($data['end_time']) && !empty($data['start_time'])) {
            if (!self::endTimeAfterStart($data['start_time'], $data['end_time'])) {
                $errors[] = ['field' => 'end_time', 'message' => 'End time must be after start time'];
            }
        }

        if (!empty($data['is_paid']) && $data['is_paid'] == 1) {
            if (!self::decimal($data['price'] ?? 0, 1, 10000)) {
                $errors[] = ['field' => 'price', 'message' => 'Price must be between $1 and $10,000'];
            }
        }

        return $errors;
    }

    /**
     * Validate member/user data
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

        if (!self::required($data['email'] ?? '')) {
            $errors[] = ['field' => 'email', 'message' => 'Email is required'];
        } elseif (!self::email($data['email'])) {
            $errors[] = ['field' => 'email', 'message' => 'Invalid email format'];
        }

        if (!empty($data['phone']) && !self::phone($data['phone'])) {
            $errors[] = ['field' => 'phone', 'message' => 'Invalid phone number format'];
        }

        return $errors;
    }

    /**
     * Validate ID (positive integer)
     */
    public static function id($value)
    {
        return self::integer($value, 1);
    }

    /**
     * Validate array
     */
    public static function isArray($value)
    {
        return is_array($value);
    }

    /**
     * Validate array contains only IDs
     */
    public static function idArray($value)
    {
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (!self::id($item)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Validate file upload
     */
    public static function fileUpload($file, $allowedTypes = [], $maxSize = 5242880)
    {
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return false;
        }

        if ($file['size'] > $maxSize) {
            return false;
        }

        if (!empty($allowedTypes)) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mimeType, $allowedTypes)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate URL
     */
    public static function url($url)
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Validate alphanumeric string (for subdirectory names, etc.)
     */
    public static function alphanumeric($value, $allowUnderscore = true, $allowHyphen = true)
    {
        $pattern = '/^[a-zA-Z0-9';
        if ($allowUnderscore) {
            $pattern .= '_';
        }
        if ($allowHyphen) {
            $pattern .= '-';
        }
        $pattern .= ']+$/';
        return preg_match($pattern, $value) === 1;
    }

    /**
     * Validate and sanitize GET parameter
     */
    public static function getParam($key, $type = 'string', $default = null)
    {
        $value = $_GET[$key] ?? $default;
        
        if ($value === null) {
            return $default;
        }

        switch ($type) {
            case 'int':
            case 'integer':
                return self::integer($value) ? (int)$value : $default;
            case 'id':
                return self::id($value) ? (int)$value : $default;
            case 'email':
                return self::email($value) ? $value : $default;
            case 'url':
                return self::url($value) ? $value : $default;
            default:
                return Security::sanitizeInput($value);
        }
    }

    /**
     * Sanitize and validate input array
     */
    public static function sanitizeArray($data)
    {
        $sanitized = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = self::sanitizeArray($value);
            } else {
                $sanitized[$key] = Security::sanitizeInput($value);
            }
        }
        return $sanitized;
    }
}
