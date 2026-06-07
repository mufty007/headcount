<?php
namespace Headcount\Core;

/**
 * Formats Headcount API facilities for shortcodes.
 */
class FacilityPresenter {

    /**
     * @param array[] $facilities
     * @return array[]
     */
    public function format_facilities(array $facilities) {
        $out = array();
        foreach ($facilities as $row) {
            if (is_array($row)) {
                $out[] = $this->format_facility($row);
            }
        }
        return $out;
    }

    /**
     * @param array $facility
     * @return array
     */
    public function format_facility(array $facility) {
        $facility['title'] = $this->plain_text($facility['name'] ?? '');
        $facility['name'] = $facility['title'];

        if (!empty($facility['location'])) {
            $facility['location'] = $this->plain_text($facility['location']);
        }

        if (!empty($facility['description'])) {
            $desc = $facility['description'];
            if (strip_tags($desc) !== $desc) {
                $facility['description_html'] = wp_kses_post($desc);
            } else {
                $facility['description_html'] = esc_html($desc);
            }
            $facility['excerpt'] = wp_trim_words(wp_strip_all_tags($desc), 30, '...');
        } else {
            $facility['description_html'] = '';
            $facility['excerpt'] = '';
        }

        $facility['thumbnail_url'] = $this->resolve_thumbnail($facility);
        $facility['image'] = $facility['thumbnail_url'];

        $isPaid = !empty($facility['is_paid']) && (float) ($facility['hourly_rate'] ?? 0) > 0;
        $facility['formatted_price'] = $isPaid
            ? '$' . number_format((float) $facility['hourly_rate'], 2) . ' / ' . __('hr', 'headcount')
            : __('Free to book', 'headcount');

        $base = $this->booking_base_url();
        $slug = (string) ($facility['slug'] ?? '');
        $facility['details_url'] = ($base !== '' && $slug !== '')
            ? $base . '/facility-details.php?facility=' . rawurlencode($slug)
            : '';
        $facility['guest_book_url'] = ($base !== '' && $slug !== '' && !empty($facility['allow_guest_booking']))
            ? $base . '/facility-book-guest.php?facility=' . rawurlencode($slug)
            : '';
        $facility['member_book_url'] = ($base !== '' && $slug !== '' && !empty($facility['allow_member_booking']))
            ? $base . '/facility-book.php?facility=' . rawurlencode($slug)
            : '';
        $facility['facility_url'] = $facility['details_url'];

        return $facility;
    }

    /**
     * @param array $facility
     * @param string $field
     * @return string
     */
    public function get_field_value(array $facility, $field) {
        $field = strtolower(trim($field));
        switch ($field) {
            case 'title':
            case 'name':
                return (string) ($facility['title'] ?? $facility['name'] ?? '');
            case 'location':
                return (string) ($facility['location'] ?? '');
            case 'description':
                return (string) ($facility['description_html'] ?? $facility['description'] ?? '');
            case 'excerpt':
                return (string) ($facility['excerpt'] ?? '');
            case 'slug':
                return (string) ($facility['slug'] ?? '');
            case 'id':
                return (string) ($facility['id'] ?? '');
            case 'price':
                return (string) ($facility['formatted_price'] ?? '');
            case 'image':
            case 'thumbnail':
            case 'banner':
                return (string) ($facility['thumbnail_url'] ?? $facility['image'] ?? '');
            case 'link':
            case 'url':
            case 'details':
                return (string) ($facility['details_url'] ?? $facility['facility_url'] ?? '');
            case 'guest_book':
            case 'guest_book_link':
                return (string) ($facility['guest_book_url'] ?? '');
            case 'member_book':
            case 'member_book_link':
                return (string) ($facility['member_book_url'] ?? '');
            default:
                return isset($facility[$field]) ? (string) $facility[$field] : '';
        }
    }

