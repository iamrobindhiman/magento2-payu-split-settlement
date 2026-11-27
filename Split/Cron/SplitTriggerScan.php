<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Cron;

use RKD\PayuSplit\Split\Exception\SplitCalculationException;
use RKD\PayuSplit\Split\Model\ResourceModel\SellerOrderStatus;
use RKD\PayuSplit\Split\Model\SplitOrder;
use RKD\PayuSplit\Split\Model\SplitSegment;
use RKD\PayuSplit\Split\Model\SplitConfig;
use RKD\PayuSplit\Split\Service\MoneyEventDetector;
use RKD\PayuSplit\Split\Service\PendingSplitLoader;
use RKD\PayuSplit\Split\Service\SegmentLoader;
use RKD\PayuSplit\Split\Service\SplitRequester;
use RKD\PayuSplit\Split\Service\SplitStateMachine;
use RKD\PayuSplit\Split\Service\SplitTriggerResolver;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * The first of the three settlement scans (matrix §5): fire payment_split for each
 * order at the earliest of its trigger conditions, exactly once.
 *
 * For every unfinished header (PENDING / REQUESTED) it reads the order's vendor
 * seller-ids from the snapshot segments and each seller's shipped-or-cancelled
 * status from Webkul, asks the resolver whether a trigger fires, and either stamps
 * the trigger + hands the header to the idempotent SplitRequester, or — if the
 * order is ageing past the alert threshold without being splittable — raises a
 * compliance alarm so an operator can act before the RBI launch-gate ceiling lands.
 *
 * Each header is processed in its own try/catch: a gateway or unexpected failure
 * marks that one header FAILED and the scan continues, so one bad order never
 * stalls the batch (AGG-103 isolation).
 *
 * MONEY_EVENT (a refund needing the split to exist) is sourced by the MoneyEventDetector
 * (A5b): a cancelled vendor portion on a not-yet-split order brings the split forward so
 * the customer can be refunded promptly (Fact 4 — var8 needs the split to exist first).
 */
class SplitTriggerScan
{
    /**
     * @param PendingSplitLoader $headerLoader
     * @param SegmentLoader $segmentLoader
     * @param SellerOrderStatus $sellerOrderStatus
     * @param SplitTriggerResolver $resolver
     * @param MoneyEventDetector $moneyEventDetector
     * @param SplitRequester $requester
     * @param SplitStateMachine $stateMachine
     * @param SplitConfig $config
     * @param DateTime $dateTime
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly PendingSplitLoader $headerLoader,
        private readonly SegmentLoader $segmentLoader,
        private readonly SellerOrderStatus $sellerOrderStatus,
        private readonly SplitTriggerResolver $resolver,
        private readonly MoneyEventDetector $moneyEventDetector,
        private readonly SplitRequester $requester,
        private readonly SplitStateMachine $stateMachine,
        private readonly SplitConfig $config,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return void
     */
    public function execute(): void
    {
        $nowGmt = $this->dateTime->gmtDate();
        $ceilingDays = $this->config->getCeilingDays();
        $alertDays = $this->config->getAlertDays();

        foreach ($this->headerLoader->loadUnfinished() as $header) {
            try {
                $this->processHeader($header, $nowGmt, $ceilingDays, $alertDays);
            } catch (SplitCalculationException $e) {
                // Snapshot corruption (segments no longer reconcile to the txn) will not
                // self-heal — terminalise so it stops being retried and is investigated.
                $this->stateMachine->markFailed($header, 'snapshot corruption: ' . $e->getMessage());
                $this->logger->error(
                    sprintf('RKD_PayuSplit: order %d snapshot corruption — %s', $header->getOrderId(), $e->getMessage()),
                    ['exception' => $e]
                );
            } catch (\Throwable $t) {
                // Unexpected/infra error (DB deadlock, lock timeout, etc.) is likely
                // transient — do NOT terminalise; leave the header for the next run to
                // retry. A persistent one is escalated by the aged-order compliance alarm.
                $this->logger->error(
                    sprintf('RKD_PayuSplit: split-trigger scan error for order %d (will retry) — %s', $header->getOrderId(), $t->getMessage()),
                    ['exception' => $t]
                );
            }
        }
    }

    /**
     * @param SplitOrder $header
     * @param string $nowGmt
     * @param int $ceilingDays
     * @param int $alertDays
     * @return void
     */
    private function processHeader(SplitOrder $header, string $nowGmt, int $ceilingDays, int $alertDays): void
    {
        $orderId = $header->getOrderId();
        $vendorSellerIds = $this->vendorSellerIds($orderId);
        $terminalFlags = $this->sellerOrderStatus->getTerminalFlags($orderId);

        $decision = $this->resolver->resolve(
            $vendorSellerIds,
            $terminalFlags,
            $this->moneyEventDetector->isMoneyEvent($orderId),
            $this->paidAt($header),
            $nowGmt,
            $ceilingDays,
            $alertDays
        );

        if ($decision->trigger !== null) {
            $header->setData('split_trigger', $decision->trigger);
            $this->requester->request($header);
        }

        // Compliance alarm (matrix M-1): any aged order that has NOT reached a completed
        // split must alarm before the ceiling auto-settles — whether it never triggered,
        // OR triggered but could not split (no mihpayid yet, perpetual transient). A
        // non-null trigger no longer suppresses the alert.
        if ($decision->alertDue && $header->getSplitState() !== SplitOrder::STATE_DONE) {
            $this->logger->warning(sprintf(
                'RKD_PayuSplit: order %d is unsplit at >= %d days and not yet settled — investigate '
                . 'before the T+%d ceiling auto-settles the aggregator txn (RBI PA control).',
                $orderId,
                $alertDays,
                $ceilingDays
            ));
        }
    }

    /**
     * @param int $orderId
     * @return int[]
     */
    private function vendorSellerIds(int $orderId): array
    {
        $sellerIds = [];
        foreach ($this->segmentLoader->loadByOrderId($orderId) as $segment) {
            if ($segment->getSegmentType() === SplitSegment::TYPE_VENDOR) {
                $sellerIds[] = $segment->getSellerId();
            }
        }
        return $sellerIds;
    }

    /**
     * @param SplitOrder $header
     * @return string|null
     */
    private function paidAt(SplitOrder $header): ?string
    {
        $paidAt = $header->getData('paid_at');
        return ($paidAt === null || $paidAt === '') ? null : (string) $paidAt;
    }
}
