<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Service;

use Bitrix\Tasks\Access\ActionDictionary;

class MemberAccessService
{
	public function __construct(
		private readonly TaskAccessService $taskAccessService,
	)
	{
	}

	public function canAddAccomplices(int $userId, int $taskId, array $params = []): bool
	{
		return $this->taskAccessService->can(
			$userId,
			ActionDictionary::ACTION_TASK_CHANGE_ACCOMPLICES,
			$taskId,
			$params,
		);
	}

	public function canAddAuditors(int $userId, int $taskId, array $params = []): bool
	{
		return $this->taskAccessService->can(
			$userId,
			ActionDictionary::ACTION_TASK_ADD_AUDITORS,
			$taskId,
			$params,
		);
	}

	public function canDeleteAccomplices(int $userId, int $taskId, array $params = []): bool
	{
		return $this->taskAccessService->can(
			$userId,
			ActionDictionary::ACTION_TASK_CHANGE_ACCOMPLICES,
			$taskId,
			$params,
		);
	}

	public function canDeleteAuditors(int $userId, int $taskId, array $params = []): bool
	{
		return $this->taskAccessService->can(
			$userId,
			ActionDictionary::ACTION_TASK_ADD_AUDITORS,
			$taskId,
			$params,
		);
	}
}
