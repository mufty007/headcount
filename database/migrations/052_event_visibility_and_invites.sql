-- Event visibility (public / internal staff-only / invite-only) and per-member invites.
-- Run after existing event migrations.

ALTER TABLE events
    ADD COLUMN visibility VARCHAR(20) NOT NULL DEFAULT 'public'
    COMMENT 'public, internal, invite_only' AFTER status;

CREATE TABLE IF NOT EXISTS event_invites (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    organization_id INT UNSIGNED NOT NULL,
    event_id INT UNSIGNED NOT NULL COMMENT 'RSVP source event id (parent for all_sessions series)',
    user_id INT UNSIGNED NOT NULL,
    invited_by INT UNSIGNED NULL,
    invited_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    note VARCHAR(500) NULL,
    UNIQUE KEY uq_event_invite (event_id, user_id),
    KEY idx_org_event (organization_id, event_id),
    KEY idx_org_user (organization_id, user_id),
    CONSTRAINT fk_event_invites_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE,
    CONSTRAINT fk_event_invites_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
