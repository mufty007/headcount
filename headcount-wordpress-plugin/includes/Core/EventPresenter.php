<?php
namespace Headcount\Core;

/**
 * Formats Headcount API events and resolves field values for shortcodes / Elementor tags.
 */
class EventPresenter {

    /**
     * @param array[] $events
     * @return array[]
     */
    public function format_events($events) {
        $formatted = array();
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            $formatted[] = $this->format_event($event);
        }
        return $formatted;
    }

    /**
     * @param array $event
     * @return array
     */
    public function format_event(array $event) {
        $date_format = get_option('headcount_date_format', 'F j, Y');
        $date_obj = \DateTime::createFromFormat('Y-m-d', $event['event_date'] ?? '');
        $event['formatted_date'] = $date_obj ? $date_obj->format($date_format) : ($event['event_date'] ?? '');

        if ($date_obj) {
            $event['event_day'] = $date_obj->format('j');
            $event['event_month'] = $date_obj->format('F');
            $event['event_month_num'] = $date_obj->format('m');
            $event['event_year'] = $date_obj->format('Y');
            $event['event_weekday'] = $date_obj->format('l');
        } else {
            $event['event_day'] = '';
            $event['event_month'] = '';
            $event['event_month_num'] = '';
            $event['event_year'] = '';
            $event['event_weekday'] = '';
        }

        $time_format = get_option('headcount_time_format', 'g:i A');
        $event['formatted_time'] = '';
        $event['formatted_start_time'] = '';
        $event['formatted_end_time'] = '';

        if (!empty($event['start_time'])) {
            $time_obj = \DateTime::createFromFormat('H:i:s', $event['start_time']);
            if (!$time_obj) {
                $time_obj = \DateTime::createFromFormat('H:i', $event['start_time']);
            }

            if ($time_obj) {
                $event['formatted_start_time'] = $time_obj->format($time_format);
                $event['formatted_time'] = $event['formatted_start_time'];
                if (!empty($event['end_time'])) {
                    $end_time_obj = \DateTime::createFromFormat('H:i:s', $event['end_time']);
                    if (!$end_time_obj) {
                        $end_time_obj = \DateTime::createFromFormat('H:i', $event['end_time']);
                    }
                    if ($end_time_obj) {
                        $event['formatted_end_time'] = $end_time_obj->format($time_format);
                        $event['formatted_time'] .= ' - ' . $event['formatted_end_time'];
                    }
                }
            }
        }

        $event['is_free'] = (float) ($event['ticket_price'] ?? 0) <= 0;
        $event['formatted_price'] = $event['is_free']
            ? __('Free', 'headcount')
            : '$' . number_format((float) $event['ticket_price'], 2);

        if (isset($event['capacity']) && $event['capacity'] > 0) {
            $attendance = (int) ($event['attendance_count'] ?? 0);
            $event['spots_available'] = max(0, (int) $event['capacity'] - $attendance);
            $event['is_full'] = $event['spots_available'] <= 0;
        } else {
            $event['spots_available'] = null;
            $event['is_full'] = false;
        }

        if (!empty($event['description'])) {
            $event['excerpt'] = wp_trim_words($event['description'], 30, '...');
        } else {
            $event['excerpt'] = '';
        }

        if (!empty($event['banner_image'])) {
            $event['banner_image'] = $this->resolve_banner_image_url($event['banner_image']);
        }

        $event['event_url'] = $this->get_event_url((int) ($event['id'] ?? 0));

        return $event;
    }

    /**
     * @param int $event_id
     * @return string
     */
    public function get_event_url($event_id) {
        $event_id = (int) $event_id;
        if ($event_id <= 0) {
            return '';
        }
        $base = trim((string) get_option('headcount_event_details_url', ''));
        if ($base === '') {
            return '#event-' . $event_id;
        }
        $sep = (strpos($base, '?') !== false) ? '&' : '?';
        return $base . $sep . 'id=' . $event_id;
    }

    /**
     * @param array      $event
     * @param string     $field
     * @param array      $atts  tag, format, link_text, fallback
     * @return string Raw field value (caller escapes for HTML context).
     */
    public function get_field_value(array $event, $field, array $atts = array()) {
        $field = strtolower(trim((string) $field));

        switch ($field) {
            case 'title':
                return (string) ($event['title'] ?? '');
            case 'description':
                return (string) ($event['description'] ?? '');
            case 'excerpt':
                return (string) ($event['excerpt'] ?? '');
            case 'location':
                return (string) ($event['location'] ?? '');
            case 'category':
                return (string) ($event['category'] ?? '');
            case 'date':
                return (string) ($event['formatted_date'] ?? '');
            case 'time':
                return (string) ($event['formatted_time'] ?? '');
            case 'start_time':
                return (string) ($event['formatted_start_time'] ?? $event['start_time'] ?? '');
            case 'end_time':
                return (string) ($event['formatted_end_time'] ?? $event['end_time'] ?? '');
            case 'day':
                return (string) ($event['event_day'] ?? '');
            case 'month':
                return (string) ($event['event_month'] ?? '');
            case 'year':
                return (string) ($event['event_year'] ?? '');
            case 'weekday':
                return (string) ($event['event_weekday'] ?? '');
            case 'id':
                return (string) ($event['id'] ?? '');
            case 'price':
                return (string) ($event['formatted_price'] ?? '');
            case 'image':
            case 'banner':
            case 'banner_image':
                return (string) ($event['banner_image'] ?? '');
            case 'link':
            case 'url':
                return (string) ($event['event_url'] ?? $this->get_event_url((int) ($event['id'] ?? 0)));
            case 'spots':
                if ($event['spots_available'] === null) {
                    return '';
                }
                if (!empty($event['is_full'])) {
                    return __('Full', 'headcount');
                }
                return sprintf(
                    /* translators: %d: spots remaining */
                    _n('%d spot left', '%d spots left', (int) $event['spots_available'], 'headcount'),
                    (int) $event['spots_available']
                );
            default:
                return isset($event[$field]) ? (string) $event[$field] : '';
        }
    }

    /**
     * Render field HTML for shortcodes.
     *
     * @param array  $event
     * @param string $field
     * @param array  $atts
     * @return string
     */
    public function render_field(array $event, $field, array $atts = array()) {
        $field = strtolower(trim((string) $field));
        $fallback = isset($atts['fallback']) ? (string) $atts['fallback'] : '';
        $class = isset($atts['class']) ? sanitize_html_class($atts['class']) : '';
        $tag = isset($atts['tag']) ? sanitize_key($atts['tag']) : '';
        $format = isset($atts['format']) ? strtolower((string) $atts['format']) : '';

        $value = $this->get_field_value($event, $field, $atts);
        if ($value === '' && $fallback !== '') {
            $value = $fallback;
        }
        if ($value === '' && in_array($field, array('image', 'banner', 'banner_image', 'link', 'url'), true)) {
            return '';
        }

        if ($field === 'image' || $field === 'banner' || $field === 'banner_image') {
            if ($value === '') {
                return '';
            }
            if ($format === 'img') {
                $alt = isset($atts['alt']) ? $atts['alt'] : ($event['title'] ?? '');
                $class_attr = $class !== '' ? ' class="' . esc_attr($class) . '"' : '';
                return '<img src="' . esc_url($value) . '" alt="' . esc_attr($alt) . '"' . $class_attr . ' loading="lazy" decoding="async" />';
            }
            return esc_url($value);
        }

        if ($field === 'link' || $field === 'url') {
            if ($value === '' || $value === '#event-' . (int) ($event['id'] ?? 0)) {
                return '';
            }
            $text = isset($atts['text']) && $atts['text'] !== ''
                ? (string) $atts['text']
                : ($event['title'] ?? __('View event', 'headcount'));
            $class_attr = $class !== '' ? ' class="' . esc_attr($class) . '"' : '';
            $target = (strpos($value, 'http') === 0) ? ' target="_blank" rel="noopener noreferrer"' : '';
            return '<a href="' . esc_url($value) . '"' . $class_attr . $target . '>' . esc_html($text) . '</a>';
        }

        if ($field === 'description') {
            $out = wp_kses_post($value);
            if ($class !== '' && $out !== '') {
                return '<div class="' . esc_attr($class) . '">' . $out . '</div>';
            }
            return $out;
        }

        $escaped = esc_html($value);
        if ($escaped === '') {
            return '';
        }

        $allowed_tags = array('h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'span', 'div', 'time', 'strong', 'em');
        if ($tag !== '' && in_array($tag, $allowed_tags, true)) {
            $class_attr = $class !== '' ? ' class="' . esc_attr($class) . '"' : '';
            return '<' . $tag . $class_attr . '>' . $escaped . '</' . $tag . '>';
        }

        if ($class !== '') {
            return '<span class="' . esc_attr($class) . '">' . $escaped . '</span>';
        }

        return $escaped;
    }

    private function get_headcount_app_base_url() {
        $api_url = rtrim((string) get_option('headcount_api_url', ''), '/');
        $derived = preg_replace('#/(public)?/api/?$#i', '', $api_url);

        $public = trim((string) get_option('headcount_public_base_url', ''));
        $public = $public === '' ? '' : rtrim($public, '/');

        if ($public === '') {
            return $derived;
        }

        $pub_parts = wp_parse_url($public);
        $drv_parts = wp_parse_url($derived);
        if (!$pub_parts || empty($pub_parts['host'])) {
            return $derived;
        }

        $drv_path = isset($drv_parts['path']) ? trim((string) $drv_parts['path'], '/') : '';
        $pub_path = isset($pub_parts['path']) ? trim((string) $pub_parts['path'], '/') : '';

        if ($pub_path === '' && $drv_path !== '') {
            return rtrim($public, '/') . '/' . $drv_path;
        }

        return $public;
    }

    private function apply_app_path_prefix($url_path, $app_root_path) {
        $url_path = (string) $url_path;
        $app_root_path = trim((string) $app_root_path, '/');
        if ($app_root_path === '' || $url_path === '') {
            return $url_path;
        }
        $prefix = '/' . $app_root_path;
        if (strpos($url_path, $prefix . '/') === 0) {
            return $url_path;
        }
        if (preg_match('#^/api/#', $url_path) || preg_match('#^/uploads/#', $url_path)) {
            return $prefix . $url_path;
        }
        return $url_path;
    }

    private function get_headcount_api_base_url_normalized() {
        $api = rtrim((string) get_option('headcount_api_url', ''), '/');
        if ($api === '') {
            return '';
        }
        if (preg_match('#/public/?$#', $api) && !preg_match('#/api/?$#', $api)) {
            $api = rtrim($api, '/') . '/api';
        }
        return $api;
    }

    private function get_image_php_base_url() {
        $apiConfigured = rtrim((string) get_option('headcount_api_url', ''), '/');
        if ($apiConfigured !== '' && preg_match('#/(public/)?api$#i', $apiConfigured)) {
            return $apiConfigured;
        }
        $app = rtrim((string) $this->get_headcount_app_base_url(), '/');
        if ($app !== '') {
            $pu = wp_parse_url($app);
            if (!empty($pu['host'])) {
                $path = isset($pu['path']) ? trim((string) $pu['path'], '/') : '';
                if ($path !== '') {
                    $scheme = $pu['scheme'] ?? 'https';
                    $host = $pu['host'];
                    $port = isset($pu['port']) ? ':' . $pu['port'] : '';
                    return $scheme . '://' . $host . $port . '/' . $path . '/public/api';
                }
            }
        }
        $norm = $this->get_headcount_api_base_url_normalized();
        return $norm !== '' ? rtrim($norm, '/') : '';
    }

    private function banner_url_via_image_php($storagePath) {
        $storagePath = str_replace('\\', '/', (string) $storagePath);
        $storagePath = ltrim($storagePath, '/');
        if (strpos($storagePath, 'uploads/') === 0) {
            $storagePath = substr($storagePath, strlen('uploads/'));
        }
        $base = $this->get_image_php_base_url();
        if ($base === '') {
            return '';
        }
        return $base . '/image.php?path=' . rawurlencode($storagePath);
    }

    private function banner_image_replace_origin_if_needed($url, $public_option_set, $base_parts) {
        $parts = wp_parse_url($url);
        if (!$parts) {
            return esc_url_raw($url);
        }
        $url_host = strtolower($parts['host'] ?? '');
        $loopback = in_array($url_host, array('localhost', '127.0.0.1', '::1'), true);
        if (!$public_option_set && !$loopback) {
            return esc_url_raw($url);
        }
        if (!$base_parts || empty($base_parts['host'])) {
            return esc_url_raw($url);
        }
        $scheme = $base_parts['scheme'] ?? 'https';
        $host = $base_parts['host'];
        $port = isset($base_parts['port']) ? ':' . $base_parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
        return esc_url_raw($scheme . '://' . $host . $port . $path . $query . $fragment);
    }

    public function resolve_banner_image_url($url) {
        if ($url === null || $url === '') {
            return '';
        }
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }

        $public_option_set = trim((string) get_option('headcount_public_base_url', '')) !== '';

        $base = $this->get_headcount_app_base_url();
        $base_parts = null;
        if ($base !== '') {
            $parsed = wp_parse_url($base);
            $base_parts = (!empty($parsed['host'])) ? $parsed : null;
        }

        if (preg_match('#^https?://#i', $url)) {
            $parts = wp_parse_url($url);
            if (!$parts) {
                return esc_url_raw($url);
            }
            $orig_path = $parts['path'] ?? '';

            if (preg_match('#/image\.php$#i', $orig_path) && !empty($parts['query']) && strpos($parts['query'], 'path=') !== false) {
                parse_str($parts['query'], $q);
                if (!empty($q['path'])) {
                    $built = $this->banner_url_via_image_php($q['path']);
                    if ($built !== '') {
                        return $this->banner_image_replace_origin_if_needed($built, $public_option_set, $base_parts);
                    }
                }
                return $this->banner_image_replace_origin_if_needed($url, $public_option_set, $base_parts);
            }

            if (preg_match('#/uploads/(.+)$#', $orig_path, $m)) {
                $inner = rawurldecode($m[1]);
                $built = $this->banner_url_via_image_php($inner);
                if ($built !== '') {
                    return $this->banner_image_replace_origin_if_needed($built, $public_option_set, $base_parts);
                }
            }

            if ($base === '' || !$base_parts) {
                return esc_url_raw($url);
            }

            $app_root_path = isset($base_parts['path']) ? trim((string) $base_parts['path'], '/') : '';
            $path = $this->apply_app_path_prefix($orig_path, $app_root_path);
            $query = isset($parts['query']) ? '?' . $parts['query'] : '';
            $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

            $url_host = strtolower($parts['host'] ?? '');
            $loopback = in_array($url_host, array('localhost', '127.0.0.1', '::1'), true);
            $replace_origin = $public_option_set || $loopback;

            if ($replace_origin) {
                $scheme = $base_parts['scheme'] ?? 'https';
                $host = $base_parts['host'];
                $port = isset($base_parts['port']) ? ':' . $base_parts['port'] : '';
                return esc_url_raw($scheme . '://' . $host . $port . $path . $query . $fragment);
            }

            if ($path !== $orig_path) {
                $scheme = $parts['scheme'] ?? 'https';
                $host = $parts['host'];
                $port = isset($parts['port']) ? ':' . $parts['port'] : '';
                return esc_url_raw($scheme . '://' . $host . $port . $path . $query . $fragment);
            }

            return esc_url_raw($url);
        }

        $built = $this->banner_url_via_image_php($url);
        if ($built !== '') {
            return esc_url_raw($built);
        }
        return esc_url_raw($url);
    }

    /**
     * @param array $p Raw program row
     * @return array
     */
    public function apply_resolved_program_banner_urls(array $p) {
        $raw = isset($p['banner_image']) ? trim((string) $p['banner_image']) : '';
        if ($raw === '' && !empty($p['banner_image_url'])) {
            $raw = trim((string) $p['banner_image_url']);
        }
        if ($raw === '') {
            return $p;
        }
        $resolved = $this->resolve_banner_image_url($raw);
        if ($resolved !== '') {
            $p['banner_image'] = $resolved;
            $p['banner_image_url'] = $resolved;
        }
        return $p;
    }
}
