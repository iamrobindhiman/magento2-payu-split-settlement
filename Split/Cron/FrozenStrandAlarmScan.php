<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Cron;

use RKD\PayuSplit\Split\Model\ResourceModel\StaleFrozenSegments;
use RKD\PayuSplit\Split\Model\SplitConfig;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * Gate item #11 — FROZEN-hold strand alarm. A TYPE_FROZEN hold (release readback mismatch INV-18,
 * the O-13 parent child-key gap, the out-of-band credit-memo freeze on the release #5 / refund F5
 * paths, or the refund reconciler's post-FAILURE freeze F6) halts auto-processing for a segment and,
 * via parent-last (INV-14), can block the order's parent commission indefinitely. There is no
 * hold-clear path today and only a ONE-SHOT CRITICAL is raised at placement, so a freeze that is
 * never reconciled would go silent. This daily scan CRITICAL-logs any non-terminal segment whose
 * FROZEN hold has sat past the alert threshold, so a frozen strand is never SILENT (the RBI
 * "never strand unalarmed" rule). Logging-only — it moves no money and changes no state, and it is
 * intentionally UNGATED (a freeze can occur on the pre-release refund path, so its alarm must run
 * even while release_enabled is OFF).
 */
class FrozenStrandAlarmScan
{
    /**
     * @param StaleFrozenSegments $reader
     * @param SplitConfig $config
     * @param DateTime $dateTime
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly StaleFrozenSegments $reader,
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

            foreach ($this->reader->getStaleFrozenSegments($cutoff) as $row) {
                $this->logger->critical(sprintf(
                    'RKD_PayuSplit: segment %d (order %d, seller %d) has been FROZEN (%s) since %s — past '
                    . 'the %d-day alert threshold. A frozen segment is never auto-released/refunded and '
                    . 'blocks the order\'s parent commission (INV-14); it needs manual reconciliation '
                    . '(fix the books / clear the hold). Note: %s',
                    (int) $row['segment_id'],
                    (int) $row['order_id'],
                    (int) $row['seller_id'],
                    (string) $row['hold_type'],
                    (string) $row['frozen_at'],
                    $alertDays,
                    $row['note'] !== null && $row['note'] !== '' ? (string) $row['note'] : '(none)'
                ));
            }
        } catch (\Throwable $t) {
            // Logging-only scan — never let a bad timestamp or read failure escalate; log and move on.
            $this->logger->error(
                'RKD_PayuSplit: FrozenStrandAlarmScan failed — ' . $t->getMessage(),
                ['exception' => $t]
            );
        }
    }
}
