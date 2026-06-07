<?php
namespace Headcount\Core;

/**
 * Stack-based context for the current event during loop / Elementor template rendering.
 */
class EventLoopContext {

    /** @var array<int, array> */
    private static $stack = array();

    /** @var APIClient|null */
    private static $api_client = null;

    public static function set_api_client(APIClient $client) {
        self::$api_client = $client;
    }

    public static function push(array $event) {
        self::$stack[] = $event;
    }

    public static function pop() {
        array_pop(self::$stack);
    }

    /**
     * @return array|null
     */
    public static function current() {
        if (empty(self::$stack)) {
            return null;
        }
        return self::$stack[count(self::$stack) - 1];
    }

    public static function depth() {
        return count(self::$stack);
    }

    /**
     * Resolve event by ID (formatted) for field shortcodes outside a loop.
     *
     * @param int|string $id
     * @return array|null
     */
    public static function get_event_by_id($id) {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }

        foreach (self::$stack as $event) {
            if ((int) ($event['id'] ?? 0) === $id) {
                return $event;
            }
        }

        if (self::$api_client === null) {
            return null;
        }

        $response = self::$api_client->get_event($id);
        if (empty($response['success']) || empty($response['event'])) {
            return null;
        }

        $presenter = new EventPresenter();
        $formatted = $presenter->format_events(array($response['event']));
        return $formatted[0] ?? null;
    }
}
