<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AiAgent\Scenario\ProjectPulse;

use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Socialnetwork\UserToGroupTable;
use Bitrix\Tasks\Internals\TaskTable;

final class ProjectEligibilityChecker
{
	private const MIN_TASKS = 20;
	private const MIN_MEMBERS = 10;
	private const ACTIVITY_PERIOD_DAYS = 14;

	public function isEligible(int $workgroupId): bool
	{
		if (!Loader::includeModule('tasks'))
		{
			return false;
		}

		if (!Loader::includeModule('socialnetwork'))
		{
			return false;
		}

		return $this->hasEnoughMembers($workgroupId)
			&& $this->hasEnoughTasks($workgroupId)
			&& $this->hasRecentActivity($workgroupId)
		;
	}

	private function hasEnoughMembers(int $workgroupId): bool
	{
		$count = UserToGroupTable::getCount([
			'=GROUP_ID' => $workgroupId,
			'@ROLE' => UserToGroupTable::getRolesMember(),
		]);

		return $count > self::MIN_MEMBERS;
	}

	private function hasEnoughTasks(int $workgroupId): bool
	{
		$count = TaskTable::getCount([
			'=GROUP_ID' => $workgroupId,
			'=ZOMBIE' => 'N',
		]);

		return $count > self::MIN_TASKS;
	}

	private function hasRecentActivity(int $workgroupId): bool
	{
		$since = (new DateTime())->add('-' . self::ACTIVITY_PERIOD_DAYS . ' days');

		$row = TaskTable::query()
			->setSelect(['ID'])
			->where('GROUP_ID', $workgroupId)
			->where('ZOMBIE', 'N')
			->where('CREATED_DATE', '>=', $since)
			->setLimit(1)
			->fetch()
		;

		return $row !== false;
	}
}
