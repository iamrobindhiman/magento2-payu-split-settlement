<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\_stubs;

use Magento\Backend\Model\Auth\Session as BackendSession;

/**
 * Test double for the backend auth session. Extends the real class so production typehints
 * accept it, skips the parent constructor (no DI needed), and declares getUser() as a real
 * method — the real class exposes it via __call, which PHPUnit mocks cannot configure.
 */
class BackendSessionStub extends BackendSession
{
    public ?object $user = null;

    // phpcs:ignore Magento2.Functions.StaticFunction
    public function __construct()
    {
        // intentionally skip parent::__construct — no real session storage needed
    }

    public function getUser()
    {
        return $this->user;
    }
}
