<?php

/**
 * Event Feedback Page
 * Members: list eligible events and submit feedback when logged in.
 * Guests: submit via signed email link or email verification (no portal login).
 */

require_once __DIR__ . '/bootstrap.php';
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';
require_once HC_PROJECT_ROOT . '/src/helpers.php';

use Headcount\Middleware\PortalAuthMiddleware;
use Headcount\Helpers\Database;
use Headcount\Helpers\Security;

$configFile = HC_PROJECT_ROOT . '/config/config.php';
if (!file_exists($configFile)) {
    die('Configuration not found.');
}

$config = require $configFile;

try {
    Database::getInstance($config['database']);
} catch (\Exception $e) {
    die('System initialization failed.');
}

Security::configureSession();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$isLoggedIn = PortalAuthMiddleware::isAuthenticated();
$member = $isLoggedIn ? PortalAuthMiddleware::getMember() : null;
$memberName = $member ? trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')) : '';

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/portal/', PHP_URL_PATH);
$baseUrlPath = preg_replace('#/portal/.*$#', '', $requestPath);
$baseUrlPath = rtrim($baseUrlPath, '/');
$apiBase = $baseUrlPath . '/api/portal/';
$loginUrl = $baseUrlPath . '/portal/login.php';

$eventId = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;
$guestUid = isset($_GET['uid']) ? (int) $_GET['uid'] : 0;
$guestToken = trim((string) ($_GET['token'] ?? ''));
$fromEmail = isset($_GET['from']) && $_GET['from'] === 'email';
$hasSignedGuestLink = $eventId > 0 && $guestUid > 0 && $guestToken !== ''
    && headcount_event_feedback_verify_token($eventId, $guestUid, $guestToken, $config);
$guestMode = !$isLoggedIn && $eventId > 0;

$ratingQuestions = [
    'overall' => 'Overall experience',
    'content' => 'Quality of content / program',
    'venue' => 'Venue & organization',
    'recommend' => 'Likelihood to recommend',
];

$pageTitle = 'Event Feedback';
require __DIR__ . '/includes/header.php';
?>

<?php
$pageHeaderTitle = 'Event Feedback';
$pageHeaderSubtitle = $guestMode
    ? 'Share your experience — no account required'
    : 'Share your feedback on past events';
require __DIR__ . '/components/page-header.php';
?>

