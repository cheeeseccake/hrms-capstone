<?php
/**
 * Suggestion row for a clinic visit text field: wording buttons that appear
 * as the field is typed in. Included by the visit panels in views/clinic-visit/.
 *
 * @var string $optionKey Key in config/clinic-visit-suggestions.php.
 * @var string $targetId  DOM id of the field these suggestions fill.
 * @var string $ariaLabel Accessible name for the suggestion group.
 */
?>
<?php if (($clinicVisitQuickOptions[$optionKey] ?? []) !== []): ?>
    <div class="eh-visit-suggestions" data-suggestion-target="<?= htmlspecialchars($targetId) ?>" role="status" aria-live="polite" aria-label="<?= htmlspecialchars($ariaLabel) ?>" hidden>
        <?php foreach (($clinicVisitQuickOptions[$optionKey] ?? []) as $option): ?>
            <button type="button"><?= htmlspecialchars((string) $option) ?></button>
        <?php endforeach; ?>
        <span class="eh-visit-suggestion-empty" hidden>No matches. You can keep typing your own text.</span>
    </div>
<?php endif; ?>
