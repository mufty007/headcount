<?php
/**
 * RSVP Form Template
 * @var int $event_id
 * @var string $error
 * @var array $atts
 */
$theme_class = 'headcount-theme-' . esc_attr($atts['theme']);
?>

<div class="headcount-rsvp-form <?php echo $theme_class; ?>">
    <?php if (!empty($error)): ?>
        <div class="headcount-error">
            <?php echo esc_html($error); ?>
        </div>
    <?php endif; ?>
    
    <form method="post" class="headcount-form">
        <?php wp_nonce_field('headcount_rsvp_' . $event_id, 'headcount_rsvp_nonce'); ?>
        
        <div class="headcount-form-group">
            <label for="headcount_name">Name *</label>
            <input type="text" id="headcount_name" name="name" required class="headcount-input">
        </div>
        
        <div class="headcount-form-group">
            <label for="headcount_email">Email *</label>
            <input type="email" id="headcount_email" name="email" required class="headcount-input">
        </div>
        
        <div class="headcount-form-group">
            <label for="headcount_phone">Phone</label>
            <input type="tel" id="headcount_phone" name="phone" class="headcount-input">
        </div>
        
        <div class="headcount-form-group">
            <label for="headcount_guests">Number of Guests</label>
            <select id="headcount_guests" name="guests" class="headcount-input">
                <?php for ($i = 1; $i <= 10; $i++): ?>
                    <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                <?php endfor; ?>
            </select>
        </div>
        
        <div class="headcount-form-group">
            <label for="headcount_notes">Special Requirements</label>
            <textarea id="headcount_notes" name="notes" rows="4" class="headcount-input"></textarea>
        </div>
        
        <button type="submit" name="headcount_rsvp_submit" class="headcount-button headcount-button-primary">
            Submit RSVP
        </button>
    </form>
</div>
