<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Deadline\Internals\Repository\Cache\Managed;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Data\ManagedCache;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Tasks\Deadline\Entity\DeadlineUserOption;
use Bitrix\Tasks\Deadline\Internals\Repository\DeadlineUserOptionRepositoryInterface;
use Bitrix\Tasks\Deadline\Internals\Repository\Orm\DeadlineUserOptionRepository;

class CacheDeadlineUserOptionRepository implements DeadlineUserOptionRepositoryInterface
{
	private const CACHE_TTL = 86400 * 30;
	private const CACHE_DIR = '/tasks/deadline_user_option/';
	private const CACHE_ID_PREFIX = 'tasks_deadline_user_option_';

	public function __construct(
		private readonly DeadlineUserOptionRepository $deadlineUserOptionRepository,
		private readonly ManagedCache $managedCache,
	)
	{
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getByUserId(int $userId): DeadlineUserOption
	{
		$cacheId = $this->getCacheId($userId);

		if ($this->managedCache->read(self::CACHE_TTL, $cacheId, self::CACHE_DIR))
		{
			$deadlineUserOptionData = $this->managedCache->get($cacheId);
			if (!is_array($deadlineUserOptionData))
			{
				return new DeadlineUserOption($userId);
			}

			return DeadlineUserOption::mapFromArray($deadlineUserOptionData);
		}

		$deadlineUserOption = $this->deadlineUserOptionRepository->getByUserId($userId);

		$this->managedCache->set($cacheId, $this->mapToCacheData($deadlineUserOption));

		return $deadlineUserOption;
	}

	public function save(DeadlineUserOption $deadlineUserOption): void
	{
		$cacheId = $this->getCacheId($deadlineUserOption->userId);

		$this->managedCache->clean($cacheId, self::CACHE_DIR);

		$this->deadlineUserOptionRepository->save($deadlineUserOption);

		$this->managedCache->set($cacheId, $this->mapToCacheData($deadlineUserOption));
	}

	public function saveDefaultDeadlineValue(
		int $userId,
		int $defaultDeadlineInSeconds,
		bool $isExactDeadlineTime,
	): DeadlineUserOption
	{
		$deadlineUserOption = $this->deadlineUserOptionRepository->saveDefaultDeadlineValue(
			$userId,
			$defaultDeadlineInSeconds,
			$isExactDeadlineTime,
		);

		$this->managedCache->clean(
			$this->getCacheId($userId),
			self::CACHE_DIR,
		);

		return $deadlineUserOption;
	}

	private function mapToCacheData(DeadlineUserOption $deadlineUserOption): array
	{
		return [
			'id' => $deadlineUserOption->id,
			'userId' => $deadlineUserOption->userId,
			'defaultDeadlineInSeconds' => $deadlineUserOption->defaultDeadlineInSeconds,
			'isExactDeadlineTime' => $deadlineUserOption->isExactDeadlineTime,
			'skipNotificationPeriod' => $deadlineUserOption->skipNotificationPeriod->value,
			'skipNotificationStartDate' => $deadlineUserOption->skipNotificationStartDate,
			'canChangeDeadline' => $deadlineUserOption->canChangeDeadline,
			'maxDeadlineChangeDate' => $deadlineUserOption->maxDeadlineChangeDate,
			'maxDeadlineChanges' => $deadlineUserOption->maxDeadlineChanges,
			'requireDeadlineChangeReason' => $deadlineUserOption->requireDeadlineChangeReason,
		];
	}

	private function getCacheId(int $userId): string
	{
		return self::CACHE_ID_PREFIX . $userId;
	}
}
