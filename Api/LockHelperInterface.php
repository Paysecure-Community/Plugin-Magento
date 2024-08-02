<?php

namespace PaySecure\Payments\Api;

interface LockHelperInterface
{
    /**
     * Generic lock name
     */
    const LOCK_NAME = 'paysecure_payments';

    /**
     * @param string $name
     * @param int $timeout
     * @return bool
     */
    public function acquireLock(string $name, int $timeout = 15): bool;

    /**
     * @param string $lockName
     * @return mixed
     */
    public function releaseLock(string $lockName);
}
