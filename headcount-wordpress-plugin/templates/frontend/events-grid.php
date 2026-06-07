<?php
/**
 * Events Grid Template with Alpine.js Filtering
 */
$categories = array_unique(array_filter(array_column($events, 'category')));
sort($categories);
$event_details_url = get_option('headcount_event_details_url', '');
?>
<div class="hc-events-container" 
     x-data="{ 
        filter: 'all',
        events: <?php echo esc_attr(json_encode($events)); ?>,
        get filteredEvents() {
            if (this.filter === 'all') return this.events;
            return this.events.filter(e => e.category === this.filter);
        }
     }">
    
    <!-- Filter Bar -->
    <?php if (!empty($categories)): ?>
    <div class="hc-filter-bar">
        <button @click="filter = 'all'" :class="{ 'active': filter === 'all' }" class="hc-filter-btn">All</button>
        <?php foreach ($categories as $cat): ?>
            <button @click="filter = '<?php echo esc_attr($cat); ?>'" 
                    :class="{ 'active': filter === '<?php echo esc_attr($cat); ?>' }" 
                    class="hc-filter-btn"><?php echo esc_html($cat); ?></button>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="hc-empty-state hc-empty-inline" x-show="filteredEvents.length === 0" x-cloak role="status">
        <div class="hc-empty-visual" aria-hidden="true">
            <svg class="hc-empty-svg" width="96" height="96" viewBox="0 0 120 120" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect x="18" y="22" width="84" height="78" rx="10" stroke="currentColor" stroke-width="2.5"/>
                <path d="M18 38h84" stroke="currentColor" stroke-width="2.5"/>
                <rect x="30" y="14" width="8" height="16" rx="2" fill="currentColor"/>
                <rect x="82" y="14" width="8" height="16" rx="2" fill="currentColor"/>
            </svg>
        </div>
        <h3 class="hc-empty-title"><?php echo esc_html__('No events in this category', 'headcount'); ?></h3>
        <p class="hc-empty-text"><?php echo esc_html__('Try choosing &ldquo;All&rdquo; or another filter.', 'headcount'); ?></p>
    </div>

    <!-- Grid View -->
    <div class="hc-events-grid" x-show="filteredEvents.length > 0">
        <template x-for="event in filteredEvents" :key="event.id">
            <div class="hc-event-card">
                <div class="hc-event-image-wrap">
                    <img x-show="event.banner_image"
                         x-bind:src="event.banner_image"
                         x-bind:alt="event.title"
                         class="hc-event-img"
                         loading="lazy"
                         decoding="async"
                         width="640"
                         height="360">
                    <div class="hc-event-placeholder" x-show="!event.banner_image">
                        <span class="dashicons dashicons-calendar-alt"></span>
                    </div>
                    <div class="hc-event-badge" x-text="event.category" x-show="event.category"></div>
                </div>
                
                <div class="hc-event-content">
                    <h3 class="hc-event-title" x-text="event.title"></h3>
                    
                    <div class="hc-event-meta">
                        <div class="hc-meta-item">
                            <span class="dashicons dashicons-calendar-alt"></span>
                            <span x-text="event.formatted_date"></span>
                        </div>
                        <div class="hc-meta-item">
                            <span class="dashicons dashicons-clock"></span>
                            <span x-text="event.formatted_time"></span>
                        </div>
                    </div>
                    
                    <div class="hc-event-footer">
                        <div class="hc-event-price">
                            <span class="hc-free-label" x-show="event.is_free">Free</span>
                            <span x-show="!event.is_free" x-text="event.formatted_price"></span>
                        </div>
                        <?php if (!empty($event_details_url)): ?>
                            <a :href="'<?php echo esc_url($event_details_url); ?>?id=' + event.id" 
                               class="hc-btn-rsvp-small" 
                               target="_blank" 
                               rel="noopener noreferrer">Details & RSVP</a>
                        <?php else: ?>
                            <a :href="'#event-' + event.id" class="hc-btn-rsvp-small">Details</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </template>
    </div>
    
    <!-- Pagination -->
    <?php if (isset($pagination) && $pagination['show']): ?>
    <div class="hc-pagination">
        <?php
        $current = $pagination['current_page'];
        $total = $pagination['total_pages'];
        $base_url = add_query_arg(array());
        
        // Previous button
        if ($current > 1):
            $prev_url = add_query_arg('hc_page', $current - 1, $base_url);
        ?>
            <a href="<?php echo esc_url($prev_url); ?>" class="hc-page-btn">← Previous</a>
        <?php endif; ?>
        
        <!-- Page numbers -->
        <div class="hc-page-numbers">
            <?php for ($i = 1; $i <= $total; $i++): ?>
                <?php if ($i == $current): ?>
                    <span class="hc-page-current"><?php echo $i; ?></span>
                <?php else: ?>
                    <a href="<?php echo esc_url(add_query_arg('hc_page', $i, $base_url)); ?>" class="hc-page-link"><?php echo $i; ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </div>
        
        <!-- Next button -->
        <?php if ($current < $total):
            $next_url = add_query_arg('hc_page', $current + 1, $base_url);
        ?>
            <a href="<?php echo esc_url($next_url); ?>" class="hc-page-btn">Next →</a>
        <?php endif; ?>
        
        <div class="hc-page-info">
            Showing page <?php echo $current; ?> of <?php echo $total; ?> (<?php echo $pagination['total_events']; ?> events)
        </div>
    </div>
    <?php endif; ?>
</div>

