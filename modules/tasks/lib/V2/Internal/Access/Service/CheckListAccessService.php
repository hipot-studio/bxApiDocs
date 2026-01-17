<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Service;

use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\V2\Internal\Entity\CheckList\Type;
use Bitrix\Tasks\V2\Internal\Repository\CheckListEntityRepositoryInterface;

class CheckListAccessService
{
	public function __construct(
		private readonly TaskAccessService $taskAccessService,
		private readonly CheckListEntityRepositoryInterface $checkListEntityRepository,
	)
	{
	}

	public function canAdd(int $userId, int $taskId, array $params = []): bool
	{
		return $this->taskAccessService->can($userId, ActionDictionary::ACTION_CHECKLIST_ADD, $taskId, $params);
	}

	public function canUpdate(int $userId, int $checkListId, array $params = []): bool
	{
		$taskId = $this->checkListEntityRepository->getIdByCheckListId($checkListId, Type::Task);

		return $this->taskAccessService->can($userId, ActionDictionary::ACTION_CHECKLIST_EDIT, $taskId, $params);
	}

	public function canDelete(int $userId, int $checkListId): bool
	{
		return $this->canUpdate($userId, $checkListId);
	}
}
