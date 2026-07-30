<?php
/**
 * Public Support page — IMCA community platform
 */
require_once __DIR__ . '/includes/public-site.php';

imca_public_page_start('Support', 'support');
?>
        <p class="mt-3 max-w-2xl text-base text-slate-600">
            Need help with the <?= e($APP_NAME) ?> member portal, events, programs, or facility bookings?
            Reach the Indianapolis Muslim Community Association team using the contacts below.
        </p>

        <div class="mt-8 grid gap-4 sm:grid-cols-2">
            <a href="mailto:<?= e($ORG_EMAIL) ?>" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm transition hover:border-slate-300 hover:shadow-md">
                <p class="text-xs font-bold uppercase tracking-widest text-slate-400">Email</p>
                <p class="mt-2 text-lg font-extrabold text-slate-900"><?= e($ORG_EMAIL) ?></p>
                <p class="mt-1 text-sm text-slate-500">Best for account, RSVP, and billing questions</p>
            </a>
            <a href="tel:<?= e($ORG_PHONE_TEL) ?>" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm transition hover:border-slate-300 hover:shadow-md">
                <p class="text-xs font-bold uppercase tracking-widest text-slate-400">Phone</p>
                <p class="mt-2 text-lg font-extrabold text-slate-900"><?= e($ORG_PHONE) ?></p>
                <p class="mt-1 text-sm text-slate-500"><?= e($ORG_HOURS) ?></p>
            </a>
            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:col-span-2">
                <p class="text-xs font-bold uppercase tracking-widest text-slate-400">Visit &amp; mail</p>
                <p class="mt-2 text-lg font-extrabold text-slate-900"><?= e($ORG_ADDRESS) ?></p>
                <p class="mt-3 text-sm text-slate-600">
                    More about IMCA programs and Masjid Al-Fajr:
                    <a class="font-semibold text-blue-600 underline underline-offset-2 hover:text-blue-700" href="<?= e($ORG_WEBSITE) ?>" target="_blank" rel="noopener noreferrer"><?= e($ORG_WEBSITE) ?></a>
                </p>
            </div>
        </div>

        <div class="legal-prose mt-8 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
            <h2 class="!mt-0">How we can help</h2>
            <ul>
                <li><strong>Sign-in &amp; account:</strong> password resets, magic links, email verification, profile updates.</li>
                <li><strong>Events &amp; RSVPs:</strong> registration issues, tickets, check-in questions, calendar links.</li>
                <li><strong>Programs &amp; family:</strong> enrollments, household members, youth registrations.</li>
                <li><strong>Facilities:</strong> booking status, payments, cancellations, access instructions.</li>
                <li><strong>Payments &amp; receipts:</strong> charges, refunds, transfer questions.</li>
                <li><strong>Emails:</strong> missing messages, unsubscribe, campaign preferences.</li>
            </ul>

            <h3>Before you write</h3>
            <p>Including these details helps us respond faster:</p>
            <ul>
                <li>The email address on your member account</li>
                <li>Event, program, or booking name and date</li>
                <li>Screenshots or confirmation/receipt numbers when relevant</li>
            </ul>

            <h3>Policies</h3>
            <p>
                Review our <a href="<?= e($legalUrls['privacy']) ?>">Privacy Policy</a> and
                <a href="<?= e($legalUrls['terms']) ?>">Terms of Service</a>.
                To browse community events, open the
                <a href="<?= e($portalHome) ?>">member portal</a>.
            </p>

            <h3>Emergency or pastoral needs</h3>
            <p>
                For urgent pastoral, janazah, or social-service needs outside this software platform,
                use the contacts published on
                <a href="<?= e($ORG_WEBSITE) ?>" target="_blank" rel="noopener noreferrer">imcaindy.org</a>
                or call the main office line above during business hours.
            </p>
        </div>

        <div class="mt-8 flex flex-wrap gap-3">
            <a href="mailto:<?= e($ORG_EMAIL) ?>?subject=<?= rawurlencode($APP_NAME . ' portal support') ?>"
               class="inline-flex min-h-[44px] items-center justify-center rounded-xl bg-slate-900 px-5 py-2.5 text-sm font-bold text-white hover:bg-slate-800">
                Email support
            </a>
            <a href="<?= e($portalHome) ?>"
               class="inline-flex min-h-[44px] items-center justify-center rounded-xl border border-slate-300 bg-white px-5 py-2.5 text-sm font-bold text-slate-800 hover:bg-slate-50">
                Back to portal
            </a>
            <a href="<?= e($ORG_WEBSITE) ?>" target="_blank" rel="noopener noreferrer"
               class="inline-flex min-h-[44px] items-center justify-center rounded-xl border border-slate-300 bg-white px-5 py-2.5 text-sm font-bold text-slate-800 hover:bg-slate-50">
                Visit imcaindy.org
            </a>
        </div>
<?php
imca_public_page_end();
