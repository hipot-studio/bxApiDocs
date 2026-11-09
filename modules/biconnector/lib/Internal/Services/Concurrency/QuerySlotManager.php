<?php

namespace Bitrix\BIConnector\Internal\Services\Concurrency;

use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;

final class QuerySlotManager
{
	public const SLOT_NAME_PREFIX = 'biconnector_data_';

	public const DEFAULT_SLOT_COUNT = 3;

	public const MAX_SLOT_COUNT = 10;

	/**
	 * Pool of the requests to external servers. They do not load the database of the portal, but each answer is
	 * spooled into a temporary file of up to a gigabyte, so their number is bounded apart from the portal slots.
	 */
	public const EXTERNAL_SLOT_NAME_PREFIX = 'biconnector_external_data_';

	public const EXTERNAL_SLOT_COUNT = 3;

	private Connection $connection;

	private int $slotCount;

	private string $slotNamePrefix;

	private ?int $acquiredSlot = null;

	public function __construct(Connection $connection, int $slotCount, string $slotNamePrefix = self::SLOT_NAME_PREFIX)
	{
		$this->connection = $connection;
		$this->slotCount = $slotCount > 0 ? $slotCount : 1;
		$this->slotNamePrefix = $slotNamePrefix;
	}

	public function acquire(): ?int
	{
		if ($this->acquiredSlot !== null)
		{
			return $this->acquiredSlot;
		}

		$startSlot = random_int(0, $this->slotCount - 1);

		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			for ($i = 0; $i < $this->slotCount; $i++)
			{
				$slot = ($startSlot + $i) % $this->slotCount;
				if ($this->connection->lock($this->slotNamePrefix . $slot, 0))
				{
					$this->acquiredSlot = $slot;

					return $slot;
				}
			}
		}
		finally
		{
			$pool->useMasterOnly(false);
		}

		return null;
	}

	public function release(): void
	{
		if ($this->acquiredSlot === null)
		{
			return;
		}

		$slot = $this->acquiredSlot;
		$this->acquiredSlot = null;

		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			$this->connection->unlock($this->slotNamePrefix . $slot);
		}
		finally
		{
			$pool->useMasterOnly(false);
		}
	}

	public function getAcquiredSlot(): ?int
	{
		return $this->acquiredSlot;
	}
}
