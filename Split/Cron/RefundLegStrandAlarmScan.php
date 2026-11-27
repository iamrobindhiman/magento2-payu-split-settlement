<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Cron;

use RKD\PayuSplit\Split\Model\ResourceModel\StaleRefundLegs;
use RKD\PayuSplit\Split\Model\SplitConfig;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * Gate item #13 — refund-leg strand alarm. The refund reconciler polls + strand-alarms only the
 * pollable legs (QUEUED/PENDING WITH a PayU request id). Two kinds fall outside it and nothing else
 * age-alarms them: a HELD freeze marker (R-6/R-7/negative/unfundable/F7 — parked for manual
 * reconciliation) and a PENDING/QUEUED leg with NO request id (the FULL_UNWIND shipping obligation +
 * the absent-parent commission leg — not pollable). Each blocks its segment's release (INV-19) and,
 * via parent-last, the parent commission, with only a ONE-SHOT alarm at creation. This daily scan
 * CRITICAL-logs any such leg past the alert threshold so it is never a SILENT strand (the RBI
 * "never strand unalarmed" rule). Logging-only — moves no money, changes no state — and UNGATED
 * (refunds run pre-release, so their strand alarm must run while release_enabled is OFF).
 */
class RefundLegStrandAlarmScan
{
    /**
     * @param StaleRefundLegs $reader
     * @param SplitConfig $config
     * @param DateTime $dateTime
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly StaleRefundLegs $reader,
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
        try {
            $alertDays = $this->config->getAlertDays();
            $cutoff = (new \DateTimeImmutable($this->dateTime->gmtDate()))
                ->sub(new \DateInterval('P' . $alertDays . 'D'))
                ->format('Y-m-d H:i:s');

            foreach ($this->reader->getStaleUnhandledLegs($cutoff) as $row) {
                $this->logger->critical(sprintf(
                    'RKD_PayuSplit: refund leg %d (order %d, segment %d, seller %d) stuck %s [%s] since %s — '
                    . 'past the %d-day alert threshold. The reconciler never resolves this leg (a HELD '
                    . 'freeze marker, or a null-request-id obligation) and it blocks the segment\'s '
                    . 'release; it needs manual reconciliation.',
                    (int) $row['refund_id'],
                    (int) $row['order_id'],
                    (int) $row['segment_id'],
                    (int) $row['seller_id'],
                    (string) $row['state'],
                    $row['reason'] !== null && $row['reason'] !== '' ? (string) $row['reason'] : '(no reason)',
                    (string) $row['created_at'],
                    $alertDays
                ));
            }
        } catch (\Throwable $t) {
            // Logging-only scan — never let a bad timestamp or read failure escalate; log and move on.
            $this->logger->error(
                'RKD_PayuSplit: RefundLegStrandAlarmScan failed — ' . $t->getMessage(),
                ['exception' => $t]
            );
        }
    }
}
