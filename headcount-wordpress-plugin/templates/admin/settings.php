<?php
/**
 * Admin Settings Template (Tabbed Interface)
 */
?>
<div class="hc-admin-wrap" x-data="{ activeTab: 'config' }">
    <div class="hc-admin-header">
        <div class="hc-header-main">
            <h1>Headcount Management</h1>
            <nav class="hc-admin-tabs">
                <button @click="activeTab = 'config'" :class="{ 'active': activeTab === 'config' }" class="hc-tab-btn">
                    <span class="dashicons dashicons-admin-settings"></span> Configuration
                </button>
                <button @click="activeTab = 'shortcodes'" :class="{ 'active': activeTab === 'shortcodes' }" class="hc-tab-btn">
                    <span class="dashicons dashicons-shortcode"></span> Shortcodes
                </button>
                <button @click="activeTab = 'status'" :class="{ 'active': activeTab === 'status' }" class="hc-tab-btn">
                    <span class="dashicons dashicons-cloud"></span> System Status
                </button>
            </nav>
        </div>
        <div class="hc-admin-actions">
            <span class="hc-status-badge <?php echo $connection['success'] ? 'status-success' : 'status-error'; ?>">
                <?php echo $connection['success'] ? 'Connected' : 'Disconnected'; ?>
            </span>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="hc-notice hc-notice-success">
            <?php echo esc_html($message); ?>
        </div>
    <?php endif; ?>

    <div class="hc-admin-grid">
        <div class="hc-main-col">
            <!-- Configuration Tab -->
            <div x-show="activeTab === 'config'" class="hc-tab-content">
                <div class="hc-card">
                    <div class="hc-card-title">API Configuration</div>
                    <form method="post" action="options.php">
                        <?php settings_fields('headcount_settings'); ?>
                        
                        <div class="hc-form-group">
                            <label class="hc-label">API Endpoint URL</label>
                            <input type="text" name="headcount_api_url" value="<?php echo esc_attr($api_url); ?>" class="hc-input" placeholder="https://events.example.org/Headcount/api">
                            <p class="hc-help-text">Primary endpoint for Headcount data. Usually ends in <code>/api</code>.</p>
                        </div>
                        
                        <div class="hc-form-group">
                            <label class="hc-label">Event Details Page URL</label>
                            <input type="text" name="headcount_event_details_url" value="<?php echo esc_attr($event_details_url); ?>" class="hc-input" placeholder="https://event.imcaindy.org/portal/event-details.php">
                            <p class="hc-help-text">URL to your public event details page (may be on a different subdomain than the API). Event ID is appended as <code>?id=123</code>.</p>
                        </div>

                        <div class="hc-form-group">
                            <label class="hc-label">Member portal base URL</label>
                            <input type="url" name="headcount_portal_url" value="<?php echo esc_attr($portal_url ?? ''); ?>" class="hc-input" placeholder="https://events.example.org/Headcount/public/portal">
                            <p class="hc-help-text">Base URL of the Headcount member portal (no trailing slash). Used by <code>[headcount_calendar]</code> for program links and by <code>[headcount_showcase]</code> “View program” buttons. Example path: <code>…/public/portal</code>.</p>
                        </div>

                        <div class="hc-form-group">
                            <label class="hc-label">Headcount site URL (images &amp; media)</label>
                            <input type="url" name="headcount_public_base_url" value="<?php echo esc_attr($public_base_url); ?>" class="hc-input" placeholder="https://events.imcaindy.org">
                            <p class="hc-help-text">
                                <strong>Optional but recommended.</strong> Use the site origin only (scheme + host, e.g. <code>https://events.imcaindy.org</code>) — the folder from your API URL (such as <code>/Headcount</code>) is added automatically so images resolve to
                                <code>…/Headcount/api/image.php?path=…</code> when your API endpoint is <code>…/Headcount/api</code>.
                                This fixes banners when the API returns <code>localhost</code> or paths like <code>/api/image.php</code> without the app folder.
                                Leave empty to derive everything from the API URL above.
                            </p>
                        </div>

                        <div class="hc-row">
                            <div class="hc-form-group">
                                <label class="hc-label">API Key</label>
                                <input type="password" name="headcount_api_key" value="<?php echo esc_attr($api_key); ?>" class="hc-input" placeholder="hc_xxxxxxxxxxxxxxxx">
                            </div>
                            <div class="hc-form-group">
                                <label class="hc-label">Cache (Minutes)</label>
                                <input type="number" name="headcount_cache_duration" value="<?php echo esc_attr($cache_duration); ?>" class="hc-input" min="1" max="1440">
                            </div>
                        </div>

                        <div class="hc-form-group">
                            <label class="hc-label-flex">
                                <input type="checkbox" name="headcount_debug_mode" value="on" <?php checked($debug_mode, 'on'); ?>>
                                <span>Enable Debug Mode</span>
                            </label>
                            <p class="hc-help-text">Logs detailed API interaction to <code>wp-content/debug.log</code> if <code>WP_DEBUG</code> is active.</p>
                        </div>

                        <div class="hc-card hc-card-nested" style="margin-top: 1.5rem; padding: 1rem; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;">
                            <div class="hc-card-title" style="font-size: 14px; margin-bottom: 8px;">Public JSON endpoints (same folder as API)</div>
                            <p class="hc-help-text" style="margin-bottom: 10px;">These endpoints are called with your organization <strong>API key</strong> (header <code>X-API-Key</code> or <code>?api_key=</code>). They power the WordPress plugin’s combined calendar and program lists. The programs endpoint returns only programs that are <strong>published</strong> and marked <strong>show on public site</strong> in Headcount.</p>
                            <?php if (!empty($public_programs_example)): ?>
                                <p class="hc-help-text" style="margin: 4px 0;"><strong>Programs list:</strong> <code style="word-break: break-all;"><?php echo esc_html($public_programs_example); ?></code></p>
                            <?php else: ?>
                                <p class="hc-help-text" style="color: #6b7280;">Save an API Endpoint URL above to see example URLs.</p>
                            <?php endif; ?>
                            <?php if (!empty($public_calendar_example)): ?>
                                <p class="hc-help-text" style="margin: 4px 0;"><strong>Calendar feed (events + program sessions):</strong> <code style="word-break: break-all;"><?php echo esc_html($public_calendar_example); ?></code> — query params <code>start</code>, <code>end</code> (YYYY-MM-DD).</p>
                            <?php endif; ?>
                        </div>

                        <div class="hc-form-actions">
                            <?php submit_button('Save Changes', 'hc-btn hc-btn-primary', 'submit', false); ?>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Shortcodes Tab -->
            <div x-show="activeTab === 'shortcodes'" class="hc-tab-content" style="display:none;">
                <div class="hc-card">
                    <div class="hc-card-title">Shortcode Reference</div>
                    <table class="hc-shortcodes-table">
                        <thead>
                            <tr>
                                <th>Function</th>
                                <th>Shortcode</th>
                                <th>Common Parameters</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Combined events &amp; programs</td>
                                <td><code class="hc-code">[headcount_listings]</code></td>
                                <td><code>layout="sidebar"</code> (or <code>stacked</code>), <code>per_page="12"</code>, <code>types="all"</code> (<code>events</code> / <code>programs</code> to lock), <code>show_filters</code>, <code>show_search</code>, <code>show_pagination</code> — mixed cards with Event/Program chips</td>
                            </tr>
                            <tr>
                                <td>Upcoming Events</td>
                                <td><code class="hc-code">[headcount_events]</code></td>
                                <td><code>limit</code>, <code>layout="grid"</code>, <code>category</code> — events only (not programs)</td>
                            </tr>
                            <tr>
                                <td>Published Programs</td>
                                <td><code class="hc-code">[headcount_programs]</code></td>
                                <td><code>limit</code> — lists programs from Headcount; requires <strong>Published</strong> status and <strong>Show on Public Site</strong> in the program editor</td>
                            </tr>
                            <tr>
                                <td>Single Event</td>
                                <td><code class="hc-code">[headcount_event]</code></td>
                                <td><code>id</code> (Required)</td>
                            </tr>
                            <tr>
                                <td>Full Calendar</td>
                                <td><code class="hc-code">[headcount_calendar]</code></td>
                                <td><code>view="month"</code>, <code>height</code> — merges events + program sessions when the calendar feed is available</td>
                            </tr>
                            <tr>
                                <td>Events, programs &amp; facilities tabs</td>
                                <td><code class="hc-code">[headcount_showcase]</code></td>
                                <td><code>events_limit</code>, <code>programs_limit</code>, <code>facilities_limit="6"</code> (optional Facilities tab), <code>layout="grid"</code> — set <strong>Member portal base URL</strong></td>
                            </tr>
                            <tr>
                                <td>Custom event loop</td>
                                <td><code class="hc-code">[headcount_events_loop]</code></td>
                                <td><code>limit</code>, <code>category</code>, <code>layout="grid"</code>, <code>columns</code>, <code>template_id</code> — wrap field shortcodes inside; upcoming published events only</td>
                            </tr>
                            <tr>
                                <td>Event field (any)</td>
                                <td><code class="hc-code">[headcount_event_field field="title"]</code></td>
                                <td><code>id</code> (optional in loop), <code>tag</code>, <code>class</code>, <code>fallback</code></td>
                            </tr>
                            <tr>
                                <td>Event title, date, time, etc.</td>
                                <td><code class="hc-code">[headcount_event_title]</code> … <code>[headcount_event_link]</code></td>
                                <td>Same as <code>headcount_event_field</code> — use inside <code>[headcount_events_loop]</code> or with <code>id="123"</code></td>
                            </tr>
                            <tr>
                                <td>Bookable facilities</td>
                                <td><code class="hc-code">[headcount_facilities]</code></td>
                                <td><code>limit</code>, <code>layout="grid"</code> — active facilities with guest/member booking links; set <strong>Member portal base URL</strong></td>
                            </tr>
                            <tr>
                                <td>Single facility</td>
                                <td><code class="hc-code">[headcount_facility]</code></td>
                                <td><code>id</code> or <code>slug</code> (one required)</td>
                            </tr>
                            <tr>
                                <td>Facility schedule</td>
                                <td><code class="hc-code">[headcount_facility_calendar]</code></td>
                                <td><code>facility="slug"</code> (required), <code>height</code> — approved bookings + IMCA event blocks</td>
                            </tr>
                            <tr>
                                <td>Custom facility loop</td>
                                <td><code class="hc-code">[headcount_facilities_loop]</code></td>
                                <td><code>limit</code>, <code>layout="grid"</code>, <code>columns</code> — wrap field shortcodes inside</td>
                            </tr>
                            <tr>
                                <td>Facility field (any)</td>
                                <td><code class="hc-code">[headcount_facility_field field="title"]</code></td>
                                <td><code>id</code> or <code>slug</code> (optional in loop), <code>tag</code>, <code>class</code>, <code>fallback</code></td>
                            </tr>
                            <tr>
                                <td>Facility title, location, price, etc.</td>
                                <td><code class="hc-code">[headcount_facility_title]</code> … <code>[headcount_facility_member_book_link]</code></td>
                                <td>Inside <code>[headcount_facilities_loop]</code> or with <code>slug="hall-a"</code> / <code>id="3"</code></td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="hc-help-text" style="margin-top: 1rem;">
                        <strong>Custom layouts:</strong> Build your own card layout with <code>[headcount_events_loop]</code> and field shortcodes such as <code>[headcount_event_title tag="h3"]</code>, <code>[headcount_event_date]</code>, <code>[headcount_event_image format="img"]</code>, and <code>[headcount_event_link text="Details"]</code>. The WordPress Query Loop block only lists posts; use Headcount’s loop shortcode for API events.
                    </p>
                    <p class="hc-help-text">
                        <strong>Elementor:</strong> Create a saved Section template with <strong>Headcount Event</strong> dynamic tags, then add the <strong>Headcount Events Loop</strong> widget and select that template. Or use <code>[headcount_events_loop template_id="123"]</code>.
                    </p>
                    <p class="hc-help-text">
                        <strong>Programs vs events:</strong> Put both on one page with <code>[headcount_listings]</code> (sidebar filters, type chips, pagination).
                        <code>[headcount_events]</code> and <code>[headcount_events_loop]</code> show upcoming events only.
                        Use <code>[headcount_programs]</code> or the Programs tab in <code>[headcount_showcase]</code> for programs-only layouts.
                        In Headcount, a program must be <strong>Published</strong> and have <strong>Show on Public Site</strong> checked (Schedule step) or it will not appear in these feeds.
                    </p>
                    <p class="hc-help-text">
                        <strong>Facilities:</strong> Use <code>[headcount_facilities]</code> or <code>[headcount_facilities_loop]</code> for bookable spaces.
                        Add a Facilities tab with <code>[headcount_showcase facilities_limit="6"]</code>.
                        Link an IMCA event to a facility in Headcount (event editor → <strong>Link to facility</strong>); when the event is <strong>Published</strong>, that time blocks community bookings.
                        Facility must be <strong>Active</strong> with guest and/or member booking enabled.
                    </p>
                </div>
            </div>

            <!-- System Status Tab -->
            <div x-show="activeTab === 'status'" class="hc-tab-content" style="display:none;">
                <div class="hc-card">
                    <div class="hc-card-title">Connection Status</div>
                    <div class="hc-status-details">
                        <div class="hc-status-row">
                            <span class="hc-label">API Reachability:</span>
                            <span class="<?php echo $connection['success'] ? 'hc-text-success' : 'hc-text-error'; ?>">
                                <?php echo esc_html($connection['message']); ?>
                            </span>
                        </div>
                        <?php if ($connection['success']): ?>
                            <div class="hc-status-row">
                                <span class="hc-label">Events Found:</span>
                                <span><?php echo intval($connection['count']); ?> published events</span>
                            </div>
                        <?php endif; ?>
                        <div class="hc-status-row">
                            <span class="hc-label">PHP Version:</span>
                            <span><?php echo phpversion(); ?></span>
                        </div>
                    </div>
                    <hr>
                    <form method="post">
                        <?php wp_nonce_field('headcount_admin_action', 'headcount_admin_nonce'); ?>
                        <button type="submit" name="headcount_clear_cache" class="hc-btn hc-btn-outline">
                            <span class="dashicons dashicons-trash"></span> Purge All Cached Data
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="hc-side-col">
            <div class="hc-card">
                <div class="hc-card-title">Quick Links</div>
                <ul class="hc-link-list">
                    <li><a href="https://gorideng.com/docs/headcount-wp" target="_blank"><span class="dashicons dashicons-editor-help"></span> Documentation</a></li>
                    <li><a href="https://gorideng.com/support" target="_blank"><span class="dashicons dashicons-sos"></span> Support</a></li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- Load Alpine.js for the tab functionality -->
<script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
