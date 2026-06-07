<?php
namespace Headcount\Core;

/**
 * Stack-based context for the current facility during loop rendering.
 */
class FacilityLoopContext {

    /** @var array<int, array> */
    private static $stack = array();

    /** @var APIClient|null */
    private static $api_client = null;

    public static function set_api_client(APIClient $client) {
        self::$api_client = $client;
    }

    public static function push(array $facility) {
        self::$stack[] = $facility;
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

    /**
     * @param int|string $id
     * @param string     $slug
     * @return array|null
     */
    public static function get_facility_by_id_or_slug($id = '', $slug = '') {
        $id = (int) $id;
        $slug = trim((string) $slug);

        foreach (self::$stack as $facility) {
            if ($id > 0 && (int) ($facility['id'] ?? 0) === $id) {
                return $facility;
            }
            if ($slug !== '' && ($facility['slug'] ?? '') === $slug) {
                return $facility;
            }
        }

        if (self::$api_client === null) {
            return null;
        }

        $response = self::$api_client->get_facility(array(
            'id' => $id > 0 ? $id : null,
            'slug' => $slug !== '' ? $slug : null,
        ));
        if (empty($response['success']) || empty($response['facility'])) {
            return null;
        }

        $presenter = new FacilityPresenter();
        return $presenter->format_facility($response['facility']);
    }
}
