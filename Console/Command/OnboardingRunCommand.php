<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Console\Command;

use RKD\PayuSplit\Split\Cron\OnboardingScan;
use RKD\PayuSplit\Split\Model\OnboardingConfig;
use RKD\PayuSplit\Split\Service\OnboardingProcessor;
use RKD\PayuSplit\Split\Service\OnboardingQueue;
use RKD\PayuSplit\Split\Service\VendorOnboardingDataReader;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento fab:payu:onboarding:run [--seller=ID [--queue]]
 *
 * Without --seller it runs the same scan cron runs. With --seller it takes that one vendor
 * as far as it can go now, ignoring any backoff wait — the way to test without waiting for
 * cron. --queue first marks the vendor for onboarding, which is also how an already-approved
 * vendor is brought in without re-approval (R-10, one at a time, deliberately).
 *
 * Obeys the same master switch as everything else: with onboarding disabled it does nothing.
 */
class OnboardingRunCommand extends Command
{
    private const OPTION_SELLER = 'seller';
    private const OPTION_QUEUE = 'queue';

    /**
     * @param OnboardingScan $scan
     * @param OnboardingProcessor $processor
     * @param OnboardingQueue $queue
     * @param OnboardingConfig $config
     * @param VendorOnboardingDataReader $reader
     * @param State $appState
     */
    public function __construct(
        private readonly OnboardingScan $scan,
        private readonly OnboardingProcessor $processor,
        private readonly OnboardingQueue $queue,
        private readonly OnboardingConfig $config,
        private readonly VendorOnboardingDataReader $reader,
        private readonly State $appState
    ) {
        parent::__construct();
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('fab:payu:onboarding:run')
            ->setDescription('Run PayU vendor onboarding now: the whole scan, or one seller.')
            ->addOption(self::OPTION_SELLER, null, InputOption::VALUE_REQUIRED, 'Only this seller id')
            ->addOption(
                self::OPTION_QUEUE,
                null,
                InputOption::VALUE_NONE,
                'Queue the seller first (brings an already-approved vendor in without re-approval)'
            );
        parent::configure();
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_CRONTAB);
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            // Area already set — fine.
        }

        if (!$this->config->isEnabled()) {
            $output->writeln('<error>PayU onboarding is disabled (rkd_payu/onboarding/enabled = 0). Nothing done.</error>');
            return Command::FAILURE;
        }

        $sellerId = (int)$input->getOption(self::OPTION_SELLER);
        if ($sellerId <= 0) {
            $outcomes = $this->scan->execute();
            if ($outcomes === []) {
                $output->writeln('No vendors due.');
            }
            foreach ($outcomes as $id => $outcome) {
                $output->writeln(sprintf('seller %d: %s', $id, $outcome));
            }
            return Command::SUCCESS;
        }

        if ($input->getOption(self::OPTION_QUEUE)) {
            if (!$this->reader->isApprovedSeller($sellerId)) {
                $output->writeln(sprintf('<error>Seller %d is not an approved vendor. Not queued.</error>', $sellerId));
                return Command::FAILURE;
            }
            $output->writeln(sprintf('seller %d: %s', $sellerId, $this->queue->enqueue($sellerId)));
        }
        $output->writeln(sprintf('seller %d: %s', $sellerId, $this->processor->process($sellerId, true)));
        return Command::SUCCESS;
    }
}
