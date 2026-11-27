<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Cron;

use RKD\PayuSplit\Split\Service\RefundCandidateLoader;
use RKD\PayuSplit\Split\Service\RefundExecutor;
use Psr\Log\LoggerInterface;

/**
 * The second of the three settlement scans (matrix §5): issue customer refunds for
 * cancelled vendor segments on already-split orders. It loads every refundable candidate
 * (DONE split + a cancelled vendor portion + no active refund leg) and hands each to the
 * idempotent RefundExecutor.
 *
 * Each segment runs in its own try/catch so one failing refund (gateway hiccup, lock)
 * never stalls the batch — the executor's write-ahead + ledger-presence idempotency make
 * the next run a safe retry.
 */
class RefundExecutorScan
{
    /**
     * @param RefundCandidateLoader $loader
     * @param RefundExecutor $executor
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly RefundCandidateLoader $loader,
        private readonly RefundExecutor $executor,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return void
     */
    public function execute(): void
    {
        foreach ($this->loader->loadCandidates() as $candidate) {
            try {
                $this->executor->execute($candidate->segment, $candidate->reason);
            } catch (\Throwable $t) {
                $this->logger->error(
                    sprintf(
                        'RKD_PayuSplit: refund scan error for segment %d (will retry) — %s',
                        (int) $candidate->segment->getId(),
                        $t->getMessage()
                    ),
                    ['exception' => $t]
                );
            }
        }
    }
}
