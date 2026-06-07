<?php
namespace Headcount\Core;

/**
 * Strips empty image/media markup from Elementor loop item HTML.
 */
class ElementorLoopSanitizer {

    /**
     * @param string $html  Rendered Elementor template HTML for one event
     * @param array  $event Formatted event row
     * @return string
     */
    public static function sanitize_item_html($html, $event) {
        $html = (string) $html;
        if ($html === '' || stripos($html, 'elementor') === false) {
            return $html;
        }

        if (!EventsLoopRenderer::event_has_banner_image($event)) {
            $html = self::remove_nodes_with_class_fragment($html, 'elementor-widget-image');
            $html = self::remove_nodes_with_class_fragment($html, 'elementor-widget-image-box');
            $html = self::remove_nodes_with_class_fragment($html, 'hc-event-media');
        }

        $html = self::remove_empty_image_widget_shells($html);

        if (!self::html_contains_valid_image($html)) {
            $html = self::remove_nodes_with_class_fragment($html, 'hc-event-media');
        }

        return $html;
    }

    /**
     * True when rendered HTML includes a real image src.
     *
     * @param string $html
     */
    public static function html_contains_valid_image($html) {
        if (!preg_match_all('/<img\b[^>]*>/i', $html, $tags)) {
            return false;
        }
        foreach ($tags[0] as $tag) {
            if (preg_match('/\bsrc\s*=\s*["\']([^"\']*)["\']/i', $tag, $m)) {
                $src = trim($m[1]);
                if ($src !== '' && $src !== '#' && stripos($src, 'data:image/gif') !== 0) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param string $html
     * @param string $class_fragment e.g. elementor-widget-image
     * @return string
     */
    private static function remove_nodes_with_class_fragment($html, $class_fragment) {
        if (!class_exists('DOMDocument')) {
            return self::remove_nodes_with_class_fragment_regex($html, $class_fragment);
        }

        $inner = self::extract_from_dom_wrapper($html, function (\DOMXPath $xpath, \DOMDocument $doc) use ($class_fragment) {
            $query = '//div[contains(concat(" ", normalize-space(@class), " "), " ' . $class_fragment . ' ")]';
            $nodes = $xpath->query($query);
            if ($nodes === false) {
                return null;
            }
            $to_remove = array();
            foreach ($nodes as $node) {
                $to_remove[] = $node;
            }
            foreach ($to_remove as $node) {
                if ($node->parentNode) {
                    $node->parentNode->removeChild($node);
                }
            }
            return true;
        });

        return $inner !== null ? $inner : self::remove_nodes_with_class_fragment_regex($html, $class_fragment);
    }

    private static function remove_nodes_with_class_fragment_regex($html, $class_fragment) {
        $pattern = '#<div[^>]*class="[^"]*' . preg_quote($class_fragment, '#') . '[^"]*"[^>]*>(?:.*?)</div>\s*</div>#is';
        $prev = $html;
        do {
            $prev = $html;
            $html = preg_replace($pattern, '', $html);
        } while ($html !== $prev && $html !== null);
        return $html === null ? '' : $html;
    }

    /**
     * Remove image widget wrappers that rendered empty (no img or empty src).
     *
     * @param string $html
     * @return string
     */
    private static function remove_empty_image_widget_shells($html) {
        if (!class_exists('DOMDocument')) {
            return $html;
        }

        $inner = self::extract_from_dom_wrapper($html, function (\DOMXPath $xpath) {
            $widgets = $xpath->query('//div[contains(@class, "elementor-widget-image")]');
            if ($widgets === false) {
                return null;
            }

            $to_remove = array();
            foreach ($widgets as $widget) {
                $imgs = $widget->getElementsByTagName('img');
                $has_valid = false;
                if ($imgs->length > 0) {
                    foreach ($imgs as $img) {
                        $src = trim((string) $img->getAttribute('src'));
                        if ($src !== '' && $src !== '#' && stripos($src, 'data:image') !== 0) {
                            $has_valid = true;
                            break;
                        }
                    }
                }
                if (!$has_valid) {
                    $to_remove[] = $widget;
                }
            }

            foreach ($to_remove as $node) {
                if ($node->parentNode) {
                    $node->parentNode->removeChild($node);
                }
            }
            return true;
        });

        return $inner !== null ? $inner : $html;
    }

    /**
     * @param string   $html
     * @param callable $mutate function (\DOMXPath $xpath, \DOMDocument $doc): ?bool
     * @return string|null
     */
    private static function extract_from_dom_wrapper($html, callable $mutate) {
        $prev = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $loaded = $doc->loadHTML(
            '<?xml encoding="utf-8" ?><div id="hc-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!$loaded) {
            return null;
        }

        $xpath = new \DOMXPath($doc);
        $result = $mutate($xpath, $doc);
        if ($result === null) {
            return null;
        }

        $root = $doc->getElementById('hc-root');
        if (!$root) {
            return null;
        }

        $inner = '';
        foreach ($root->childNodes as $child) {
            $inner .= $doc->saveHTML($child);
        }
        return $inner;
    }
}
