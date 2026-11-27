<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Cron;

use RKD\PayuSplit\Split\Model\ResourceModel\StaleReturnSegments;
use RKD\PayuSplit\Split\Model\SplitConfig;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * #9 — return-strand alarm. SegmentEligibility blocks release while any item is RETURN_OPEN, but
 * the returns-refund that would clear it is deferred to Fab_Returns v2 — so an approved return can
 * leave a PayU-split segment blocked indefinitely. This daily scan CRITICAL-logs any segment stuck
 * RETURN_OPEN past the alert threshold, so a blocked release is never a SILENT strand (the RBI
 * "never strand unalarmed" rule). Logging-only — it moves no money and changes no state.
 */
class ReturnStrandAlarmScan
{
    /**
     * @param StaleReturnSegments $reader
     * @param SplitConfig $config
     * @param DateTime $dateTime
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly StaleReturnSegments $reader,
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

            foreach ($this->reader->getStaleOpenReturnSegments($cutoff) as $row) {
                $this->logger->critical(sprintf(
                    'RKD_PayuSplit: segment %d (order %d, seller %d) stuck RETURN_OPEN since %s — past the '
                    . '%d-day alert threshold. An approved/in-progress return whose refund has not '
                    . 'executed (returns-refund deferred to Fab_Returns v2) is blocking this segment\'s '
                    . 'release. Investigate / reconcile.',
                    (int) $row['segment_id'],
                    (int) $row['order_id'],
                    (int) $row['seller_id'],
                    (string) $row['opened_at'],
                    $alertDays
                ));
            }
        } catch (\Throwable $t) {
            // Logging-only scan — never let a bad timestamp or read failure escalate; log and move on.
            $this->logger->error(
                'RKD_PayuSplit: ReturnStrandAlarmScan failed — ' . $t->getMessage(),
                ['exception' => $t]
            );
        }
    }
}
