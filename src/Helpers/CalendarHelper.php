<?php

namespace Headcount\Helpers;

/**
 * Calendar Helper
 * Generates calendar links and ICS files
 */
class CalendarHelper
{
    /**
     * Generate ICS file content for event
     * 
     * @param array $event Event data
     * @param array $rsvp RSVP data (optional)
     * @return string ICS file content
     */
    public static function generateICS($event, $rsvp = null)
    {
        $eventDate = $event['event_date'] ?? date('Y-m-d');
        $startTime = $event['start_time'] ?? '00:00:00';
        $endTime = $event['end_time'] ?? '23:59:59';
        
        // Combine date and time
        $startDateTime = $eventDate . ' ' . $startTime;
        $endDateTime = $eventDate . ' ' . $endTime;
        
        // Format for ICS (YYYYMMDDTHHMMSS)
        $dtstart = date('Ymd\THis', strtotime($startDateTime));
        $dtend = date('Ymd\THis', strtotime($endDateTime));
        $dtstamp = date('Ymd\THis'); // Current timestamp
        
        // Escape text for ICS format
        $title = self::escapeICS($event['title'] ?? 'Event');
        $description = self::escapeICS($event['description'] ?? '');
        $location = self::escapeICS($event['location'] ?? '');
        
        // Generate unique ID
        $uid = 'event-' . ($event['id'] ?? time()) . '@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        
        $ics = "BEGIN:VCALENDAR\r\n";
        $ics .= "VERSION:2.0\r\n";
        $ics .= "PRODID:-//Headcount Events//Event Calendar//EN\r\n";
        $ics .= "CALSCALE:GREGORIAN\r\n";
        $ics .= "METHOD:REQUEST\r\n";
        $ics .= "BEGIN:VEVENT\r\n";
        $ics .= "UID:$uid\r\n";
        $ics .= "DTSTAMP:$dtstamp\r\n";
        $ics .= "DTSTART:$dtstart\r\n";
        $ics .= "DTEND:$dtend\r\n";
        $ics .= "SUMMARY:$title\r\n";
        $ics .= "DESCRIPTION:$description\r\n";
        $ics .= "LOCATION:$location\r\n";
        $ics .= "STATUS:CONFIRMED\r\n";
        $ics .= "SEQUENCE:0\r\n";
        $ics .= "BEGIN:VALARM\r\n";
        $ics .= "TRIGGER:-PT1H\r\n";
        $ics .= "ACTION:DISPLAY\r\n";
        $ics .= "DESCRIPTION:Reminder: $title\r\n";
        $ics .= "END:VALARM\r\n";
        $ics .= "END:VEVENT\r\n";
        $ics .= "END:VCALENDAR\r\n";
        
        return $ics;
    }

    /**
     * Generate Google Calendar URL
     * 
     * @param array $event Event data
     * @return string Google Calendar URL
     */
    public static function getGoogleCalendarLink($event)
    {
        $eventDate = $event['event_date'] ?? date('Y-m-d');
        $startTime = $event['start_time'] ?? '00:00';
        $endTime = $event['end_time'] ?? '23:59';
        
        // Format dates for Google Calendar
        $start = date('Ymd\THis', strtotime($eventDate . ' ' . $startTime));
        $end = date('Ymd\THis', strtotime($eventDate . ' ' . $endTime));
        
        $params = [
            'action' => 'TEMPLATE',
            'text' => urlencode($event['title'] ?? 'Event'),
            'dates' => $start . '/' . $end,
            'details' => urlencode($event['description'] ?? ''),
            'location' => urlencode($event['location'] ?? ''),
            'sf' => 'true',
            'output' => 'xml'
        ];
        
        return 'https://calendar.google.com/calendar/render?' . http_build_query($params);
    }

    /**
     * Generate Apple Calendar URL (webcal://)
     * 
     * @param array $event Event data
     * @param string $baseUrl Base URL of the application
     * @return string Apple Calendar URL
     */
    public static function getAppleCalendarLink($event, $baseUrl)
    {
        // Apple Calendar uses webcal:// protocol with ICS file
        $eventId = $event['id'] ?? 0;
        return $baseUrl . '/api/portal/calendar/event/' . $eventId . '.ics';
    }

    /**
     * Escape text for ICS format
     * 
     * @param string $text Text to escape
     * @return string Escaped text
     */
    private static function escapeICS($text)
    {
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace(',', '\\,', $text);
        $text = str_replace(';', '\\;', $text);
        $text = str_replace("\n", '\\n', $text);
        return $text;
    }
}
