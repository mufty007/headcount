<?php

namespace Headcount\Services;

use Headcount\Models\Event;
use Headcount\Helpers\Validator;

/**
 * Event Service
 * Business logic for event management
 */
class EventService
{
    private $eventModel;

    public function __construct()
    {
        $this->eventModel = new Event();
    }

    /**
     * Create event with validation
     */
    public function createEvent($data)
    {
        // Validate event data
        $errors = Validator::validateEvent($data);
        if (!empty($errors)) {
            throw new \Exception('Validation failed', 400);
        }

        // Create event
        $event = $this->eventModel->create($data);
        return $event;
    }

    /**
     * Update event with validation
     */
    public function updateEvent($id, $data)
    {
        // Check if event exists
        $existingEvent = $this->eventModel->find($id);
        if (!$existingEvent) {
            throw new \Exception('Event not found', 404);
        }

        // Validate event data
        $errors = Validator::validateEvent(array_merge($existingEvent, $data));
        if (!empty($errors)) {
            throw new \Exception('Validation failed', 400);
        }

        // Update event
        $event = $this->eventModel->update($id, $data);
        return $event;
    }

    /**
     * Duplicate event
     */
    public function duplicateEvent($id)
    {
        $event = $this->eventModel->duplicate($id);
        if (!$event) {
            throw new \Exception('Event not found', 404);
        }
        return $event;
    }

    /**
     * Get event by ID
     */
    public function getEvent($id)
    {
        $event = $this->eventModel->find($id);
        if (!$event) {
            throw new \Exception('Event not found', 404);
        }
        return $event;
    }

    /**
     * Get all events with filters
     */
    public function getEvents($organizationId, $filters = [], $page = 1, $perPage = 20)
    {
        $offset = ($page - 1) * $perPage;
        $events = $this->eventModel->getAll($organizationId, $filters, $perPage, $offset);
        $total = $this->eventModel->count($organizationId, $filters);

        return [
            'events' => $events,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($total / $perPage)
        ];
    }

    /**
     * Get upcoming events
     */
    public function getUpcomingEvents($organizationId, $limit = 10)
    {
        return $this->eventModel->getUpcoming($organizationId, $limit);
    }

    /**
     * Delete event (soft delete)
     */
    public function deleteEvent($id)
    {
        $event = $this->eventModel->find($id);
        if (!$event) {
            throw new \Exception('Event not found', 404);
        }

        // Soft delete by setting status to cancelled
        return $this->eventModel->update($id, ['status' => 'cancelled']);
    }
}