<div class="max-w-3xl mx-auto">
    <div id="error-message" class="hidden rounded-xl border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-700 mb-4 dark:border-rose-900 dark:bg-rose-950/30 dark:text-rose-300"></div>
    <div id="success-message" class="hidden rounded-xl border border-green-300 bg-green-50 px-4 py-3 text-sm text-green-700 mb-4 dark:border-green-900 dark:bg-green-950/30 dark:text-green-300"></div>

    <?php if (!$isLoggedIn && !$eventId): ?>
    <div class="bento-card p-8 text-center">
        <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-600 dark:bg-brand-950/40 dark:text-brand-300">
            <svg class="h-7 w-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
        </div>
        <h2 class="text-xl font-bold text-gray-900 dark:text-white">Sign in or use your email link</h2>
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400 max-w-md mx-auto">
            Members can view all events awaiting feedback after signing in. Guests should open the feedback link from the email we sent after the event.
        </p>
        <div class="mt-6 flex flex-col sm:flex-row gap-3 justify-center">
            <a href="<?= e($loginUrl) ?>" class="btn-primary px-6 py-2.5">Sign in</a>
            <a href="<?= e($baseUrlPath . '/portal/events.php') ?>" class="btn-secondary px-6 py-2.5">Browse events</a>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isLoggedIn): ?>
    <div class="bento-card mb-6">
        <div class="p-5 sm:p-6 border-b border-gray-200 dark:border-gray-700">
            <h2 class="text-lg sm:text-xl font-semibold text-gray-900 dark:text-white">Events awaiting your feedback</h2>
            <p class="text-sm text-gray-500 mt-1 dark:text-gray-400">Past events you checked into that requested feedback.</p>
        </div>
        <div id="attended-events" class="p-5 sm:p-6">
            <div class="text-center py-8 text-gray-500 dark:text-gray-400">Loading events...</div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($eventId): ?>
    <div class="bento-card" id="feedback-form-container">
        <div class="p-5 sm:p-6 border-b border-gray-200 dark:border-gray-700">
            <h2 class="text-lg sm:text-xl font-semibold text-gray-900 dark:text-white">Submit Feedback</h2>
            <?php if ($guestMode): ?>
            <p class="text-sm text-gray-500 mt-1 dark:text-gray-400">No portal account needed — verify with the email you used at check-in.</p>
            <?php endif; ?>
        </div>
        <div class="p-5 sm:p-6">
            <div id="event-info" class="mb-6 rounded-2xl border border-gray-200 bg-gradient-to-br from-gray-50 to-white p-4 sm:p-5 dark:border-gray-700 dark:from-gray-800/80 dark:to-gray-900/50">
                <p class="text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Event</p>
                <p id="event-title-display" class="text-lg sm:text-xl font-bold text-gray-900 dark:text-white mt-1 leading-snug">Loading...</p>
                <p id="event-date-display" class="text-sm text-gray-600 mt-1 dark:text-gray-400"></p>
                <?php if ($isLoggedIn): ?>
                <p class="text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400 mt-4">Your name</p>
                <p class="text-base font-semibold text-gray-900 dark:text-white mt-1"><?= e($memberName ?: 'Member') ?></p>
                <?php endif; ?>
            </div>

            <?php if ($guestMode && !$hasSignedGuestLink): ?>
            <div class="mb-6">
                <label for="guest_email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Email address used at check-in *</label>
                <input type="email" id="guest_email" name="guest_email" autocomplete="email"
                       class="w-full rounded-xl border border-gray-300 bg-white px-4 py-3 text-sm text-gray-800 placeholder-gray-400 transition-colors focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                       placeholder="you@example.com">
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">We match this to your check-in record for this event.</p>
            </div>
            <?php endif; ?>

            <div id="already-submitted" class="hidden rounded-xl border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-800 mb-4 dark:border-brand-900 dark:bg-brand-950/30 dark:text-brand-200">
                You already submitted feedback for this event. You can update your responses below.
            </div>

            <form id="feedback-form">
                <input type="hidden" id="feedback_event_id" name="event_id" value="<?= (int) $eventId ?>">

                <div class="space-y-6">
                    <?php foreach ($ratingQuestions as $key => $label): ?>
                    <div class="rating-question rounded-2xl border border-gray-200 bg-white p-4 sm:p-5 dark:border-gray-700 dark:bg-gray-900/40" data-question-key="<?= e($key) ?>">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-3">
                            <label class="text-sm font-semibold text-gray-800 dark:text-gray-200"><?= e($label) ?></label>
                            <span class="rating-label text-xs font-medium text-amber-600 dark:text-amber-400" data-key="<?= e($key) ?>">Tap to rate</span>
                        </div>
                        <div class="flex gap-1.5 sm:gap-2 star-group" data-key="<?= e($key) ?>" role="group" aria-label="<?= e($label) ?>">
                            <?php for ($s = 1; $s <= 5; $s++): ?>
                            <button type="button"
                                    class="star-btn flex h-11 w-11 sm:h-10 sm:w-10 items-center justify-center rounded-xl text-gray-300 transition-all hover:scale-105 hover:text-amber-300 focus:outline-none focus:ring-2 focus:ring-amber-400/40 dark:text-gray-600"
                                    data-rating="<?= $s ?>" data-key="<?= e($key) ?>"
                                    aria-label="<?= $s ?> star<?= $s > 1 ? 's' : '' ?>">
                                <svg class="h-7 w-7 sm:h-6 sm:w-6" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.957a1 1 0 00.95.69h4.162c.969 0 1.371 1.24.588 1.81l-3.368 2.446a1 1 0 00-.364 1.118l1.287 3.957c.3.922-.755 1.688-1.54 1.118l-3.367-2.446a1 1 0 00-1.175 0l-3.367 2.446c-.784.57-1.838-.196-1.539-1.118l1.286-3.957a1 1 0 00-.363-1.118L2.075 10.05c-.783-.57-.38-1.81.588-1.81h4.162a1 1 0 00.95-.69l1.286-3.957z"/></svg>
                            </button>
                            <?php endfor; ?>
                        </div>
                        <input type="hidden" class="rating-input" name="rating_<?= e($key) ?>" data-key="<?= e($key) ?>" required>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="mt-6">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Any other feedback (optional)</label>
                    <textarea id="feedback_text" name="feedback_text" rows="4"
                              class="w-full rounded-xl border border-gray-300 bg-white px-4 py-3 text-sm text-gray-800 placeholder-gray-400 transition-colors focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:placeholder-gray-500"
                              placeholder="What went well? What could be improved?"></textarea>
                </div>

                <button type="submit" class="btn-primary w-full sm:w-auto mt-6 px-8 py-3 text-sm font-semibold" id="feedback-submit-btn">
                    Submit Feedback
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
    const apiBase = <?= json_encode($apiBase) ?>;
    const baseUrl = <?= json_encode($baseUrlPath) ?>;
    const selectedEventId = <?= json_encode($eventId) ?>;
    const isLoggedIn = <?= json_encode($isLoggedIn) ?>;
    const guestMode = <?= json_encode($guestMode) ?>;
    const hasSignedGuestLink = <?= json_encode($hasSignedGuestLink) ?>;
    const guestUid = <?= json_encode($guestUid) ?>;
    const guestToken = <?= json_encode($guestToken) ?>;
    const fromEmail = <?= json_encode($fromEmail) ?>;
    const ratingScores = {};
    const ratingLabels = ['', 'Poor', 'Fair', 'Good', 'Very good', 'Excellent'];

    function decodePlainText(text) {
        if (!text) return '';
        const el = document.createElement('textarea');
        el.innerHTML = text;
        return el.value;
    }

    function escapeHtml(text) {
        const safe = decodePlainText(String(text || ''));
        return safe
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function setStars(key, rating) {
        ratingScores[key] = rating;
        const input = document.querySelector('.rating-input[data-key="' + key + '"]');
        if (input) input.value = rating;
        document.querySelectorAll('.star-btn[data-key="' + key + '"]').forEach(function(star) {
            const starRating = parseInt(star.dataset.rating, 10);
            const on = starRating <= rating;
            star.classList.toggle('text-amber-400', on);
            star.classList.toggle('text-gray-300', !on);
            star.classList.toggle('dark:text-gray-600', !on);
            star.classList.toggle('bg-amber-50', on);
            star.classList.toggle('dark:bg-amber-950/20', on);
        });
        const labelEl = document.querySelector('.rating-label[data-key="' + key + '"]');
        if (labelEl) {
            labelEl.textContent = rating + ' / 5 · ' + (ratingLabels[rating] || '');
        }
    }

    document.querySelectorAll('.star-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            setStars(this.dataset.key, parseInt(this.dataset.rating, 10));
        });
    });

    async function loadAttendedEvents() {
        if (!isLoggedIn) return;
        try {
            const response = await fetch(apiBase + 'feedback/eligible', { credentials: 'same-origin' });
            const data = await response.json();
            if (data.success) {
                displayAttendedEvents(data.events || []);
            }
        } catch (error) {
            console.error('Error:', error);
        }
    }

    function displayAttendedEvents(events) {
        const container = document.getElementById('attended-events');
        if (!container) return;
        if (!events.length) {
            container.innerHTML = '<div class="text-center py-8 text-gray-500 dark:text-gray-400">No events are waiting for your feedback.</div>';
            return;
        }
        container.innerHTML = events.map(function(event) {
            const eventDate = new Date(event.event_date + 'T12:00:00');
            const dateStr = eventDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            const submitted = parseInt(event.has_feedback, 10) > 0;
            const title = escapeHtml(event.title);
            return '<article class="rounded-2xl border border-gray-200 bg-white p-4 sm:p-5 mb-3 last:mb-0 dark:border-gray-700 dark:bg-gray-900/30">' +
                '<div class="flex flex-col gap-4">' +
                '<div class="min-w-0">' +
                '<h3 class="text-base sm:text-lg font-bold text-gray-900 dark:text-white leading-snug">' + title + '</h3>' +
                '<p class="text-sm text-gray-600 mt-1 dark:text-gray-400">' + dateStr + '</p>' +
                (submitted ? '<span class="inline-flex mt-2 items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-semibold text-green-700 dark:bg-green-950/40 dark:text-green-300">Feedback submitted</span>' : '') +
                '</div>' +
                '<a href="' + baseUrl + '/portal/feedback.php?event_id=' + event.id + '" class="btn-primary w-full sm:w-auto sm:self-start text-center px-5 py-2.5 text-sm font-semibold">' +
                (submitted ? 'Update feedback' : 'Provide feedback') +
                '</a></div></article>';
        }).join('');
    }

    async function loadEventFeedbackForm(eventId) {
        try {
            const mineQs = new URLSearchParams({ event_id: String(eventId) });
            if (guestMode && hasSignedGuestLink) {
                mineQs.set('uid', String(guestUid));
                mineQs.set('token', guestToken);
            }

            const [eventRes, mineRes] = await Promise.all([
                fetch(apiBase + 'feedback/event-info?event_id=' + eventId),
                fetch(apiBase + 'feedback/mine?' + mineQs.toString(), guestMode && hasSignedGuestLink ? undefined : { credentials: 'same-origin' })
            ]);
            const eventData = await eventRes.json();
            const mineData = await mineRes.json();

            if (eventData.success && eventData.event) {
                document.getElementById('event-title-display').textContent = decodePlainText(eventData.event.title || 'Event');
                const d = eventData.event.event_date ? new Date(eventData.event.event_date + 'T12:00:00') : null;
                const dateEl = document.getElementById('event-date-display');
                if (dateEl && d) {
                    dateEl.textContent = d.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
                }
            } else {
                showError(eventData.message || 'Feedback is not available for this event.');
                const form = document.getElementById('feedback-form');
                if (form) form.classList.add('hidden');
            }

            if (mineData.success && mineData.feedback) {
                document.getElementById('already-submitted').classList.remove('hidden');
                let scores = mineData.feedback.rating_scores || {};
                if (typeof scores === 'string') {
                    try { scores = JSON.parse(scores); } catch (e) { scores = {}; }
                }
                Object.keys(scores).forEach(function(key) {
                    setStars(key, parseInt(scores[key], 10));
                });
                if (mineData.feedback.feedback_text) {
                    document.getElementById('feedback_text').value = mineData.feedback.feedback_text;
                }
            }
        } catch (error) {
            console.error('Error:', error);
            showError('Could not load the feedback form.');
        }
    }

    if (selectedEventId) {
        loadEventFeedbackForm(selectedEventId);
    }

    const feedbackForm = document.getElementById('feedback-form');
    if (feedbackForm) {
        feedbackForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            const submitBtn = document.getElementById('feedback-submit-btn');
            const originalText = submitBtn.textContent;
            submitBtn.disabled = true;
            submitBtn.textContent = 'Submitting...';

            try {
                const csrfToken = await getCSRFToken();
                const payload = {
                    event_id: parseInt(document.getElementById('feedback_event_id').value, 10),
                    feedback_text: document.getElementById('feedback_text').value,
                    rating_scores: ratingScores,
                    submitted_via: fromEmail ? 'email_link' : 'portal',
                    csrf_token: csrfToken
                };

                let endpoint = apiBase + 'feedback';
                let fetchOpts = {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify(payload)
                };

                if (guestMode) {
                    endpoint = apiBase + 'feedback/guest';
                    if (hasSignedGuestLink) {
                        payload.uid = guestUid;
                        payload.token = guestToken;
                    } else {
                        const emailEl = document.getElementById('guest_email');
                        payload.email = emailEl ? emailEl.value.trim() : '';
                        if (!payload.email) {
                            showError('Please enter the email you used at check-in.');
                            submitBtn.disabled = false;
                            submitBtn.textContent = originalText;
                            return;
                        }
                    }
                    fetchOpts.body = JSON.stringify(payload);
                } else {
                    fetchOpts.credentials = 'same-origin';
                }

                const response = await fetch(endpoint, fetchOpts);
                const data = await response.json();
                if (data.success) {
                    showSuccess('Thank you! Your feedback was submitted.');
                    submitBtn.textContent = 'Submitted';
                    setTimeout(function() {
                        if (isLoggedIn) {
                            window.location.href = baseUrl + '/portal/feedback.php';
                        } else {
                            document.getElementById('feedback-form').classList.add('opacity-60', 'pointer-events-none');
                        }
                    }, 2200);
                } else {
                    const errors = data.errors || [data.message || 'Submission failed'];
                    showError(errors.join(', '));
                    submitBtn.disabled = false;
                    submitBtn.textContent = originalText;
                }
            } catch (error) {
                showError('An error occurred. Please try again.');
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
            }
        });
    }

    async function getCSRFToken() {
        try {
            const response = await fetch(apiBase.replace('/portal/', '/') + 'csrf-token');
            const data = await response.json();
            return data.token || '';
        } catch (e) {
            return '';
        }
    }

    function showError(message) {
        const el = document.getElementById('error-message');
        el.textContent = message;
        el.classList.remove('hidden');
        el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        setTimeout(function() { el.classList.add('hidden'); }, 7000);
    }

    function showSuccess(message) {
        const el = document.getElementById('success-message');
        el.textContent = message;
        el.classList.remove('hidden');
        el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    loadAttendedEvents();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
