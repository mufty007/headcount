<?php
/**
 * Period / tab switcher for chart cards
 * Expects: $cardTabs (array of ['id'=>'week', 'label'=>'Week', 'active'=>true, 'click'=>'loadRsvps()']),
 *          $cardTabsVar (string, Alpine var name, default 'cardTab'),
 *          $cardTabsAlpine (bool, use Alpine binding for active state),
 *          $cardTabsParentScope (bool, bind to parent x-data var — no nested x-data)
 */
if (!isset($cardTabs) || !is_array($cardTabs) || empty($cardTabs)) { return; }
$cardTabsVar          = $cardTabsVar ?? 'cardTab';
$cardTabsAlpine       = $cardTabsAlpine ?? true;
$cardTabsParentScope  = $cardTabsParentScope ?? false;
$defaultActive  = null;
foreach ($cardTabs as $t) {
    if (!empty($t['active'])) { $defaultActive = $t['id'] ?? ''; break; }
}
if ($defaultActive === null) {
    $defaultActive = $cardTabs[0]['id'] ?? '';
}
?>
<div class="inline-flex items-center gap-1 rounded-lg border border-gray-200 bg-gray-50 p-1 dark:border-gray-700 dark:bg-gray-800/50"
     <?php if ($cardTabsAlpine && !$cardTabsParentScope): ?>x-data="{ <?= e($cardTabsVar) ?>: '<?= e($defaultActive) ?>' }"<?php endif; ?>>
    <?php foreach ($cardTabs as $tab):
        $tabId = $tab['id'] ?? '';
        $tabLabel = $tab['label'] ?? $tabId;
        $tabClick = $tab['click'] ?? '';
        if ($tabId === '') continue;
    ?>
        <button type="button"
                class="ta-card-tab"
                <?php if ($cardTabsAlpine): ?>
                    @click="<?= e($cardTabsVar) ?> = '<?= e($tabId) ?>'<?= $tabClick !== '' ? '; ' . $tabClick : '' ?>"
                    :class="<?= e($cardTabsVar) ?> === '<?= e($tabId) ?>' ? 'active' : ''"
                <?php else: ?>
                    class="ta-card-tab <?= ($tabId === $defaultActive) ? 'active' : '' ?>"
                <?php endif; ?>
                data-tab="<?= e($tabId) ?>">
            <?= e($tabLabel) ?>
        </button>
    <?php endforeach; ?>
</div>
