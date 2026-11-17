<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Prepare;

use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity\GroupTypes;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Trait\ConfigTrait;

class PrepareStage implements PrepareFieldInterface
{
	use ConfigTrait;

	public function __invoke(array $fields): array
	{
		$groupId = (int)($fields['GROUP_ID'] ?? 0);
		if ($groupId <= 0)
		{
			return $fields;
		}

		if ((int)($fields['STAGE_ID'] ?? 0) > 0)
		{
			return $fields;
		}

		// scrum task keeps the sprint stage in STAGE_ID, not the group kanban column
		if ($this->isScrumGroup($groupId))
		{
			return $fields;
		}

		$systemStageId = Container::getInstance()->getStageRepository()->getSystemStageIdByGroupId($groupId);
		if ($systemStageId === null)
		{
			return $fields;
		}

		$fields['STAGE_ID'] = $systemStageId;

		return $fields;
	}

	private function isScrumGroup(int $groupId): bool
	{
		return Container::getInstance()->getGroupRepository()->getType($groupId) === GroupTypes::Scrum->value;
	}
}
