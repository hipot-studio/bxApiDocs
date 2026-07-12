<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service;

use Bitrix\Tasks\V2\Internal\Entity\Group;
use Bitrix\Tasks\V2\Internal\Entity\GroupTypes;
use Bitrix\Tasks\V2\Internal\Integration\Socialnetwork\Service\ProjectOptionFacade;

class GroupDisplayTypeResolver
{
	public function __construct(
		private readonly ProjectOptionFacade $projectOptionFacade,
	)
	{
	}

	public function resolveForGroup(?Group $group): ?string
	{
		if ($group === null || $group->type !== GroupTypes::Collab->value)
		{
			return null;
		}

		$groupId = (int)$group->getId();
		if ($groupId <= 0)
		{
			return null;
		}

		if (!$this->projectOptionFacade->isCollabConverted($groupId))
		{
			return null;
		}

		return GroupTypes::Project->value;
	}

	/**
	 * @param array<array{GROUP_ID?: int|string, GROUP_TYPE?: string}> $tasks
	 * @return array<int, string>
	 */
	public function resolveForRawTasks(array $tasks): array
	{
		$collabIdMap = [];
		foreach ($tasks as $task)
		{
			$groupId = (int)($task['GROUP_ID'] ?? 0);
			$type = (string)($task['GROUP_TYPE'] ?? '');

			if ($groupId > 0 && $type === GroupTypes::Collab->value)
			{
				$collabIdMap[$groupId] = true;
			}
		}

		if ($collabIdMap === [])
		{
			return [];
		}

		$collabIds = array_keys($collabIdMap);

		$convertedMap = $this->projectOptionFacade->isCollabConvertedBatch($collabIds);

		$groupDisplayTypes = [];
		foreach ($convertedMap as $collabId => $isConverted)
		{
			if ($isConverted)
			{
				$groupDisplayTypes[$collabId] = GroupTypes::Project->value;
			}
		}

		return $groupDisplayTypes;
	}
}
