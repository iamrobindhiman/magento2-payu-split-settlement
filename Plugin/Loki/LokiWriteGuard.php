<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Plugin\Loki;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Message\ManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Closes Loki's generic write paths that can reach money-routing, bank or audit data
 * (wave-3 I-F1 / M1 / S-F3, wave-4 security F1 and correctness F1).
 *
 * Loki serves every grid and form through one AJAX endpoint whose only ACL is
 * Loki_Components::index, and the client chooses the layout handle, the action and — on a form
 * save — every property name, each of which becomes a setter call. So without this, any admin who
 * may use ANY Loki grid could:
 *   - inline-edit any column of any row: re-point a vendor's PayU merchant key, set is_active,
 *     blank a PayU uuid, rewrite a settlement or audit row, or — through the Shiprocket pickup
 *     grid, whose rows are marketplace_userdata — replace a seller's bank account before it is
 *     sent to PayU, one-shot;
 *   - duplicate a row (no item convertor on that path);
 *   - post "id_field_name" on a form save, which calls setIdFieldName() and makes the UPDATE land on
 *     a different row from the one the convertor checked;
 *   - save or delete a submerchant mapping without the RKD_PayuSplit manage permission.
 *
 * No Loki grid in this install uses inline edit or duplicate, so both are refused on EVERY Loki
 * component. On RKD_PayuSplit forms, save and delete need RKD_PayuSplit::submerchant_manage and a save may
 * only carry the form's own columns.
 */
class LokiWriteGuard
{
    public const MANAGE_RESOURCE = 'RKD_PayuSplit::submerchant_manage';

    /**
     * Every column the submerchant form may post: the ones it edits, the primary key, and the
     * hidden and timestamp columns it renders and the convertor then discards. Anything else —
     * id_field_name above all — is refused, because every posted key becomes a setter call.
     */
    private const FORM_COLUMNS = [
        'entity_id', 'seller_id', 'payu_merchant_key', 'payu_mid', 'kyc_status', 'is_active',
        'created_at', 'updated_at',
        'onboarding_state', 'payu_uuid', 'payu_email', 'last_error', 'error_class', 'attempts',
        'last_attempt_at', 'next_attempt_at', 'locked_until', 'esign_sent_at', 'esign_sent_by', 'activated_at',
    ];

    /**
     * @param AuthorizationInterface $authorization
     * @param ManagerInterface $messageManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly AuthorizationInterface $authorization,
        private readonly ManagerInterface $messageManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Grid inline edit — refused on RKD_PayuSplit grids. Runs on every grid update, so only a request
     * that actually carries an inline edit is stopped; search, paging and sorting go through.
     *
     * @param object $subject
     * @param callable $proceed
     * @param object $repository Loki GridRepository
     * @param array<string, mixed> $value
     * @return void
     */
    public function aroundExecute(object $subject, callable $proceed, object $repository, array $value): void
    {
        $action = $this->actionName($subject);
        $refusal = match ($action) {
            'inline_edit' => isset($value['inline_edit']) ? 'inline edit is disabled' : null,
            'duplicate' => 'duplicate is disabled',
            'save', 'delete' => $this->isPayuComponent($repository) ? $this->payuWriteRefusal($action, $value) : null,
            default => $this->isPayuComponent($repository) ? 'unknown action' : null,
        };
        if ($refusal === null) {
            $proceed($repository, $value);
            return;
        }

        $this->logger->warning('Loki write refused: ' . $action . ' — ' . $refusal);
        $this->messageManager->addErrorMessage(__(
            'This record cannot be changed that way. Use the actions on its grid, which are checked and audited.'
        ));
    }

    /**
     * @param string $action
     * @param array<string, mixed> $value
     * @return string|null Why it is refused, or null to let it through
     */
    private function payuWriteRefusal(string $action, array $value): ?string
    {
        if (!$this->authorization->isAllowed(self::MANAGE_RESOURCE)) {
            return 'no manage permission';
        }
        if ($action === 'save') {
            $posted = is_array($value['item'] ?? null) ? array_keys($value['item']) : [];
            $unknown = array_diff($posted, self::FORM_COLUMNS);
            if ($unknown !== []) {
                return 'unexpected field(s) ' . implode(', ', array_map('strval', $unknown));
            }
        }
        return null;
    }

    /**
     * @param object $repository
     * @return bool
     */
    private function isPayuComponent(object $repository): bool
    {
        if (!method_exists($repository, 'getProvider')) {
            return false;
        }
        try {
            $provider = $repository->getProvider();
        } catch (\Throwable $t) {
            return false;
        }
        if (!is_object($provider)) {
            return false;
        }
        // The class or any parent: the provider arrives as a generated Interceptor subclass.
        foreach (array_merge([get_class($provider)], array_values(class_parents($provider) ?: [])) as $class) {
            if (str_starts_with($class, 'RKD\\PayuSplit\\')) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param object $subject
     * @return string
     */
    private function actionName(object $subject): string
    {
        $class = get_class($subject);
        return match (true) {
            str_contains($class, 'InlineEditAction') => 'inline_edit',
            str_contains($class, 'DuplicateAction') => 'duplicate',
            str_contains($class, 'DeleteAction') => 'delete',
            str_contains($class, 'SaveAction') => 'save',
            default => 'unknown',
        };
    }
}
