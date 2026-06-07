<?php

namespace Headcount\Controllers;

use Headcount\Services\EventService;
use Headcount\Middleware\AuthMiddleware;
use Headcount\Helpers\Utilities;

/**
 * Event Controller
 */
class EventController
{
    private $eventService;

    public function __construct()
    {
        $this->eventService = new EventService();
    }

    /**
     * List events
     */
    public function index($filters = [], $page = 1)
    {
        AuthMiddleware::requireAdmin();
        
        $organizationId = AuthMiddleware::getOrganizationId();
        $result = $this->eventService->getEvents($organizationId, $filters, $page);

        if (self::isApiRequest()) {
            Utilities::jsonResponse(true, $result, 'Events retrieved successfully');
        }

        return $result;
    }

    /**
     * Get single event
     */
    public function show($id)
    {
        AuthMiddleware::requireAdmin();
        
        try {
            $event = $this->eventService->getEvent($id);
            
            // Verify event belongs to organization
            if ($event['organization_id'] != AuthMiddleware::getOrganizationId()) {
                throw new \Exception('Event not found', 404);
            }

            if (self::isApiRequest()) {
                Utilities::jsonResponse(true, $event, 'Event retrieved successfully');
            }

            return $event;
        } catch (\Exception $e) {
            if (self::isApiRequest()) {
                Utilities::jsonResponse(false, null, $e->getMessage(), [], $e->getCode() ?: 500);
            }
            throw $e;
        }
    }

    /**
     * Create event
     */
    public function create($data)
    {
        AuthMiddleware::requireAdmin();
        
        $data['organization_id'] = AuthMiddleware::getOrganizationId();
        $data['created_by'] = AuthMiddleware::getUserId();

        try {
            $event = $this->eventService->createEvent($data);

            if (self::isApiRequest()) {
                Utilities::jsonResponse(true, $event, 'Event created successfully');
            }

            return $event;
        } catch (\Exception $e) {
            if (self::isApiRequest()) {
                Utilities::jsonResponse(false, null, $e->getMessage(), [], $e->getCode() ?: 500);
            }
            throw $e;
        }
    }

    /**
     * Update event
     */
    public function update($id, $data)
    {
        AuthMiddleware::requireAdmin();
        
        try {
            // Verify event belongs to organization
            $event = $this->eventService->getEvent($id);
            if ($event['organization_id'] != AuthMiddleware::getOrganizationId()) {
                throw new \Exception('Event not found', 404);
            }

            $event = $this->eventService->updateEvent($id, $data);

            if (self::isApiRequest()) {
                Utilities::jsonResponse(true, $event, 'Event updated successfully');
            }

            return $event;
        } catch (\Exception $e) {
            if (self::isApiRequest()) {
                Utilities::jsonResponse(false, null, $e->getMessage(), [], $e->getCode() ?: 500);
            }
            throw $e;
        }
    }

    /**
     * Delete event
     */
    public function delete($id)
    {
        AuthMiddleware::requireAdmin();
        
        try {
            // Verify event belongs to organization
            $event = $this->eventService->getEvent($id);
            if ($event['organization_id'] != AuthMiddleware::getOrganizationId()) {
                throw new \Exception('Event not found', 404);
            }

            $this->eventService->deleteEvent($id);

            if (self::isApiRequest()) {
                Utilities::jsonResponse(true, null, 'Event deleted successfully');
            }

            return ['success' => true];
        } catch (\Exception $e) {
            if (self::isApiRequest()) {
                Utilities::jsonResponse(false, null, $e->getMessage(), [], $e->getCode() ?: 500);
            }
            throw $e;
        }
    }

    /**
     * Duplicate event
     */
    public function duplicate($id)
    {
        AuthMiddleware::requireAdmin();
        
        try {
            $event = $this->eventService->duplicateEvent($id);

            if (self::isApiRequest()) {
                Utilities::jsonResponse(true, $event, 'Event duplicated successfully');
            }

            return $event;
        } catch (\Exception $e) {
            if (self::isApiRequest()) {
                Utilities::jsonResponse(false, null, $e->getMessage(), [], $e->getCode() ?: 500);
            }
            throw $e;
        }
    }

    /**
     * Check if request is API request
     */
    private static function isApiRequest()
    {
        return strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;
    }
}
