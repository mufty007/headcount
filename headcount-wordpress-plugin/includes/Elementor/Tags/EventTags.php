<?php
namespace Headcount\Elementor\Tags;

use Elementor\Core\DynamicTags\Tag;
use Elementor\Modules\DynamicTags\Module as TagsModule;
use Headcount\Core\EventLoopContext;
use Headcount\Core\EventPresenter;

/**
 * Base dynamic tag — reads EventLoopContext or optional event ID control.
 */
abstract class BaseEventTag extends Tag {

    /** @return string Field key for EventPresenter::get_field_value */
    abstract protected function get_field_key();

    public function get_group() {
        return 'headcount-event';
    }

    protected function get_event_id_control() {
        return array(
            'type' => \Elementor\Controls_Manager::NUMBER,
            'label' => __('Event ID (optional)', 'headcount'),
            'description' => __('Leave empty when used inside the Headcount Events Loop widget.', 'headcount'),
        );
    }

    protected function register_controls() {
        $this->add_control('event_id', $this->get_event_id_control());
    }

    /**
     * @return array|null
     */
    protected function resolve_event() {
        $settings = $this->get_settings();
        $id = isset($settings['event_id']) ? (int) $settings['event_id'] : 0;
        if ($id > 0) {
            return EventLoopContext::get_event_by_id($id);
        }
        return EventLoopContext::current();
    }

    public function render() {
        $event = $this->resolve_event();
        if ($event === null) {
            return;
        }
        $presenter = new EventPresenter();
        echo esc_html($presenter->get_field_value($event, $this->get_field_key()));
    }
}

class EventTitleTag extends BaseEventTag {
    protected function get_field_key() { return 'title'; }
    public function get_name() { return 'headcount-event-title'; }
    public function get_title() { return __('Event Title', 'headcount'); }
    public function get_categories() { return array(TagsModule::TEXT_CATEGORY); }
}

class EventDateTag extends BaseEventTag {
    protected function get_field_key() { return 'date'; }
    public function get_name() { return 'headcount-event-date'; }
    public function get_title() { return __('Event Date', 'headcount'); }
    public function get_categories() { return array(TagsModule::TEXT_CATEGORY); }
}

class EventTimeTag extends BaseEventTag {
    protected function get_field_key() { return 'time'; }
    public function get_name() { return 'headcount-event-time'; }
    public function get_title() { return __('Event Time', 'headcount'); }
    public function get_categories() { return array(TagsModule::TEXT_CATEGORY); }
}

class EventDayTag extends BaseEventTag {
    protected function get_field_key() { return 'day'; }
    public function get_name() { return 'headcount-event-day'; }
    public function get_title() { return __('Event Day', 'headcount'); }
    public function get_categories() { return array(TagsModule::TEXT_CATEGORY); }
}

class EventMonthTag extends BaseEventTag {
    protected function get_field_key() { return 'month'; }
    public function get_name() { return 'headcount-event-month'; }
    public function get_title() { return __('Event Month', 'headcount'); }
    public function get_categories() { return array(TagsModule::TEXT_CATEGORY); }
}

class EventYearTag extends BaseEventTag {
    protected function get_field_key() { return 'year'; }
    public function get_name() { return 'headcount-event-year'; }
    public function get_title() { return __('Event Year', 'headcount'); }
    public function get_categories() { return array(TagsModule::TEXT_CATEGORY); }
}

class EventWeekdayTag extends BaseEventTag {
    protected function get_field_key() { return 'weekday'; }
    public function get_name() { return 'headcount-event-weekday'; }
    public function get_title() { return __('Event Weekday', 'headcount'); }
    public function get_categories() { return array(TagsModule::TEXT_CATEGORY); }
}

class EventLocationTag extends BaseEventTag {
    protected function get_field_key() { return 'location'; }
    public function get_name() { return 'headcount-event-location'; }
    public function get_title() { return __('Event Location', 'headcount'); }
    public function get_categories() { return array(TagsModule::TEXT_CATEGORY); }
}

class EventCategoryTag extends BaseEventTag {
    protected function get_field_key() { return 'category'; }
    public function get_name() { return 'headcount-event-category'; }
    public function get_title() { return __('Event Category', 'headcount'); }
    public function get_categories() { return array(TagsModule::TEXT_CATEGORY); }
}

class EventExcerptTag extends BaseEventTag {
    protected function get_field_key() { return 'excerpt'; }
    public function get_name() { return 'headcount-event-excerpt'; }
    public function get_title() { return __('Event Excerpt', 'headcount'); }
    public function get_categories() { return array(TagsModule::TEXT_CATEGORY); }
}

class EventPriceTag extends BaseEventTag {
    protected function get_field_key() { return 'price'; }
    public function get_name() { return 'headcount-event-price'; }
    public function get_title() { return __('Event Price', 'headcount'); }
    public function get_categories() { return array(TagsModule::TEXT_CATEGORY); }
}

class EventIdTag extends BaseEventTag {
    protected function get_field_key() { return 'id'; }
    public function get_name() { return 'headcount-event-id'; }
    public function get_title() { return __('Event ID', 'headcount'); }
    public function get_categories() { return array(TagsModule::TEXT_CATEGORY); }
}

class EventImageUrlTag extends BaseEventTag {
    protected function get_field_key() { return 'image'; }
    public function get_name() { return 'headcount-event-image-url'; }
    public function get_title() { return __('Event Image URL', 'headcount'); }
    public function get_categories() { return array(TagsModule::IMAGE_CATEGORY); }

    public function render() {
        $event = $this->resolve_event();
        if ($event === null) {
            return;
        }
        $presenter = new EventPresenter();
        $url = $presenter->get_field_value($event, 'image');
        if ($url !== '') {
            echo esc_url($url);
        }
    }
}

class EventLinkUrlTag extends BaseEventTag {
    protected function get_field_key() { return 'link'; }
    public function get_name() { return 'headcount-event-link-url'; }
    public function get_title() { return __('Event Link URL', 'headcount'); }
    public function get_categories() { return array(TagsModule::URL_CATEGORY); }

    public function render() {
        $event = $this->resolve_event();
        if ($event === null) {
            return;
        }
        $presenter = new EventPresenter();
        $url = $presenter->get_field_value($event, 'link');
        if ($url !== '') {
            echo esc_url($url);
        }
    }
}
