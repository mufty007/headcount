<?php

namespace Headcount\Services;

use Headcount\Helpers\Database;

/**
 * Persists event custom questions on create/update (merge by id, options, depends_on).
 * Shared by the events API and admin event-edit page.
 */
class EventQuestionMergeService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * @param array<int, array<string, mixed>> $questions Same shape as API `questions` payload
     */
    public function mergeForEvent(int $eventId, array $questions): void
    {
        if (!is_array($questions)) {
            return;
        }
        $validQuestionTypes = ['text', 'short_text', 'checkbox', 'number', 'radio', 'dropdown', 'multi_checkbox'];
        if (empty($questions)) {
            try {
                $this->db->execute('DELETE FROM event_questions WHERE event_id = :event_id', ['event_id' => $eventId]);
            } catch (\Throwable $e) {
                error_log('EventQuestionMergeService: clear questions: ' . $e->getMessage());
            }
            return;
        }

        try {
            $hasDependsOnColumns = $this->db->hasColumn('event_questions', 'depends_on_question_id');
            $existing = $this->db->query('SELECT id FROM event_questions WHERE event_id = :event_id', ['event_id' => $eventId]);
            $existingIds = array_column($existing, 'id');
            $orderedIds = [];
            $payloadIds = [];
            $saveIndex = 0;

            foreach ($questions as $idx => $q) {
                if (!is_array($q) || empty($q['question_text'])) {
                    continue;
                }
                $qType = isset($q['question_type']) && in_array($q['question_type'], $validQuestionTypes, true)
                    ? $q['question_type'] : 'short_text';
                $options = isset($q['options']) && is_array($q['options']) ? $q['options'] : [];
                $qType = headcount_normalize_event_question_type($qType, $options);
                if (in_array($qType, ['radio', 'dropdown', 'multi_checkbox'], true) && headcount_event_question_option_labels($options) === []) {
                    continue;
                }
                $qId = isset($q['id']) ? (int) $q['id'] : 0;
                if ($qId > 0 && in_array($qId, $existingIds, true)) {
                    $orderedIds[$saveIndex] = $qId;
                } else {
                    $insertData = [
                        'event_id' => $eventId,
                        'question_text' => substr(trim((string) $q['question_text']), 0, 500),
                        'question_type' => $qType,
                        'is_required' => !empty($q['is_required']) ? 1 : 0,
                        'sort_order' => isset($q['sort_order']) ? (int) $q['sort_order'] : (int) $idx,
                    ];
                    if ($hasDependsOnColumns) {
                        $insertData['depends_on_question_id'] = null;
                        $insertData['depends_on_value'] = null;
                    }
                    $newId = (int) $this->db->insert('event_questions', $insertData);
                    $orderedIds[$saveIndex] = $newId;
                    if (!empty($options)) {
                        foreach ($options as $oi => $opt) {
                            $label = isset($opt['option_label']) ? trim((string) $opt['option_label']) : (is_string($opt) ? trim($opt) : '');
                            if ($label === '') {
                                continue;
                            }
                            $this->db->insert('event_question_options', [
                                'question_id' => $newId,
                                'option_label' => substr($label, 0, 255),
                                'sort_order' => isset($opt['sort_order']) ? (int) $opt['sort_order'] : (int) $oi,
                            ]);
                        }
                    }
                }
                $saveIndex++;
            }

            $saveIndex = 0;
            foreach ($questions as $idx => $q) {
                if (!is_array($q) || empty($q['question_text'])) {
                    continue;
                }
                $qType = isset($q['question_type']) && in_array($q['question_type'], $validQuestionTypes, true)
                    ? $q['question_type'] : 'short_text';
                $options = isset($q['options']) && is_array($q['options']) ? $q['options'] : [];
                $qType = headcount_normalize_event_question_type($qType, $options);
                if (in_array($qType, ['radio', 'dropdown', 'multi_checkbox'], true) && headcount_event_question_option_labels($options) === []) {
                    continue;
                }
                $questionId = isset($orderedIds[$saveIndex]) ? (int) $orderedIds[$saveIndex] : 0;
                if ($questionId <= 0) {
                    $saveIndex++;
                    continue;
                }
                $payloadIds[] = $questionId;
                $rawDep = $q['depends_on_question_id'] ?? null;
                if ($rawDep === '' || $rawDep === null) {
                    $dependsOnId = null;
                } elseif (is_string($rawDep) && preg_match('/^__idx_(\d+)$/', (string) $rawDep, $m)) {
                    $dependsOnId = isset($orderedIds[(int) $m[1]]) ? (int) $orderedIds[(int) $m[1]] : null;
                } else {
                    $dependsOnId = (int) $rawDep;
                    if ($dependsOnId <= 0) {
                        $dependsOnId = null;
                    }
                }
                $dependsOnValue = isset($q['depends_on_value']) && $q['depends_on_value'] !== '' && $q['depends_on_value'] !== null
                    ? substr(trim((string) $q['depends_on_value']), 0, 500) : null;
                $sortOrder = isset($q['sort_order']) ? (int) $q['sort_order'] : (int) $idx;
                $qId = isset($q['id']) ? (int) $q['id'] : 0;
                if ($qId > 0 && in_array($qId, $existingIds, true)) {
                    $updateData = [
                        'question_text' => substr(trim((string) $q['question_text']), 0, 500),
                        'question_type' => $qType,
                        'is_required' => !empty($q['is_required']) ? 1 : 0,
                        'sort_order' => $sortOrder,
                    ];
                    if ($hasDependsOnColumns) {
                        $updateData['depends_on_question_id'] = $dependsOnId;
                        $updateData['depends_on_value'] = $dependsOnValue;
                    }
                    $this->db->update('event_questions', $questionId, $updateData);
                    try {
                        $this->db->execute('DELETE FROM event_question_options WHERE question_id = :qid', ['qid' => $questionId]);
                    } catch (\Throwable $e) {
                        // table may not exist
                    }
                    if (!empty($options)) {
                        foreach ($options as $oi => $opt) {
                            $label = isset($opt['option_label']) ? trim((string) $opt['option_label']) : (is_string($opt) ? trim($opt) : '');
                            if ($label === '') {
                                continue;
                            }
                            $this->db->insert('event_question_options', [
                                'question_id' => $questionId,
                                'option_label' => substr($label, 0, 255),
                                'sort_order' => isset($opt['sort_order']) ? (int) $opt['sort_order'] : (int) $oi,
                            ]);
                        }
                    }
                } else {
                    if ($hasDependsOnColumns) {
                        $this->db->update('event_questions', $questionId, [
                            'depends_on_question_id' => $dependsOnId,
                            'depends_on_value' => $dependsOnValue,
                        ]);
                    }
                }
                $saveIndex++;
            }

            if (!empty($payloadIds)) {
                $placeholders = implode(',', array_map('intval', $payloadIds));
                $this->db->execute(
                    "DELETE FROM event_questions WHERE event_id = :event_id AND id NOT IN ($placeholders)",
                    ['event_id' => $eventId]
                );
            } else {
                $this->db->execute('DELETE FROM event_questions WHERE event_id = :event_id', ['event_id' => $eventId]);
            }
        } catch (\Throwable $e) {
            error_log('EventQuestionMergeService::mergeForEvent: ' . $e->getMessage());
            throw $e;
        }
    }
}
