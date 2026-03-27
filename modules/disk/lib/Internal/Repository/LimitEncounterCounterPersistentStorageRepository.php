<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Repository;

use Bitrix\Disk\Internal\Enum\LimitEncounterType;
use Bitrix\Disk\Internal\Repository\Interface\LimitEncounterCounterRepositoryInterface;
use Bitrix\Disk\Internal\Service\ItemsCountResult;
use Bitrix\Main\Application;
use Bitrix\Main\Data\Storage\PersistentStorageInterface;
use Bitrix\Main\Error;
use DateInterval;
use DateTimeImmutable;
use Throwable;

class LimitEncounterCounterPersistentStorageRepository implements LimitEncounterCounterRepositoryInterface
{
	private const STORAGE_KEY_PREFIX = 'disk_limit_encounter_count_';
	private const LOCK_TIMEOUT = 5;

	public function __construct(
		private readonly PersistentStorageInterface $storage,
	)
	{
	}

	public function incrementUnlessMax(LimitEncounterType $type, int $max): ItemsCountResult
	{
		$result = new ItemsCountResult();

		$key = $this->getKey($type);

		$connection = Application::getInstance()->getConnection();
		if (!$connection->lock($key, self::LOCK_TIMEOUT))
		{
			$result->addError(new Error('Could not acquire a lock for the key.'));

			return $result;
		}

		try
		{
			$count = $this->get($type) ?? 0;

			if ($count < $max)
			{
				$nextCount = $count + 1;

				$ttl = $this->getTtl();
				$this->storage->set($key, $nextCount, $ttl);

				$result->setCount($nextCount);
			}
		}
		catch (Throwable $e)
		{
			$result->addError(new Error('Could not increment limit encounter count: ' . $e->getMessage()));
		}
		finally
		{
			$connection->unlock($key);
		}

		return $result;
	}

	public function get(LimitEncounterType $type): ?int
	{
		$key = $this->getKey($type);

		$count = $this->storage->get($key);
		if ($count === null)
		{
			return null;
		}

		return (int)$count;
	}

	private function getKey(LimitEncounterType $type): string
	{
		return self::STORAGE_KEY_PREFIX . $type->value;
	}

	private function getTtl(): DateInterval
	{
		//todo: take time zone into account?
		$now = new DateTimeImmutable('now');
		$tomorrowMidnight = $now->modify('tomorrow')->setTime(0, 0, 0);

		return $now->diff($tomorrowMidnight);
	}
}
