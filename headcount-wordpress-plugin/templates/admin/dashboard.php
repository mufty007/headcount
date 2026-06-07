<?php
/**
 * Admin Dashboard Template
 */
?>
<div class="hc-admin-wrap">
    <div class="hc-admin-header">
        <h1>Headcount Dashboard</h1>
        <div class="hc-admin-actions">
            <span class="hc-status-badge <?php echo $connection ? 'status-success' : 'status-error'; ?>">
                <?php echo $connection ? 'Service Online' : 'Service Offline'; ?>
            </span>
        </div>
    </div>

    <div class="hc-admin-grid">
        <div class="hc-main-col">
            <div class="hc-card">
                <div class="hc-card-title">Recent Events</div>
                <?php if (empty($events)): ?>
                    <div class="hc-empty-state">
                        <p>No events found. Make sure your API is configured correctly.</p>
                        <a href="admin.php?page=headcount-settings" class="hc-btn hc-btn-primary">Go to Settings</a>
                    </div>
                <?php else: ?>
                    <table class="hc-shortcodes-table">
                        <thead>
                            <tr>
                                <th>Event Title</th>
                                <th>Date</th>
                                <th>Status</th>
                                <th><?php echo esc_html__('Actions', 'headcount'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $event_details_url = isset($event_details_url) ? trim((string) $event_details_url) : '';
                            foreach ($events as $event) :
                                $eid = isset($event['id']) ? (int) $event['id'] : 0;
                                $view_url = '';
                                if ($event_details_url !== '' && $eid > 0) {
                                    $view_url = $event_details_url . (strpos($event_details_url, '?') !== false ? '&' : '?') . 'id=' . $eid;
                                }
                                ?>
                                <tr>
                                    <td><strong><?php echo esc_html($event['title']); ?></strong></td>
                                    <td><?php echo esc_html($event['event_date']); ?></td>
                                    <td>
                                        <span class="hc-status-badge status-success">Published</span>
                                    </td>
                                    <td>
                                        <?php if ($view_url !== '') : ?>
                                            <a href="<?php echo esc_url($view_url); ?>" class="button button-small" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('View', 'headcount'); ?></a>
                                        <?php else : ?>
                                            <span class="description" style="font-size:12px;"><?php echo esc_html__('Set Event details URL in settings', 'headcount'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div class="hc-card">
                <div class="hc-card-title">Quick Overview</div>
                <div class="hc-row">
                    <div class="hc-stat-box">
                        <span class="hc-stat-label">Total Events</span>
                        <span class="hc-stat-value"><?php echo intval($event_count); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="hc-side-col">
            <div class="hc-card">
                <div class="hc-card-title">Resources</div>
                <ul class="hc-link-list">
                    <li><a href="admin.php?page=headcount-settings"><span class="dashicons dashicons-admin-settings"></span> Plugin Settings</a></li>
                    <li><a href="https://gorideng.com/docs/headcount-wp" target="_blank"><span class="dashicons dashicons-editor-help"></span> Help Docs</a></li>
                </ul>
            </div>
        </div>
    </div>
</div>

<style>
.hc-stat-box {
    background: var(--hc-bg-light);
    padding: 20px;
    border-radius: 12px;
    text-align: center;
    border: 1px solid var(--hc-border);
}
.hc-stat-label {
    display: block;
    font-size: 13px;
    color: var(--hc-text-muted);
    font-weight: 600;
    margin-bottom: 5px;
}
.hc-stat-value {
    font-size: 32px;
    font-weight: 800;
    color: var(--hc-primary);
}
</style>
