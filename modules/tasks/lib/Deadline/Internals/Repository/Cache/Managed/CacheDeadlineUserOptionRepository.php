<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Deadline\Internals\Repository\Cache\Managed;

use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
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

	private DeadlineUserOptionRepositoryInterface $repository;

	public function __construct(DeadlineUserOptionRepository $repository)
	{
		$this->repository = $repository;
	}

	/**
	 * @param int $userId
	 *
	 * @return DeadlineUserOption
	 *
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getByUserId(int $userId): DeadlineUserOption
	{
		$cacheManager = Application::getInstance()->getManagedCache();
		$cacheId = $this->getCacheId($userId);

		if ($cacheManager->read(self::CACHE_TTL, $cacheId, self::CACHE_DIR))
		{
			$deadlineUserOptionData = $cacheManager->get($cacheId);
			if (!is_array($deadlineUserOptionData))
			{
				return new DeadlineUserOption($userId);
			}

			return DeadlineUserOption::mapFromArray($deadlineUserOptionData);
		}

		$deadlineUserOption = $this->repository->getByUserId($userId);

		$cacheManager->set($cacheId, $deadlineUserOption->toArray());

		return $deadlineUserOption;
	}

	public function save(DeadlineUserOption $entity): void
	{
		$cacheManager = Application::getInstance()->getManagedCache();
		$cacheId = $this->getCacheId($entity->userId);

		$cacheManager->clean($cacheId, self::CACHE_DIR);

		$this->repository->save($entity);

		$cacheManager->set($cacheId, $entity->toArray());
	}

	private function getCacheId(int $userId): string
	{
		return self::CACHE_ID_PREFIX . $userId;
	}
}
