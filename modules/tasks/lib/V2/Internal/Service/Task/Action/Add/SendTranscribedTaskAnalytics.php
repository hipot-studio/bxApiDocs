<?php

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Add;

use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\Helper\Analytics;
use Bitrix\Tasks\V2\Internal\Service\AnalyticsService;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Trait\ConfigTrait;

class SendTranscribedTaskAnalytics
{
	use ConfigTrait;

	public function __invoke(Entity\Task $task, bool $status = true): void
	{
		if (
			!$task->scenarios?->contains(Entity\Task\Scenario::Voice)
			&& !$task->scenarios?->contains(Entity\Task\Scenario::Video)
		)
		{
			return;
		}

		$params = [
			'p1' => 'taskId_' . $task->id,
			'p2' => Container::getInstance()->get(AnalyticsService::class)->getUserTypeParameter($this->config->getUserId()),
			'p3' => 'viewersCount_' . $task->auditors->count(),
			'p5' => 'coexecutorsCount_' . $task->accomplices->count(),
		];

		if ($task->group?->type === 'collab' && $task->group?->id)
		{
			$params['p4'] = 'collabId_' . $task->group->id;
		}

		Analytics::getInstance($this->config->getUserId())->onTaskCreate(
			category: Analytics::TASK_CATEGORY,
			event: Analytics::EVENT['task_create'],
			section: Analytics::SECTION['chat'],
			element: Analytics::ELEMENT['create_button'],
			subSection: Analytics::SUB_SECTION['ai'],
			status: $status,
			params: $params,
		);
	}
}