    /**
     * @param array  $facility
     * @param string $field
     * @param array  $atts
     * @return string
     */
    public function render_field(array $facility, $field, array $atts = array()) {
        $field = strtolower(trim($field));
        $fallback = isset($atts['fallback']) ? (string) $atts['fallback'] : '';
        $class = isset($atts['class']) ? sanitize_html_class($atts['class']) : '';
        $tag = isset($atts['tag']) ? sanitize_key($atts['tag']) : '';
        $format = isset($atts['format']) ? strtolower((string) $atts['format']) : '';

        $value = $this->get_field_value($facility, $field);
        if ($value === '' && $fallback !== '') {
            $value = $fallback;
        }

        if (in_array($field, array('image', 'thumbnail', 'banner'), true)) {
            if ($value === '') {
                return '';
            }
            if ($format === 'img') {
                $alt = isset($atts['alt']) ? $atts['alt'] : ($facility['title'] ?? '');
                $class_attr = $class !== '' ? ' class="' . esc_attr($class) . '"' : '';
                return '<img src="' . esc_url($value) . '" alt="' . esc_attr($alt) . '"' . $class_attr . ' loading="lazy" decoding="async" />';
            }
            return esc_url($value);
        }

        if (in_array($field, array('link', 'url', 'details', 'guest_book', 'guest_book_link', 'member_book', 'member_book_link'), true)) {
            if ($value === '') {
                return '';
            }
            $default_text = array(
                'guest_book' => __('Book as guest', 'headcount'),
                'guest_book_link' => __('Book as guest', 'headcount'),
                'member_book' => __('Book as member', 'headcount'),
                'member_book_link' => __('Book as member', 'headcount'),
            );
            $text = isset($atts['text']) && $atts['text'] !== ''
                ? (string) $atts['text']
                : ($default_text[$field] ?? ($facility['title'] ?? __('View facility', 'headcount')));
            $class_attr = $class !== '' ? ' class="' . esc_attr($class) . '"' : '';
            return '<a href="' . esc_url($value) . '"' . $class_attr . ' target="_blank" rel="noopener noreferrer">' . esc_html($text) . '</a>';
        }

        if ($field === 'description') {
            $out = $value;
            if ($class !== '' && $out !== '') {
                return '<div class="' . esc_attr($class) . '">' . $out . '</div>';
            }
            return $out;
        }

        $escaped = esc_html(wp_strip_all_tags($value));
        if ($escaped === '') {
            return '';
        }

        $allowed_tags = array('h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'span', 'div', 'strong', 'em');
        if ($tag !== '' && in_array($tag, $allowed_tags, true)) {
            $class_attr = $class !== '' ? ' class="' . esc_attr($class) . '"' : '';
            return '<' . $tag . $class_attr . '>' . $escaped . '</' . $tag . '>';
        }

        if ($class !== '') {
            return '<span class="' . esc_attr($class) . '">' . $escaped . '</span>';
        }

        return $escaped;
    }

    private function plain_text($value) {
        return trim(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function resolve_thumbnail(array $facility) {
        if (!empty($facility['thumbnail_url'])) {
            return (string) $facility['thumbnail_url'];
        }
        if (!empty($facility['image_urls'][0])) {
            return (string) $facility['image_urls'][0];
        }
        return '';
    }

    private function booking_base_url() {
        $portal = trim((string) get_option('headcount_portal_url', ''));
        if ($portal !== '') {
            return rtrim($portal, '/');
        }
        $booking = trim((string) get_option('headcount_facility_booking_url', ''));
        if ($booking !== '') {
            return rtrim($booking, '/');
        }
        $api = rtrim((string) get_option('headcount_api_url', ''), '/');
        if ($api !== '' && preg_match('#/public/api$#i', $api)) {
            return (string) preg_replace('#/public/api$#i', '/public/portal', $api);
        }
        if ($api !== '' && preg_match('#/api$#i', $api)) {
            return (string) preg_replace('#/api$#i', '/public/portal', $api);
        }
        return '';
    }
}
