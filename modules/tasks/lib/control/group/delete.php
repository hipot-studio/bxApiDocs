<?php

namespace Bitrix\Tasks\Control\Group;

use Bitrix\Tasks\Control\Task;
use Bitrix\Tasks\Integration\SocialNetwork\GroupProvider;
use Bitrix\Tasks\V2\Internal\Entity\Analytics;
use Bitrix\Tasks\V2\Internal\Entity\Analytics\AnalyticsData;

class Delete
{
	public function runBatch(int $userId, array $taskIds, int $groupId = 0): array
	{
		$result = [];
		$control =
			(new Task($userId))
				->useConsistency()
				->withAnalyticsData($this->getAnalyticsData($groupId))
		;

		foreach ($taskIds as $id)
		{
			$result[] = [
				$control->delete($id),
				'taskId' => $id,
			];
		}

		return $result;
	}

	private function getAnalyticsData(int $groupId): AnalyticsData
	{
		$section = Analytics\Section::Tasks;

		if ($groupId)
		{
			$isCollab = GroupProvider::isCollab($groupId);

			$section =
				$isCollab
					? Analytics\Section::Collab
					: Analytics\Section::Project
			;
		}

		return new AnalyticsData(
			section: $section,
			subSection: Analytics\SubSection::List,
			element: Analytics\Element::MultiAction,
		);
	}
}
