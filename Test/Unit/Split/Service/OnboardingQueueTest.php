<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Api\SubmerchantRepositoryInterface;
use RKD\PayuSplit\Split\Model\Submerchant;
use RKD\PayuSplit\Split\Model\SubmerchantFactory;
use RKD\PayuSplit\Split\Service\OnboardingQueue;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Approval, retry and e-sign — the state changes people make. Approval must be safe to run
 * any number of times (T-07) and must never touch a vendor already under way.
 */
class OnboardingQueueTest extends TestCase
{
    private SubmerchantRepositoryInterface&MockObject $repository;
    private SubmerchantFactory&MockObject $factory;
    private OnboardingQueue $queue;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(SubmerchantRepositoryInterface::class);
        $this->factory = $this->createMock(SubmerchantFactory::class);
        $this->factory->method('create')->willReturnCallback(fn (): Submerchant => $this->submerchant([]));
        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtDate')->willReturn('2026-09-24 10:00:00');
        $this->queue = new OnboardingQueue($this->repository, $this->factory, $dateTime);
    }

    public function testApprovalWritesAQueuedRowThatIsNotActive(): void
    {
        $this->repository->method('getBySellerId')->willReturn(null);
        $saved = null;
        $this->repository->expects(self::once())->method('save')
            ->willReturnCallback(function (Submerchant $row) use (&$saved): Submerchant {
                $saved = $row;
                return $row;
            });

        self::assertSame(OnboardingQueue::RESULT_QUEUED, $this->queue->enqueue(42));
        self::assertSame(42, $saved->getSellerId());
        self::assertSame(Submerchant::STATE_QUEUED, $saved->getOnboardingState());
        self::assertFalse($saved->isActive());
    }

    public function testApprovingTwiceChangesNothingTheSecondTime(): void
    {
        foreach ([
            Submerchant::STATE_QUEUED,
            Submerchant::STATE_CREATED,
            Submerchant::STATE_BANK_ATTACHED,
            Submerchant::STATE_AWAITING_ESIGN,
            Submerchant::STATE_ACTIVE,
        ] as $state) {
            $repository = $this->createMock(SubmerchantRepositoryInterface::class);
            $repository->method('getBySellerId')->willReturn(
                $this->submerchant(['onboarding_state' => $state, 'payu_uuid' => 'u-1'])
            );
            $repository->expects(self::never())->method('save');
            $queue = new OnboardingQueue($repository, $this->factory, $this->createMock(DateTime::class));

            self::assertSame(OnboardingQueue::RESULT_ALREADY, $queue->enqueue(42), $state);
        }
    }

    public function testAConcurrentApprovalThatWonTheInsertIsLeftStanding(): void
    {
        $this->repository->method('getBySellerId')->willReturn(null);
        $this->repository->method('save')->willThrowException(new AlreadyExistsException(__('dup')));

        self::assertSame(OnboardingQueue::RESULT_ALREADY, $this->queue->enqueue(42));
    }

    public function testAHandTypedLegacyMappingIsNeverQueued(): void
    {
        // Seller 18 locally: a key typed in by hand, no uuid. Queueing would create a second account.
        $this->repository->method('getBySellerId')->willReturn($this->submerchant([
            'onboarding_state' => Submerchant::STATE_NOT_STARTED,
            'payu_merchant_key' => 'CHLD01',
        ]));
        $this->repository->expects(self::never())->method('save');

        self::assertSame(OnboardingQueue::RESULT_LEGACY, $this->queue->enqueue(18));
    }

    public function testReApprovingAFailedVendorQueuesItAgainWithACleanSlate(): void
    {
        $row = $this->submerchant([
            'onboarding_state' => Submerchant::STATE_FAILED,
            'payu_uuid' => 'u-1',
            'attempts' => 6,
            'last_error' => 'boom',
            'error_class' => Submerchant::ERROR_RETRYABLE,
        ]);
        $this->repository->method('getBySellerId')->willReturn($row);

        self::assertSame(OnboardingQueue::RESULT_REQUEUED, $this->queue->enqueue(42));
        self::assertSame(Submerchant::STATE_QUEUED, $row->getOnboardingState());
        self::assertSame(0, $row->getAttempts());
        self::assertNull($row->getLastError());
        self::assertNull($row->getErrorClass());
        self::assertSame('u-1', $row->getPayuUuid(), 'the uuid is what stops a retry creating a second child');
    }

    public function testOnlyAFailedVendorCanBeRetried(): void
    {
        $this->repository->method('getBySellerId')->willReturn(
            $this->submerchant(['onboarding_state' => Submerchant::STATE_AWAITING_ESIGN])
        );
        $this->expectException(LocalizedException::class);
        $this->queue->retry(42);
    }

    /**
     * Wave-4 correctness F3: PayU says the parent has no sub-merchants, so an ambiguous create of
     * this vendor's made nothing; the mark goes, and the retry can create.
     */
    public function testARetryOnAnEmptyParentClearsTheCreateMark(): void
    {
        $row = $this->submerchant(['onboarding_state' => Submerchant::STATE_FAILED, 'payu_email' => 'v@x.in']);
        $this->repository->method('getBySellerId')->willReturn($row);

        $this->queue->retry(42, true);

        self::assertNull($row->getPayuEmail());
        self::assertSame(Submerchant::STATE_QUEUED, $row->getOnboardingState());
    }

    public function testAnOrdinaryRetryKeepsTheCreateMark(): void
    {
        $row = $this->submerchant(['onboarding_state' => Submerchant::STATE_FAILED, 'payu_email' => 'v@x.in']);
        $this->repository->method('getBySellerId')->willReturn($row);

        $this->queue->retry(42);

        self::assertSame('v@x.in', $row->getPayuEmail());
    }

    public function testMarkingTheEsignRecordsWhoAndWhen(): void
    {
        $row = $this->submerchant(['onboarding_state' => Submerchant::STATE_AWAITING_ESIGN]);
        $this->repository->method('getBySellerId')->willReturn($row);

        $this->queue->markEsignSent(42, 3);

        self::assertTrue($row->isEsignSent());
        self::assertSame('2026-09-24 10:00:00', $row->getData('esign_sent_at'));
        self::assertSame(3, $row->getData('esign_sent_by'));
    }

    public function testTheEsignCannotBeMarkedTwiceOrOutOfTurn(): void
    {
        $row = $this->submerchant([
            'onboarding_state' => Submerchant::STATE_AWAITING_ESIGN,
            'esign_sent_at' => '2026-09-23 09:00:00',
        ]);
        $this->repository->method('getBySellerId')->willReturn($row);
        $this->expectException(LocalizedException::class);
        $this->queue->markEsignSent(42, 3);
    }

    public function testTheEsignCannotBeMarkedForAVendorNotWaitingForIt(): void
    {
        $this->repository->method('getBySellerId')->willReturn(
            $this->submerchant(['onboarding_state' => Submerchant::STATE_CREATED])
        );
        $this->expectException(LocalizedException::class);
        $this->queue->markEsignSent(42, 3);
    }

    /**
     * @param array<string, mixed> $data
     * @return Submerchant
     */
    private function submerchant(array $data): Submerchant
    {
        $model = $this->getMockBuilder(Submerchant::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $model->setData($data);
        return $model;
    }

    public function testAHandTypedLegacyRowCannotBeRetriedIntoASecondAccount(): void
    {
        $this->repository->method('getBySellerId')->willReturn($this->submerchant([
            'onboarding_state' => Submerchant::STATE_FAILED,
            'payu_merchant_key' => 'K-typed',
        ]));
        $this->repository->expects(self::never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->queue->retry(18);
    }

    public function testRequeueNeverClearsALeaseARunStillHolds(): void
    {
        $row = $this->submerchant([
            'onboarding_state' => Submerchant::STATE_FAILED,
            'locked_until' => '2026-09-24 10:10:00',
        ]);
        $this->repository->method('getBySellerId')->willReturn($row);

        $this->queue->retry(42);

        self::assertSame('2026-09-24 10:10:00', $row->getData('locked_until'));
    }
}
