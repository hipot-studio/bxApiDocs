<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Tasks\Service;

use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\V2\Internal\Repository\WorkgroupCounterRepositoryInterface;
use Bitrix\Tasks\Internals\Project\Filter;

class ProjectCounterFilterService
{
	private const ALLOWED_COUNTERS = [
		'EXPIRED',
		'NEW_COMMENTS',
		'PROJECT_EXPIRED',
		'PROJECT_NEW_COMMENTS',
	];

	public function __construct(
		private readonly WorkgroupCounterRepositoryInterface $workgroupCounterRepository,
	)
	{
	}

	/**
	 * @return int[]
	 */
	public function getGroupIdsByCounter(int $userId, string $counterType): array
	{
		if (
			$userId <= 0
			|| $counterType === ''
			|| !in_array($counterType, self::ALLOWED_COUNTERS, true)
			|| !Loader::includeModule('tasks')
			|| !class_exists(Filter::class)
		)
		{
			return [];
		}

		return $this->workgroupCounterRepository->getGroupIdsByTasksCounter($userId, $counterType);
	}
}
