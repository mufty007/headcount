<?php

namespace Headcount\Services;

use Headcount\Core\FileUpload;
use Headcount\Helpers\Database;

/**
 * Speakers and organisers linked to an event (stored on series parent for recurring).
 */
class EventPeopleService
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function tableExists(): bool
    {
        try {
            $r = $this->db->queryOne(
                "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'event_people' LIMIT 1"
            );
            return !empty($r);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Event id whose rows we read/write (parent id when instance belongs to a series).
     */
    public static function peopleStorageEventId(array $eventRow): int
    {
        $pid = isset($eventRow['parent_event_id']) ? (int) $eventRow['parent_event_id'] : 0;
        if ($pid > 0) {
            return $pid;
        }
        return (int) ($eventRow['id'] ?? 0);
    }

    /**
     * @return list<array{id:int,role:string,display_name:string,title:?string,image_path:?string,sort_order:int}>
     */
    public function listForEventId(int $eventId): array
    {
        if ($eventId <= 0 || !$this->tableExists()) {
            return [];
        }
        return $this->db->query(
            "SELECT id, event_id, role, sort_order, display_name, title, image_path
             FROM event_people WHERE event_id = :eid
             ORDER BY role ASC, sort_order ASC, id ASC",
            ['eid' => $eventId]
        ) ?: [];
    }

    public function deleteForEventId(int $eventId): void
    {
        if ($eventId <= 0 || !$this->tableExists()) {
            return;
        }
        $this->db->execute('DELETE FROM event_people WHERE event_id = :eid', ['eid' => $eventId]);
    }

    /**
     * Replace all people for an event. Upload files from $_FILES keys event_person_image_{index}.
     *
     * @param array<string,mixed> $input Request body (POST)
     * @param array<string,mixed> $files Typically $_FILES
     * @param array<string,mixed> $config App config (uploads)
     */
    public function replaceFromAdminInput(int $peopleTargetEventId, array $input, array $files, array $config): void
    {
        if ($peopleTargetEventId <= 0 || !$this->tableExists()) {
            return;
        }
        if (!array_key_exists('event_people', $input)) {
            return;
        }
        $raw = $input['event_people'];
        if (is_string($raw)) {
            $people = json_decode($raw, true);
        } else {
            $people = is_array($raw) ? $raw : [];
        }
        if (!is_array($people)) {
            $people = [];
        }

        $uploadConfig = $config['uploads'] ?? [];
        $uploadConfig['allowed_types'] = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $uploadConfig['allowed_extensions'] = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $uploadConfig['max_size'] = 5242880;
        if (empty($uploadConfig['upload_path'])) {
            $uploadConfig['upload_path'] = dirname(__DIR__, 2) . '/uploads/';
        }
        $uploadConfig['upload_path'] = rtrim(realpath($uploadConfig['upload_path']) ?: $uploadConfig['upload_path'], '/\\') . '/';

        $this->deleteForEventId($peopleTargetEventId);

        foreach ($people as $idx => $p) {
            if (!is_array($p)) {
                continue;
            }
            $role = isset($p['role']) ? trim((string) $p['role']) : 'speaker';
            if (!in_array($role, ['speaker', 'organiser'], true)) {
                $role = 'speaker';
            }
            $name = isset($p['display_name']) ? trim((string) $p['display_name']) : '';
            if ($name === '') {
                continue;
            }
            $title = isset($p['title']) ? trim((string) $p['title']) : '';
            $title = $title === '' ? null : substr($title, 0, 255);
            $sortOrder = isset($p['sort_order']) ? (int) $p['sort_order'] : (int) $idx;
            $imagePath = isset($p['image_path']) ? trim((string) $p['image_path']) : '';
            $imagePath = $imagePath === '' ? null : substr($imagePath, 0, 500);

            if (!empty($p['remove_image'])) {
                $imagePath = null;
            }

            $fileKey = 'event_person_image_' . $idx;
            if (!empty($files[$fileKey]) && isset($files[$fileKey]['error']) && (int) $files[$fileKey]['error'] === UPLOAD_ERR_OK) {
                try {
                    $fileUpload = new FileUpload($uploadConfig);
                    $uploadResult = $fileUpload->upload($files[$fileKey], 'event-people');
                    $imagePath = 'event-people/' . $uploadResult['filename'];
                    $full = $uploadConfig['upload_path'] . str_replace('/', DIRECTORY_SEPARATOR, $imagePath);
                    if (!file_exists($full) || !is_file($full)) {
                        $imagePath = isset($p['image_path']) ? trim((string) $p['image_path']) : null;
                        if ($imagePath === '') {
                            $imagePath = null;
                        }
                    }
                } catch (\Throwable $e) {
                    error_log('event person image upload: ' . $e->getMessage());
                }
            }

            $this->db->insert('event_people', [
                'event_id' => $peopleTargetEventId,
                'role' => $role,
                'sort_order' => $sortOrder,
                'display_name' => substr($name, 0, 255),
                'title' => $title,
                'image_path' => $imagePath,
            ]);
        }
    }

    /**
     * Copy rows from one event to another (duplicate). Reuses same image paths (no file copy).
     */
    public function copyFromEvent(int $fromEventId, int $toEventId): void
    {
        if ($fromEventId <= 0 || $toEventId <= 0 || !$this->tableExists()) {
            return;
        }
        $rows = $this->listForEventId($fromEventId);
        foreach ($rows as $r) {
            $this->db->insert('event_people', [
                'event_id' => $toEventId,
                'role' => $r['role'],
                'sort_order' => (int) $r['sort_order'],
                'display_name' => $r['display_name'],
                'title' => $r['title'] ?? null,
                'image_path' => $r['image_path'] ?? null,
            ]);
        }
    }
}
