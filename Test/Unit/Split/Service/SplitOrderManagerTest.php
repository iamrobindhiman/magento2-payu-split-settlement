<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Api\SplitOrderRepositoryInterface;
use RKD\PayuSplit\Split\Exception\SplitCalculationException;
use RKD\PayuSplit\Split\Exception\SubmerchantNotMappedException;
use RKD\PayuSplit\Split\Model\Money;
use RKD\PayuSplit\Split\Model\OrderItemShare;
use RKD\PayuSplit\Split\Model\OrderSplitPlan;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegment as SplitSegmentResource;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegmentItem as SplitSegmentItemResource;
use RKD\PayuSplit\Split\Model\SplitOrder;
use RKD\PayuSplit\Split\Model\SplitOrderFactory;
use RKD\PayuSplit\Split\Model\SplitResult;
use RKD\PayuSplit\Split\Model\SplitSegment;
use RKD\PayuSplit\Split\Model\SplitSegmentFactory;
use RKD\PayuSplit\Split\Model\SplitSegmentItem;
use RKD\PayuSplit\Split\Model\SplitSegmentItemFactory;
use RKD\PayuSplit\Split\Service\OrderSplitDataProvider;
use RKD\PayuSplit\Split\Service\SplitOrderManager;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * recordPlan branches: idempotent no-op, SKIPPED on an unmapped vendor, FAILED on
 * a calculation error, and the success path that lays down a PENDING header + one
 * segment per vendor + the parent commission segment + per-item rows. The happy
 * path's persisted values are additionally proven end-to-end by the local order test.
 */
class SplitOrderManagerTest extends TestCase
{
    private ObjectManager $om;
    private $dataProvider;
    private $repository;
    private $orderFactory;
    private $segmentFactory;
    private $segmentResource;
    private $itemFactory;
    private $itemResource;
    private $dateTime;
    private $logger;

    protected function setUp(): void
    {
        $this->om = new ObjectManager($this);
        $this->dataProvider = $this->createMock(OrderSplitDataProvider::class);
        $this->repository = $this->createMock(SplitOrderRepositoryInterface::class);
        $this->orderFactory = $this->createMock(SplitOrderFactory::class);
        $this->segmentFactory = $this->createMock(SplitSegmentFactory::class);
        $this->segmentResource = $this->createMock(SplitSegmentResource::class);
        $this->itemFactory = $this->createMock(SplitSegmentItemFactory::class);
        $this->itemResource = $this->createMock(SplitSegmentItemResource::class);
        $this->dateTime = $this->createMock(DateTime::class);
        $this->dateTime->method('gmtDate')->willReturn('2026-06-11 00:00:00');
        $this->logger = $this->createMock(LoggerInterface::class);

        // Factories return fresh real models so setData/getData/getId behave.
        $this->orderFactory->method('create')->willReturnCallback(fn() => $this->om->getObject(SplitOrder::class));
        $this->segmentFactory->method('create')->willReturnCallback(fn() => $this->om->getObject(SplitSegment::class));
        $this->itemFactory->method('create')->willReturnCallback(fn() => $this->om->getObject(SplitSegmentItem::class));
    }

    private function manager(): SplitOrderManager
    {
        return new SplitOrderManager(
            $this->dataProvider,
            $this->repository,
            $this->orderFactory,
            $this->segmentFactory,
            $this->segmentResource,
            $this->itemFactory,
            $this->itemResource,
            $this->dateTime,
            $this->logger
        );
    }

    private function order(int $id): OrderInterface
    {
        $payment = $this->createMock(OrderPaymentInterface::class);
        $payment->method('getAdditionalInformation')->willReturn(['payu_mihpayid' => '100000000000']);
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn($id);
        $order->method('getIncrementId')->willReturn('00000000' . $id);
        $order->method('getGrandTotal')->willReturn(600.00);
        $order->method('getPayment')->willReturn($payment);
        return $order;
    }

    public function testIdempotentNoOpWhenAlreadyRecorded(): void
    {
        $existing = $this->om->getObject(SplitOrder::class);
        $this->repository->method('getByOrderId')->willReturn($existing);
        $this->dataProvider->expects($this->never())->method('buildForOrder');
        $this->repository->expects($this->never())->method('save');

        $this->manager()->recordPlan($this->order(60));
    }

    public function testUnmappedVendorRecordsSkippedHeader(): void
    {
        $this->repository->method('getByOrderId')->willReturn(null);
        $this->dataProvider->method('buildForOrder')
            ->willThrowException(new SubmerchantNotMappedException('seller 11 not mapped'));

        $saved = null;
        $this->repository->method('save')->willReturnCallback(function ($h) use (&$saved) {
            $saved = $h;
            return $h;
        });
        $this->segmentResource->expects($this->never())->method('save');

        $this->manager()->recordPlan($this->order(61));

        $this->assertInstanceOf(SplitOrder::class, $saved);
        $this->assertSame(SplitOrder::STATE_SKIPPED, $saved->getSplitState());
        $this->assertStringContainsString('not mapped', (string) $saved->getData('last_error'));
    }

    public function testCalculationErrorRecordsFailedHeader(): void
    {
        $this->repository->method('getByOrderId')->willReturn(null);
        $this->dataProvider->method('buildForOrder')
            ->willThrowException(new SplitCalculationException('no positive grand total'));

        $saved = null;
        $this->repository->method('save')->willReturnCallback(function ($h) use (&$saved) {
            $saved = $h;
            return $h;
        });

        $this->manager()->recordPlan($this->order(62));

        $this->assertSame(SplitOrder::STATE_FAILED, $saved->getSplitState());
    }

    public function testSuccessRecordsPendingHeaderSegmentsAndItems(): void
    {
        $this->repository->method('getByOrderId')->willReturn(null);

        $result = new SplitResult(
            [11 => Money::fromRupees('500.00')],
            Money::fromRupees('100.00'),
            Money::fromRupees('600.00')
        );
        $items = [11 => [
            new OrderItemShare(11, 101, 2143, 1, Money::fromRupees('300.00'), Money::fromRupees('30.00'), Money::zero(), false),
            new OrderItemShare(11, 102, 2144, 2, Money::fromRupees('200.00'), Money::fromRupees('20.00'), Money::zero(), false),
        ]];
        $plan = new OrderSplitPlan(60, '000000060', $result, [11 => 'KEYA'], $items);
        $this->dataProvider->method('buildForOrder')->willReturn($plan);

        $savedHeader = null;
        $this->repository->method('save')->willReturnCallback(function ($h) use (&$savedHeader) {
            $savedHeader = $h;
            return $h;
        });

        // 1 vendor segment + 1 parent commission segment; 2 item rows.
        $this->segmentResource->expects($this->exactly(2))->method('save');
        $this->itemResource->expects($this->exactly(2))->method('save');

        $this->manager()->recordPlan($this->order(60));

        $this->assertSame(SplitOrder::STATE_PENDING, $savedHeader->getSplitState());
        $this->assertSame(60, $savedHeader->getOrderId());
        $this->assertSame(60000, (int) $savedHeader->getData('txn_amount_paise'));
        $this->assertSame('100000000000', (string) $savedHeader->getData('mihpayid'));
    }
}
