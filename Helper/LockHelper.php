<?php

namespace PaySecure\Payments\Helper;

use Magento\Framework\App\ResourceConnection;
use PaySecure\Payments\Api\LockHelperInterface;

class LockHelper implements LockHelperInterface
{
    /**
     * @var ResourceConnection $resourceConnection
     */
    private $resourceConnection;

    /**
     * @var
     */
    private $currentDatabase;

    /**
     * LockHelper constructor.
     *
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(ResourceConnection $resourceConnection)
    {
        $this->resourceConnection = $resourceConnection->getConnection();
    }

    /**
     * @param string $name
     * @param int $timeout
     * @return bool
     */
    public function acquireLock(string $name, int $timeout = 15): bool
    {
        // phpcs:ignore Magento2.SQL.RawQuery
        $statement = sprintf("SELECT GET_LOCK(%s, %u)", $this->prepareLockName($name), $timeout);
        $result = $this->resourceConnection->fetchOne($statement);

        return $result === '1';
    }

    /**
     * @param string $lockName
     */
    public function releaseLock(string $lockName)
    {
        $lockName = $lockName ? $lockName : self::LOCK_NAME;
        // phpcs:ignore Magento2.SQL.RawQuery
        $statement = "SELECT RELEASE_LOCK({$this->prepareLockName($lockName)})";
        $this->resourceConnection->query($statement);
    }

    /**
     * @param string $lockName
     * @return string
     */
    private function prepareLockName(string $lockName): string
    {
        $lockName = $lockName ? $lockName : self::LOCK_NAME;
        $lockName = "{$this->getCurrentDatabase()}.$lockName";

        if (strlen($lockName) >= 64) {
            $lockName = hash('sha256', $lockName);
        }

        return $this->resourceConnection->quote($lockName);
    }

    /**
     * @return string
     */
    private function getCurrentDatabase()
    {
        if (null === $this->currentDatabase) {
            $this->currentDatabase = $this->resourceConnection->fetchOne('SELECT DATABASE()');
        }

        return $this->currentDatabase;
    }
}
