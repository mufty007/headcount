<?php
/**
 * Error Template
 */
?>
<div class="hc-error-container">
    <div class="hc-error-icon">⚠️</div>
    <div class="hc-error-content">
        <h4>Unable to load events</h4>
        <p><?php echo esc_html($message); ?></p>
        <?php if (current_user_can('manage_options')): ?>
            <p><small>Admin: Check your <a href="<?php echo admin_url('options-general.php?page=headcount'); ?>">Headcount Settings</a></small></p>
        <?php endif; ?>
    </div>
</div>
