<?php

declare(strict_types=1);

namespace Bitrix\TasksMobile\Controller;

use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Tasks\Scrum\Controllers\DoD as DodController;
use Bitrix\TasksMobile\Cache\DeadlineCountersResetTrait;
use Bitrix\TasksMobile\Provider\ChecklistProvider;

class Dod extends DodController
{
	use DeadlineCountersResetTrait;

	/** the reads of the scrum controller behind this one, plus the tree of the mobile screen */
	protected function getReadActionNames(): array
	{
		return [
			'getDodTree',
			'getDodInfo',
			'getSettings',
			'getChecklist',
			'getList',
			'isNecessary',
		];
	}

	public function configureActions(): array
	{
		return [
			'getDodTree' => [
				'+prefilters' => [
					new CloseSession(),
				],
			],
		];
	}

	public function getDodTreeAction(int $groupId, int $taskId, int $typeId): ?array
	{
		$checklistItems = $this->getListItems($groupId, $taskId, $typeId);
		if ($checklistItems === null)
		{
			return null;
		}

		// DoD has no V2 entity, so the V2 flat mapping is manual
		return (new ChecklistProvider())->buildV2FlatFromPlainItems($checklistItems);
	}
}
