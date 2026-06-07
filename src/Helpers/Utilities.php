<?php

namespace Headcount\Helpers;

/**
 * Utilities Helper Class
 * Provides utility functions
 */
class Utilities
{
    /**
     * Format date for display
     */
    public static function formatDate($date, $format = 'F j, Y')
    {
        if (empty($date)) {
            return '';
        }
        try {
            $dateObj = new \DateTime($date);
            return $dateObj->format($format);
        } catch (\Exception $e) {
            return $date;
        }
    }

    /**
     * Format time for display
     */
    public static function formatTime($time, $format = 'g:i A')
    {
        if (empty($time)) {
            return '';
        }
        try {
            $timeObj = \DateTime::createFromFormat('H:i:s', $time);
            if (!$timeObj) {
                $timeObj = \DateTime::createFromFormat('H:i', $time);
            }
            if ($timeObj) {
                return $timeObj->format($format);
            }
            return $time;
        } catch (\Exception $e) {
            return $time;
        }
    }

    /**
     * Format datetime for display
     */
    public static function formatDateTime($datetime, $format = 'F j, Y g:i A')
    {
        if (empty($datetime)) {
            return '';
        }
        try {
            $dateObj = new \DateTime($datetime);
            return $dateObj->format($format);
        } catch (\Exception $e) {
            return $datetime;
        }
    }

    /**
     * Format currency
     */
    public static function formatCurrency($amount, $currency = 'USD')
    {
        return '$' . number_format((float)$amount, 2);
    }

    /**
     * Format phone number
     */
    public static function formatPhone($phone)
    {
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) == 10) {
            return '(' . substr($digits, 0, 3) . ') ' . substr($digits, 3, 3) . '-' . substr($digits, 6);
        }
        return $phone;
    }

    /**
     * Generate slug from string
     */
    public static function slugify($text)
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9-]/', '-', $text);
        $text = preg_replace('/-+/', '-', $text);
        return trim($text, '-');
    }

    /**
     * Truncate string
     */
    public static function truncate($string, $length = 100, $suffix = '...')
    {
        if (strlen($string) <= $length) {
            return $string;
        }
        return substr($string, 0, $length) . $suffix;
    }

    /**
     * Get relative time (e.g., "2 hours ago")
     */
    public static function timeAgo($datetime)
    {
        if (empty($datetime)) {
            return '';
        }

        try {
            $time = new \DateTime($datetime);
            $now = new \DateTime();
            $diff = $now->diff($time);

            if ($diff->y > 0) {
                return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
            } elseif ($diff->m > 0) {
                return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
            } elseif ($diff->d > 0) {
                return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
            } elseif ($diff->h > 0) {
                return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
            } elseif ($diff->i > 0) {
                return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
            } else {
                return 'just now';
            }
        } catch (\Exception $e) {
            return $datetime;
        }
    }

    /**
     * Generate pagination data
     */
    public static function paginate($total, $page = 1, $perPage = 20)
    {
        $page = max(1, (int)$page);
        $perPage = max(1, (int)$perPage);
        $totalPages = ceil($total / $perPage);
        $offset = ($page - 1) * $perPage;

        return [
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
            'offset' => $offset,
            'has_prev' => $page > 1,
            'has_next' => $page < $totalPages,
        ];
    }

    /**
     * Get JSON response
     */
    public static function jsonResponse($success, $data = null, $message = '', $errors = [], $statusCode = 200)
    {
        // Clear any output that may have been generated
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        http_response_code($statusCode);
        header('Content-Type: application/json', true);
        echo json_encode([
            'success' => $success,
            'data' => $data,
            'message' => $message,
            'errors' => $errors
        ], JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * Redirect to URL
     */
    public static function redirect($url)
    {
        header("Location: $url");
        exit;
    }

    /**
     * Get base URL
     */
    public static function getBaseUrl()
    {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $basePath = dirname($script);
        
        if ($basePath === '/' || $basePath === '\\') {
            $basePath = '';
        }
        
        return $protocol . '://' . $host . $basePath;
    }

    /**
     * Get current URL
     */
    public static function getCurrentUrl()
    {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        return $protocol . '://' . $host . $uri;
    }

    /**
     * Capitalize name properly (handles names like "Mary Jane", "O'Brien", etc.)
     * 
     * @param string $name The name to capitalize
     * @return string Properly capitalized name
     */
    public static function capitalizeName($name)
    {
        if (empty($name)) {
            return '';
        }
        
        // Trim whitespace
        $name = trim($name);
        
        // Convert to lowercase first
        $name = mb_strtolower($name, 'UTF-8');
        
        // Split by spaces to handle multiple words (e.g., "Mary Jane")
        $words = preg_split('/\s+/', $name);
        
        // Capitalize each word
        $capitalized = [];
        foreach ($words as $word) {
            if (empty($word)) {
                continue;
            }
            
            // Handle special cases like O'Brien, D'Angelo, etc.
            if (preg_match("/^([a-z]+)['-]([a-z]+)$/i", $word, $matches)) {
                // Name with apostrophe or hyphen
                $capitalized[] = mb_convert_case($matches[1], MB_CASE_TITLE, 'UTF-8') . 
                                $word[strpos($word, $matches[2]) - 1] . 
                                mb_convert_case($matches[2], MB_CASE_TITLE, 'UTF-8');
            } else {
                // Regular word - capitalize first letter
                $capitalized[] = mb_convert_case($word, MB_CASE_TITLE, 'UTF-8');
            }
        }
        
        return implode(' ', $capitalized);
    }

    /**
     * Decode HTML entities for plain-text fields (e.g. titles) stored with htmlspecialchars or CMS encoding,
     * so values like "&amp;" become "&" before JSON output or Stripe metadata.
     */
    public static function decodeHtmlEntities($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $s = is_string($value) ? $value : (string) $value;
        return trim(html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Decode HTML entities on common event text columns after a DB row load (admin UI, reports).
     * Mutates the row in place. Implemented fully here (not only via helpers) so the method
     * always exists on this class even if src/helpers.php or Composer file autoload is stale.
     */
    public static function decodeHtmlEntitiesInEventRow(array &$event): void
    {
        foreach (['title', 'location'] as $k) {
            if (isset($event[$k]) && $event[$k] !== null && $event[$k] !== '') {
                $event[$k] = self::decodeHtmlEntities($event[$k]);
            }
        }
        if (isset($event['description']) && $event['description'] !== null && $event['description'] !== '') {
            $event['description'] = self::decodeHtmlEntities($event['description']);
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public static function decodeHtmlEntitiesInEventRows(array &$rows): void
    {
        foreach ($rows as &$row) {
            if (is_array($row)) {
                self::decodeHtmlEntitiesInEventRow($row);
            }
        }
        unset($row);
    }
}
