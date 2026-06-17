<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Provider\WorkflowTemplate;

use Bitrix\Bizproc\Internal\Repository\DayPlanBot\DayPlanBotRepository;
use Bitrix\Bizproc\Internal\Service\DayPlanBot\DayPlanBotSyncService;
use Bitrix\Main\DI\ServiceLocator;

class DayPlanBotProvider
{
	/**
	 * Returns the bot user ID for the day plan AI agent
	 * where the given user participates as a tracked user.
	 *
	 * Looks for the most recently activated template
	 * originating from bitrix_ai_day_planner.
	 */
	public function getBotIdByUserId(int $userId): ?int
	{
		if ($userId <= 0)
		{
			return null;
		}

		$entityIds = $this->resolveUserEntityIds($userId);
		if (empty($entityIds))
		{
			return null;
		}

		$repository = ServiceLocator::getInstance()->get(DayPlanBotRepository::class);

		$row = $repository->findUserDataByEntityIds($entityIds);
		if ($row === null)
		{
			return null;
		}

		$botId = (int)$row['VALUE'];
		if ($botId > 0)
		{
			return $botId;
		}

		$templateId = (int)($row['TEMPLATE_ID'] ?? 0);
		if ($templateId <= 0)
		{
			return null;
		}

		$botId = ServiceLocator::getInstance()
			->get(DayPlanBotSyncService::class)
			->resolveBotId($templateId)
		;
		if ($botId === null)
		{
			return null;
		}

		$repository->updateUserDataValue((int)$row['ID'], (string)$botId);

		return $botId;
	}

	private function resolveUserEntityIds(int $userId): array
	{
		$entityIds = ['user_' . $userId];

		$userGroups = \CBPHelper::getUserExtendedGroups($userId);
		foreach ($userGroups as $group)
		{
			if (preg_match('/^group_hr(r?)(\d+)$/i', $group, $match))
			{
				$recursive = !empty($match[1]);
				$entityIds[] = '[HR' . ($recursive ? 'R' : '') . $match[2] . ']';
			}
		}

		return array_unique($entityIds);
	}
}
