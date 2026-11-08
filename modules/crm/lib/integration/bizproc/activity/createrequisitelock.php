<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\BizProc\Activity;

use Bitrix\Main\Application;

final class CreateRequisiteLock
{
	private const LOCK_PREFIX = 'crm_create_requisite_';
	private const LOCK_TIMEOUT = 10;

	private ?string $name = null;
	private bool $locked = false;
	private bool $masterOnly = false;

	public function acquire(int $ownerTypeId, int $ownerId, int $presetId): bool
	{
		$this->release();

		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		$this->masterOnly = true;

		$this->name = $this->buildName($ownerTypeId, $ownerId, $presetId);
		$this->locked = (bool)Application::getConnection()->lock($this->name, self::LOCK_TIMEOUT);

		if (!$this->locked)
		{
			$this->release();
		}

		return $this->locked;
	}

	public function release(): void
	{
		try
		{
			if ($this->locked && $this->name !== null)
			{
				Application::getConnection()->unlock($this->name);
			}
		}
		finally
		{
			$this->locked = false;
			$this->name = null;

			if ($this->masterOnly)
			{
				Application::getInstance()->getConnectionPool()->useMasterOnly(false);
				$this->masterOnly = false;
			}
		}
	}

	private function buildName(int $ownerTypeId, int $ownerId, int $presetId): string
	{
		return self::LOCK_PREFIX . md5($ownerTypeId . ':' . $ownerId . ':' . $presetId);
	}
}
