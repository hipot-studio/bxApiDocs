<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Repository;

interface WorkgroupCounterRepositoryInterface
{
	/**
	 * @return int[]
	 */
	public function getGroupIdsByTasksCounter(int $userId, string $counterType): array;

	/**
	 * @return int[]
	 */
	public function getGroupIdsByCommonCounter(int $userId, string $counterType): array;
}
