<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Agent;

use Bitrix\Main\Update\Stepper;
use Bitrix\Socialnetwork\Collab\Internals\CollabOptionTable;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;

/**
 * Повторно запускает линкеры чатов для групп, которые уже были сконвертированы
 * (имеют completed_from_* в опциях конвертации в b_sonet_collab_option).
 *
 * Запускать после релиза правок AbstractGroupChatLinker:
 *   RelinkConvertedGroupsAgent::bind(0);
 */
class RelinkConvertedGroupsAgent extends Stepper
{
	private const LIMIT = 20;

	protected static $moduleId = 'socialnetwork';

	public function execute(array &$option): bool
	{
		$lastOptionId = (int)($option['lastOptionId'] ?? 0);

		$groups = $this->getConvertedGroups($lastOptionId, self::LIMIT);
		if ($groups === [])
		{
			return self::FINISH_EXECUTION;
		}

		foreach ($groups as $group)
		{
			$groupId = $group['groupId'];
			$groupChatId = $group['groupChatId'];

			if ($groupId <= 0 || $groupChatId <= 0)
			{
				continue;
			}

			GroupTaskChatLinker::bind(
				delay: 1,
				withArguments: [$groupId, $groupChatId],
			);

			GroupEventsChatLinker::bind(
				delay: 1,
				withArguments: [$groupId, $groupChatId],
			);
		}

		$option['lastOptionId'] = min(array_column($groups, 'optionId'));

		return self::CONTINUE_EXECUTION;
	}

	/**
	 * @return array<int, array{optionId: int, groupId: int, groupChatId: int}>
	 */
	private function getConvertedGroups(int $lastOptionId, int $limit): array
	{
		$query = CollabOptionTable::query()
			->setSelect(['ID', 'COLLAB_ID'])
			->where('NAME', 'CONVERT_STATUS')
			->whereLike('VALUE', 'completed_from%')
			->setOrder(['ID' => 'DESC'])
			->setLimit($limit)
		;

		if ($lastOptionId > 0)
		{
			$query->where('ID', '<', $lastOptionId);
		}

		$optionRows = $query->exec()->fetchAll();
		if ($optionRows === [])
		{
			return [];
		}

		$chatResolver = Container::getInstance()->getProjectChatResolver();
		$groupIds = array_map(static fn(array $row): int => (int)($row['COLLAB_ID'] ?? 0), $optionRows);
		$chatMap = $chatResolver->getByProjectIds($groupIds);

		$groups = [];
		foreach ($optionRows as $optionRow)
		{
			$groupId = (int)($optionRow['COLLAB_ID'] ?? 0);

			$groups[] = [
				'optionId' => (int)($optionRow['ID'] ?? 0),
				'groupId' => $groupId,
				'groupChatId' => (int)($chatMap[$groupId] ?? 0),
			];
		}

		return $groups;
	}
}
