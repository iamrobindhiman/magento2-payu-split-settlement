<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Model\AuditLog;
use RKD\PayuSplit\Split\Model\AuditLogFactory;
use RKD\PayuSplit\Split\Model\ResourceModel\AuditLog as AuditLogResource;
use RKD\PayuSplit\Split\Service\SettlementAuditLogger;
use RKD\PayuSplit\Test\Unit\Split\_stubs\BackendSessionStub;
use Magento\User\Model\User;
use PHPUnit\Framework\TestCase;

/**
 * Writes one immutable audit row per break-glass action, stamping the acting admin from the
 * backend session. The row is the accountability record for every manual money-operation
 * recovery (retry split, re-arm refund) — so the acting user, the entity, and the before/after
 * state must all be captured.
 */
class SettlementAuditLoggerTest extends TestCase
{
    public function testRecordsAnAuditRowWithTheActingAdminAndStateTransition(): void
    {
        $captured = [];
        $row = $this->createMock(AuditLog::class);
        $row->method('addData')->willReturnCallback(function (array $data) use (&$captured, $row) {
            $captured = $data;
            return $row;
        });

        $factory = $this->createMock(AuditLogFactory::class);
        $factory->method('create')->willReturn($row);

        $resource = $this->createMock(AuditLogResource::class);
        $resource->expects($this->once())->method('save')->with($row);

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(7);
        $user->method('getUserName')->willReturn('ops_admin');
        $session = new BackendSessionStub();
        $session->user = $user;

        (new SettlementAuditLogger($factory, $resource, $session))->record(
            AuditLog::ACTION_RETRY_SPLIT,
            AuditLog::ENTITY_SPLIT,
            42,
            1043,
            'FAILED',
            'PENDING',
            'manual retry after salt rotation'
        );

        $this->assertSame(7, $captured['admin_user_id']);
        $this->assertSame('ops_admin', $captured['admin_user_name']);
        $this->assertSame('retry_split', $captured['action']);
        $this->assertSame('split', $captured['entity_type']);
        $this->assertSame(42, $captured['entity_ref_id']);
        $this->assertSame(1043, $captured['order_id']);
        $this->assertSame('FAILED', $captured['before_state']);
        $this->assertSame('PENDING', $captured['after_state']);
        $this->assertSame('manual retry after salt rotation', $captured['note']);
    }

    public function testToleratesNoAuthenticatedUser(): void
    {
        $captured = [];
        $row = $this->createMock(AuditLog::class);
        $row->method('addData')->willReturnCallback(function (array $data) use (&$captured, $row) {
            $captured = $data;
            return $row;
        });
        $factory = $this->createMock(AuditLogFactory::class);
        $factory->method('create')->willReturn($row);
        $resource = $this->createMock(AuditLogResource::class);
        $resource->expects($this->once())->method('save')->with($row);
        $session = new BackendSessionStub(); // user stays null

        (new SettlementAuditLogger($factory, $resource, $session))->record(
            AuditLog::ACTION_REARM_REFUND,
            AuditLog::ENTITY_SEGMENT,
            8,
            null,
            'HELD',
            'REFUND_PENDING',
            null
        );

        $this->assertNull($captured['admin_user_id']);
        $this->assertNull($captured['admin_user_name']);
        $this->assertSame('rearm_refund', $captured['action']);
    }
}
