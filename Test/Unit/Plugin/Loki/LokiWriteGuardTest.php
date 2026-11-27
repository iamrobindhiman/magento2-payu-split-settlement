<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Plugin\Loki;

use RKD\PayuSplit\Plugin\Loki\LokiWriteGuard;
use RKD\PayuSplit\Split\Model\ResourceModel\Submerchant\Collection as SubmerchantCollection;
use Loki\AdminComponents\Form\Action\DeleteAction;
use Loki\AdminComponents\Form\Action\DuplicateAction;
use Loki\AdminComponents\Form\Action\SaveAction;
use Loki\AdminComponents\Grid\Action\InlineEditAction;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Loki's generic write paths against RKD_PayuSplit components (wave-3 I-F1 / M1 / S-F3).
 */
class LokiWriteGuardTest extends TestCase
{
    public function testInlineEditNeverReachesAPayuRow(): void
    {
        self::assertFalse($this->runs(InlineEditAction::class, $this->payuRepository(), ['inline_edit' => [
            'identifier' => 5, 'property' => 'payu_merchant_key', 'value' => 'someone-elses-key',
        ]], true));
    }

    public function testSearchAndPagingOnAPayuGridStillWork(): void
    {
        self::assertTrue($this->runs(InlineEditAction::class, $this->payuRepository(), ['search' => 'x'], false));
    }

    public function testDuplicateIsRefusedEvenForAManager(): void
    {
        self::assertFalse($this->runs(DuplicateAction::class, $this->payuRepository(), ['actions' => ['duplicate']], true));
    }

    public function testSaveAndDeleteNeedTheManagePermission(): void
    {
        foreach ([SaveAction::class, DeleteAction::class] as $action) {
            self::assertFalse($this->runs($action, $this->payuRepository(), ['item' => ['entity_id' => 1]], false), $action . ' without ACL');
            self::assertTrue($this->runs($action, $this->payuRepository(), ['item' => ['entity_id' => 1]], true), $action . ' with ACL');
        }
    }

    /**
     * Wave-4 correctness F1: the Shiprocket pickup grid's rows are marketplace_userdata, where the
     * bank details onboarding sends to PayU live.
     */
    public function testInlineEditAndDuplicateAreOffOnEveryLokiComponent(): void
    {
        self::assertFalse($this->runs(InlineEditAction::class, $this->otherRepository(), ['inline_edit' => [
            'identifier' => 3, 'property' => 'bank_account_number', 'value' => '000111222333',
        ]], true));
        self::assertFalse($this->runs(DuplicateAction::class, $this->otherRepository(), [], true));
    }

    public function testOtherModulesFormSavesAreUntouched(): void
    {
        self::assertTrue($this->runs(SaveAction::class, $this->otherRepository(), ['item' => ['anything' => 1]], false));
    }

    /**
     * Wave-4 security F1: every posted key becomes a setter, and id_field_name re-points the UPDATE.
     */
    public function testAPayuFormSaveMayOnlyCarryTheFormsOwnColumns(): void
    {
        self::assertFalse($this->runs(SaveAction::class, $this->payuRepository(), ['item' => [
            'entity_id' => 1, 'id_field_name' => 'x_target', 'x_target' => 5, 'payu_merchant_key' => 'K',
        ]], true));
        self::assertTrue($this->runs(SaveAction::class, $this->payuRepository(), ['item' => [
            'entity_id' => 1, 'seller_id' => 18, 'payu_merchant_key' => 'K', 'payu_mid' => '',
            'kyc_status' => 'verified', 'is_active' => '1', 'payu_uuid' => '', 'created_at' => '',
        ]], true));
    }

    private function otherRepository(): object
    {
        return new class {
            public function getProvider(): object
            {
                return new \Magento\Framework\DataObject();
            }
        };
    }

    /**
     * @param class-string $actionClass
     * @param array<string, mixed> $value
     */
    private function runs(string $actionClass, object $repository, array $value, bool $allowed): bool
    {
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturn($allowed);
        $guard = new LokiWriteGuard(
            $authorization,
            $this->createMock(ManagerInterface::class),
            $this->createMock(LoggerInterface::class)
        );
        $ran = false;
        $guard->aroundExecute(
            $this->createMock($actionClass),
            function () use (&$ran): void {
                $ran = true;
            },
            $repository,
            $value
        );
        return $ran;
    }

    private function payuRepository(): object
    {
        $provider = $this->createMock(SubmerchantCollection::class);
        return new class ($provider) {
            public function __construct(private readonly object $provider)
            {
            }

            public function getProvider(): object
            {
                return $this->provider;
            }
        };
    }
}
