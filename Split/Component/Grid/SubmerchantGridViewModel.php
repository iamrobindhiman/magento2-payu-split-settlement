<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Component\Grid;

use RKD\PayuSplit\Split\Model\Submerchant;
use RKD\PayuSplit\Split\Service\CreateMarkForgetter;
use Loki\AdminComponents\Component\Grid\GridViewModel as BaseGridViewModel;
use Loki\AdminComponents\Grid\Cell\CellAction;
use Loki\AdminComponents\Grid\Column\Column;
use Magento\Framework\DataObject;

/**
 * Loki grid view-model for the A7-2 submerchant-mapping view. Unlike A7-1, this grid IS
 * actionable (per-row Edit + a New button → the submerchant form). The masked key/MID and the
 * KYC/active labels are decorated onto each row by SubmerchantGrid\Collection.
 */
class SubmerchantGridViewModel extends BaseGridViewModel
{
    /**
     * Return EXACTLY the columns declared in the layout.
     *
     * Loki's default getColumns() only merges the layout config into an auto-detected base
     * set, which is unreliable for our DECORATED, non-DB columns (key_display, mid_display,
     * kyc_display, active_display) — it intermittently drops them. Declaring the set explicitly
     * makes it deterministic; per-row values for the decorated columns still resolve via the
     * base getValueFromItem() through the model's magic getData() accessors. (Same fix as the
     * settlement grid; see lesson_loki_admin_components_gotchas.)
     *
     * @return Column[]
     */
    #[\Override]
    public function getColumns(): array
    {
        return $this->columnLoader->sortColumns(
            $this->columnLoader->getColumnsFromBlock($this->block)
        );
    }

    /**
     * Render the onboarding state as a coloured badge.
     *
     * @return array<string, string>
     */
    #[\Override]
    public function getCellTemplates(): array
    {
        return [
            'onboarding_state' => 'RKD_PayuSplit::grid/cell/onboarding_state.phtml',
        ];
    }

    /**
     * State-aware onboarding actions (R-4, R-8): "Retry" on a FAILED vendor, "Mark e-sign sent"
     * on a vendor waiting for the Schedule C click, "Link PayU account" on a vendor with no PayU
     * account id who is not being onboarded, "Deactivate payouts" on any active mapping (the off
     * switch, which the edit form cannot provide for rows onboarding manages) and "Reactivate
     * payouts" to undo one. Each is a confirmed navigation to an ACL-gated, audited controller via a
     * keyed admin URL, as on the settlement grid.
     *
     * @param DataObject $item
     * @return CellAction[]
     */
    #[\Override]
    protected function getAdditionalActions(DataObject $item): array
    {
        return array_merge($this->onboardingActions($item), $this->payoutActions($item), $this->legacyActions($item));
    }

    /**
     * @param DataObject $item
     * @return CellAction[]
     */
    private function onboardingActions(DataObject $item): array
    {
        $sellerId = (int) $item->getData('seller_id');
        $state = (string) $item->getData('onboarding_state');
        $hasUuid = (string) $item->getData('payu_uuid') !== '';
        $isLegacy = !$hasUuid && (string) $item->getData('payu_merchant_key') !== '';

        $actions = [];
        if ($state === Submerchant::STATE_FAILED && !$isLegacy) {
            $actions[] = $this->action($sellerId, 'Retry', 'retry', 'Retry PayU onboarding for seller %d? It runs on the next scan.');
        }
        if ($state === Submerchant::STATE_AWAITING_ESIGN && $item->getData('esign_sent_at') === null) {
            $actions[] = $this->action(
                $sellerId,
                'Mark e-sign sent',
                'markesignsent',
                'Have you sent the Schedule C e-sign email for seller %d from the PayU dashboard?'
            );
        }
        if (!$hasUuid && in_array($state, [Submerchant::STATE_NOT_STARTED, Submerchant::STATE_FAILED, ''], true)) {
            $actions[] = $this->linkAction($sellerId);
        }
        return $actions;
    }

    /**
     * @param DataObject $item
     * @return CellAction[]
     */
    private function payoutActions(DataObject $item): array
    {
        $sellerId = (int) $item->getData('seller_id');
        if ((int) $item->getData('is_active') === 1) {
            return [$this->action(
                $sellerId,
                'Deactivate payouts',
                'deactivate',
                'Switch off PayU payouts for seller %d? New orders will not route their share.'
            )];
        }
        if ((string) $item->getData('payu_merchant_key') !== '') {
            // Shown on any inactive mapping; the controller acts only on one staff switched off.
            return [$this->action($sellerId, 'Reactivate payouts', 'reactivate', 'Switch PayU payouts for seller %d back on?')];
        }
        return [];
    }

    /**
     * "Reset legacy mapping" on a hand-typed mapping onboarding never touched; "Forget create mark" on a
     * failed vendor held back by a recorded create.
     *
     * @param DataObject $item
     * @return CellAction[]
     */
    private function legacyActions(DataObject $item): array
    {
        if ($item instanceof Submerchant && CreateMarkForgetter::isEligible($item)) {
            return [$this->action(
                (int) $item->getData('seller_id'),
                'Forget create mark',
                'forgetmark',
                'Clear the recorded PayU create for seller %d? PayU is checked first; clear it only if no account '
                . 'made by that create belongs to this vendor. A failed vendor may then be onboarded afresh.'
            )];
        }
        $isLegacy = (string) $item->getData('payu_merchant_key') !== ''
            && (string) $item->getData('payu_uuid') === ''
            && (string) $item->getData('payu_email') === ''
            && in_array((string) $item->getData('onboarding_state'), [Submerchant::STATE_NOT_STARTED, ''], true);
        if (!$isLegacy) {
            return [];
        }
        return [$this->action(
            (int) $item->getData('seller_id'),
            'Reset legacy mapping',
            'resetlegacy',
            'Reset the hand-entered PayU mapping for seller %d? Only do this if its merchant key is NOT a real '
            . 'PayU account. The key is cleared, payouts switch off, and onboarding will CREATE a new PayU '
            . 'account for this vendor.'
        )];
    }

    /**
     * A confirmed navigation to an ACL-gated, audited controller via a keyed admin URL.
     *
     * @param int $sellerId
     * @param string $label
     * @param string $route
     * @param string $question Contains one %d for the seller id; no quotes
     * @return CellAction
     */
    private function action(int $sellerId, string $label, string $route, string $question): CellAction
    {
        $url = $this->urlFactory->create()->getUrl('rkd_payu/submerchant/' . $route, ['seller_id' => $sellerId]);
        return $this->cellActionFactory->create(
            $label,
            null,
            null,
            sprintf("confirm('%s') && window.location.assign('%s')", sprintf($question, $sellerId), $url)
        );
    }

    /**
     * @param int $sellerId
     * @return CellAction
     */
    private function linkAction(int $sellerId): CellAction
    {
        $url = $this->urlFactory->create()->getUrl('rkd_payu/submerchant/link', ['seller_id' => $sellerId]);
        return $this->cellActionFactory->create(
            'Link PayU account',
            null,
            null,
            sprintf(
                "(() => { const account = prompt('PayU MID or account uuid to link to seller %d. It must "
                . "already exist under our PayU parent account.'); if (account && account.trim()) { "
                . "window.location.assign('%s?payu_account=' + encodeURIComponent(account.trim())); } })()",
                $sellerId,
                $url
            )
        );
    }
}
