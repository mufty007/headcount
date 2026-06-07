<?php
/**
 * Single Event Template - Premium Redesign
 */
if (!$event) return;
?>
<div class="hc-single-event-wrap">
    <!-- Hero Section -->
    <div class="hc-event-hero" 
         style="<?php echo !empty($event['banner_image']) ? 'background-image: url(' . esc_url($event['banner_image']) . ');' : ''; ?>">
        <div class="hc-hero-overlay"></div>
        <div class="hc-hero-content">
            <div class="hc-hero-container">
                <div class="hc-category-badge"><?php echo esc_html($event['category'] ?? 'Event'); ?></div>
                <h1 class="hc-hero-title"><?php echo esc_html($event['title']); ?></h1>
                
                <div class="hc-hero-meta">
                    <div class="hc-meta-pill">
                        <span class="dashicons dashicons-calendar-alt"></span>
                        <?php echo esc_html($event['formatted_date']); ?>
                    </div>
                    <?php if (!empty($event['formatted_time'])): ?>
                    <div class="hc-meta-pill">
                        <span class="dashicons dashicons-clock"></span>
                        <?php echo esc_html($event['formatted_time']); ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($event['location_name'])): ?>
                    <div class="hc-meta-pill">
                        <span class="dashicons dashicons-location"></span>
                        <?php echo esc_html($event['location_name']); ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content Area -->
    <div class="hc-single-container">
        <div class="hc-single-grid">
            <div class="hc-col-main">
                <div class="hc-event-description">
                    <h2 class="hc-section-title">About this Event</h2>
                    <?php echo wp_kses_post(wpautop($event['description'] ?? '')); ?>
                </div>

                <?php if (!empty($event['location_address'])): ?>
                <div class="hc-event-location">
                    <h2 class="hc-section-title">Location</h2>
                    <p class="hc-address">
                        <strong><?php echo esc_html($event['location_name'] ?? ''); ?></strong><br>
                        <?php echo esc_html($event['location_address']); ?>
                    </p>
                    <a href="https://www.google.com/maps/search/?api=1&query=<?php echo urlencode($event['location_address']); ?>" 
                       target="_blank" class="hc-btn-outline-small">
                        <span class="dashicons dashicons-location"></span> View on Maps
                    </a>
                </div>
                <?php endif; ?>
            </div>

            <div class="hc-col-side">
                <div class="hc-sticky-card">
                    <div class="hc-card-price">
                        <span class="hc-label">Registration</span>
                        <div class="hc-amount">
                            <?php if ($event['is_free']): ?>
                                <span class="hc-free">Free</span>
                            <?php else: ?>
                                <span class="hc-price">$<?php echo esc_html($event['ticket_price']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="hc-card-details">
                        <div class="hc-detail-row">
                            <span class="dashicons dashicons-calendar"></span>
                            <span><?php echo esc_html($event['formatted_date']); ?></span>
                        </div>
                        <?php if (isset($event['spots_available'])): ?>
                        <div class="hc-detail-row">
                            <span class="dashicons dashicons-groups"></span>
                            <span><?php echo intval($event['spots_available']); ?> spots left</span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="hc-card-actions">
                        <a href="#rsvp" class="hc-btn-rsvp-large">Register Now</a>
                        <button class="hc-btn-share" onclick="navigator.share({title: '<?php echo esc_js($event['title']); ?>', url: window.location.href})">
                            <span class="dashicons dashicons-share"></span> Share Event
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
