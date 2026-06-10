<?php

namespace Headcount\Models;

use Headcount\Helpers\Database;
use Headcount\Helpers\OrgTimeZone;

/**
 * Organization Model
 */
class Organization
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Find organization by ID
     */
    public function find($id)
    {
        $sql = "SELECT * FROM organizations WHERE id = :id LIMIT 1";
        return $this->db->queryOne($sql, ['id' => $id]);
    }

    /**
     * Create new organization
     */
    public function create($data)
    {
        $insertData = [
            'name' => $data['name'] ?? '',
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'logo' => $data['logo'] ?? null,
            'timezone' => $data['timezone'] ?? OrgTimeZone::FALLBACK_IANA,
        ];

        $id = $this->db->insert('organizations', $insertData);
        return $this->find($id);
    }

    /**
     * Update organization
     */
    public function update($id, $data)
    {
        $updateData = [];
        
        // Basic organization fields
        if (isset($data['name'])) $updateData['name'] = $data['name'];
        if (isset($data['slug'])) $updateData['slug'] = $data['slug'];
        if (isset($data['email'])) $updateData['email'] = $data['email'];
        if (isset($data['phone'])) $updateData['phone'] = $data['phone'];
        if (isset($data['address'])) $updateData['address'] = $data['address'];
        if (isset($data['logo'])) $updateData['logo'] = $data['logo'];
        if (isset($data['logo_path'])) $updateData['logo_path'] = $data['logo_path'];
        if (isset($data['primary_color'])) $updateData['primary_color'] = $data['primary_color'];
        if (isset($data['timezone'])) $updateData['timezone'] = $data['timezone'];
        if (isset($data['date_format'])) $updateData['date_format'] = $data['date_format'];
        if (isset($data['time_format'])) $updateData['time_format'] = $data['time_format'];
        
        // Stripe fields
        if (isset($data['stripe_publishable_key'])) $updateData['stripe_publishable_key'] = $data['stripe_publishable_key'];
        if (isset($data['stripe_secret_key_encrypted'])) $updateData['stripe_secret_key_encrypted'] = $data['stripe_secret_key_encrypted'];
        if (isset($data['stripe_webhook_secret_encrypted'])) $updateData['stripe_webhook_secret_encrypted'] = $data['stripe_webhook_secret_encrypted'];
        if (isset($data['stripe_test_mode'])) $updateData['stripe_test_mode'] = $data['stripe_test_mode'] ? 1 : 0;
        
        // SMTP fields
        if (isset($data['smtp_api_key_encrypted'])) $updateData['smtp_api_key_encrypted'] = $data['smtp_api_key_encrypted'];
        if (isset($data['smtp_from_email'])) $updateData['smtp_from_email'] = $data['smtp_from_email'];
        if (isset($data['smtp_from_name'])) $updateData['smtp_from_name'] = $data['smtp_from_name'];
        if (isset($data['smtp_reply_to'])) $updateData['smtp_reply_to'] = $data['smtp_reply_to'];
        
        // Notification fields (if they exist in schema, otherwise will be ignored)
        if (isset($data['email_reminders_enabled'])) $updateData['email_reminders_enabled'] = $data['email_reminders_enabled'] ? 1 : 0;
        if (isset($data['reminder_1week'])) $updateData['reminder_1week'] = $data['reminder_1week'] ? 1 : 0;
        if (isset($data['reminder_1day'])) $updateData['reminder_1day'] = $data['reminder_1day'] ? 1 : 0;
        if (isset($data['reminder_2hours'])) $updateData['reminder_2hours'] = $data['reminder_2hours'] ? 1 : 0;

        if (!empty($updateData)) {
            $this->db->update('organizations', $id, $updateData);
        }

        return $this->find($id);
    }

    /**
     * Get organization settings (deprecated - settings are now in organizations table)
     * Kept for backward compatibility but returns empty array
     */
    public function getSettings($organizationId)
    {
        // Settings are now stored directly in organizations table
        // This method is kept for backward compatibility
        return [];
    }

    /**
     * Set organization setting (deprecated - use update() instead)
     * Kept for backward compatibility
     */
    public function setSetting($organizationId, $key, $value)
    {
        // Settings are now stored directly in organizations table
        // Use update() method instead
        return $this->update($organizationId, [$key => $value]);
    }
}
