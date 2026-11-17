<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Add;

use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity\GroupTypes;
use Bitrix\Tasks\V2\Internal\Repository\StageRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Trait\ConfigTrait;

/**
 * Keeps STAGE_ID consistent with the final GROUP_ID. {@see Prepare\PrepareStage} runs before
 * OnBeforeTaskAdd, and a handler receiving the fields by reference may move the task to another
 * group; a stage of the previous group would leave the task out of every kanban column.
 */
class ReconcileStage
{
	use ConfigTrait;

	public function __invoke(array $fields): array
	{
		$stageId = (int)($fields['STAGE_ID'] ?? 0);
		$groupId = (int)($fields['GROUP_ID'] ?? 0);

		if ($groupId <= 0)
		{
			// a stage belongs to a kanban of the group and means nothing without one
			if ($stageId > 0)
			{
				$fields['STAGE_ID'] = 0;
			}

			return $fields;
		}

		$stageRepository = Container::getInstance()->getStageRepository();

		// scrum task keeps the stage of the active sprint in STAGE_ID, not the group kanban column
		if ($this->isScrumGroup($groupId))
		{
			return $this->reconcileScrumStage($fields, $stageId, $groupId, $stageRepository);
		}

		if ($stageId > 0 && $stageRepository->isGroupStage($stageId, $groupId))
		{
			return $fields;
		}

		$systemStageId = $stageRepository->getSystemStageIdByGroupId($groupId);
		if ($systemStageId !== null)
		{
			$fields['STAGE_ID'] = $systemStageId;

			return $fields;
		}

		// the group has no stages yet: a foreign stage is still worse than none
		if ($stageId > 0)
		{
			$fields['STAGE_ID'] = 0;
		}

		return $fields;
	}

	private function reconcileScrumStage(
		array $fields,
		int $stageId,
		int $groupId,
		StageRepositoryInterface $stageRepository,
	): array
	{
		if ($stageId <= 0)
		{
			return $fields;
		}

		if (!$stageRepository->isActiveSprintStage($stageId, $groupId))
		{
			$fields['STAGE_ID'] = 0;
		}

		return $fields;
	}

	private function isScrumGroup(int $groupId): bool
	{
		return Container::getInstance()->getGroupRepository()->getType($groupId) === GroupTypes::Scrum->value;
	}
}
