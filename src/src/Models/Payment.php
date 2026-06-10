<?php

namespace Headcount\Models;

use Headcount\Core\Database;

/**
 * Payment Model
 * Handles database operations for payments
 */
class Payment
{
    private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Find payment by ID
     */
    public function find($id)
    {
        $sql = "SELECT * FROM payments WHERE id = :id";
        return $this->db->queryOne($sql, ['id' => $id]);
    }

    /**
     * Find payment by Stripe payment intent ID (only for Stripe payments)
     */
    public function findByPaymentIntent($paymentIntentId)
    {
        if ($paymentIntentId === null || $paymentIntentId === '') {
            return null;
        }
        $sql = "SELECT * FROM payments 
                WHERE stripe_payment_intent_id = :payment_intent_id";
        return $this->db->queryOne($sql, ['payment_intent_id' => $paymentIntentId]);
    }

    /**
     * Create payment record
     */
    public function create($data)
    {
        $defaults = [
            'status' => 'pending',
            'currency' => 'USD',
        ];

        $data = array_merge($defaults, $data);
        $id = $this->db->insert('payments', $data);
        return $this->find($id);
    }

    /**
     * Update payment
     */
    public function update($id, $data)
    {
        $this->db->update('payments', $id, $data);
        return $this->find($id);
    }

    /**
     * Get payments for event
     */
    public function getEventPayments($eventId)
    {
        $sql = "SELECT p.*, u.first_name, u.last_name, u.email 
                FROM payments p
                JOIN users u ON p.user_id = u.id
                WHERE p.event_id = :event_id
                ORDER BY p.created_at DESC";

        return $this->db->query($sql, ['event_id' => $eventId]);
    }

    /**
     * Get financial summary (includes cash and Stripe; uses app status 'paid' not 'succeeded')
     */
    public function getFinancialSummary($organizationId, $dateFrom = null, $dateTo = null)
    {
        $sql = "SELECT 
                    SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END) as total_revenue,
                    SUM(CASE WHEN status IN ('refunded', 'partially_refunded') THEN COALESCE(refund_amount, 0) ELSE 0 END) as total_refunds,
                    SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END) as pending_payments,
                    COUNT(*) as total_transactions
                FROM payments p
                JOIN events e ON p.event_id = e.id
                WHERE e.organization_id = :org_id";

        $params = ['org_id' => $organizationId];

        if ($dateFrom) {
            $sql .= " AND DATE(p.created_at) >= :date_from";
            $params['date_from'] = $dateFrom;
        }

        if ($dateTo) {
            $sql .= " AND DATE(p.created_at) <= :date_to";
            $params['date_to'] = $dateTo;
        }

        return $this->db->queryOne($sql, $params);
    }

    /**
     * Find cash (or Stripe) payment for event + user
     */
    public function findByEventAndUser($eventId, $userId)
    {
        $sql = "SELECT * FROM payments WHERE event_id = :event_id AND user_id = :user_id ORDER BY created_at DESC LIMIT 1";
        return $this->db->queryOne($sql, ['event_id' => $eventId, 'user_id' => $userId]);
    }
}
