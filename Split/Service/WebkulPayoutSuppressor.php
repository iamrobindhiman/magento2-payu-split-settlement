<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * A5c-4b — the shared INV-17 decision behind all three "Pay Seller" suppressor plugins. Webkul has
 * no single payout choke-point (each path writes inline), so the plugins are separate; this service
 * holds the one safety rule they all share, so the FAIL-CLOSED guarantee lives in exactly one tested
 * place instead of being copied (and risking drift) across three plugins.
 *
 * The rule: a payout is suppressed when PayU's split-settlement manages the vendor for that order
 * (see PayuReleaseGuard). FAIL CLOSED — if ownership cannot be determined (a read error), the payout
 * is BLOCKED, never allowed: a delayed manual payout is recoverable, a double payment is not.
 */
class WebkulPayoutSuppressor
{
    /**
     * @param PayuReleaseGuard $guard
     * @param SaleslistPayoutKeyResolver $keyResolver
     * @param ResultFactory $resultFactory
     * @param ManagerInterface $messageManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly PayuReleaseGuard $guard,
        private readonly SaleslistPayoutKeyResolver $keyResolver,
        private readonly ResultFactory $resultFactory,
        private readonly ManagerInterface $messageManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * For the two admin "Pay Seller" controllers. Resolves the submitted saleslist row ids to
     * (order, seller) keys and returns a redirect Result to short-circuit the controller with when
     * ANY key is PayU-managed, or null to let the controller proceed normally. Fails CLOSED: a read
     * error returns a block redirect, never null.
     *
     * @param int $sellerId Seller id from the request — the redirect target + error-path log key
     * @param array<int, int|string> $saleslistEntityIds Submitted saleslist row ids (autoorderid / CSV)
     * @return ResultInterface|null Redirect when the payout must be blocked, null to proceed
     */
    public function controllerSuppression(int $sellerId, array $saleslistEntityIds): ?ResultInterface
    {
        try {
            foreach ($this->keyResolver->resolve($saleslistEntityIds) as $key) {
                if ($this->guard->isManagedByPayu($key['order_id'], $key['seller_id'])) {
                    return $this->blockManaged($key['order_id'], $key['seller_id']);
                }
            }
        } catch (\Throwable $t) {
            return $this->blockOnError($sellerId, $t);
        }

        return null;
    }

    /**
     * For the dormant Helper\Orders::paysellerpayment (which returns void — no redirect). True means
     * the payout is PayU-managed (or could not be verified) and must be skipped. Fails CLOSED: a read
     * error returns true.
     *
     * @param int $orderId
     * @param int $sellerId
     * @return bool
     */
    public function isManagedPayout(int $orderId, int $sellerId): bool
    {
        try {
            return $this->guard->isManagedByPayu($orderId, $sellerId);
        } catch (\Throwable $t) {
            $this->logger->critical(sprintf(
                'RKD_PayuSplit: INV-17 guard could not verify PayU ownership for a seller payout '
                . '(order %d, seller %d) — treating it as managed (skipping) to be safe: %s',
                $orderId,
                $sellerId,
                $t->getMessage()
            ), ['exception' => $t]);
            return true;
        }
    }

    /**
     * @param int $orderId
     * @param int $sellerId
     * @return ResultInterface
     */
    private function blockManaged(int $orderId, int $sellerId): ResultInterface
    {
        $this->logger->warning(sprintf(
            'RKD_PayuSplit: suppressed Webkul Pay Seller for order %d / seller %d — PayU split-settlement '
            . 'manages this payout (INV-17 anti-double-pay).',
            $orderId,
            $sellerId
        ));
        $this->messageManager->addErrorMessage(__(
            'This payout is managed by PayU split-settlement and is released automatically after '
            . 'delivery and the return window closes. Manual "Pay Seller" is disabled for order #%1 '
            . 'to prevent a double payment.',
            $orderId
        ));
        return $this->redirect($sellerId);
    }

    /**
     * @param int $sellerId
     * @param \Throwable $t
     * @return ResultInterface
     */
    private function blockOnError(int $sellerId, \Throwable $t): ResultInterface
    {
        $this->logger->critical(sprintf(
            'RKD_PayuSplit: INV-17 guard could not verify PayU ownership for a Webkul Pay Seller '
            . '(seller %d) — blocking the payout to be safe: %s',
            $sellerId,
            $t->getMessage()
        ), ['exception' => $t]);
        $this->messageManager->addErrorMessage(__(
            'This payout could not be verified against PayU split-settlement, so it was blocked to '
            . 'prevent a possible double payment. Please retry; if it persists, contact support.'
        ));
        return $this->redirect($sellerId);
    }

    /**
     * @param int $sellerId
     * @return ResultInterface
     */
    private function redirect(int $sellerId): ResultInterface
    {
        /** @var Redirect $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $result->setPath('marketplace/order/index', ['seller_id' => $sellerId]);
        return $result;
    }
}
