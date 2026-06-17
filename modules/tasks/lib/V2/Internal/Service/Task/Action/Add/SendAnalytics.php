<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Add;

use Bitrix\Tasks\Helper;
use Bitrix\Tasks\V2\Internal\Entity\Analytics;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Trait\ConfigTrait;

class SendAnalytics
{
	use ConfigTrait;

	public function __invoke(array $fields, bool $status = true): void
	{
		$analyticsData = $this->config->getAnalyticsData();

		if ($analyticsData?->section === null)
		{
			return;
		}

		$taskId = (int)($fields['ID'] ?? 0);

		$event = $this->getEvent($fields);

		$parameters = $this->getParameters($taskId, $analyticsData->parameters);

		Helper\Analytics::getInstance($this->config->getUserId())->onTaskCreate(
			category: $analyticsData->category?->value ?: Analytics\Category::TaskOperations->value,
			event: $event->value,
			section: $analyticsData->section->value,
			element: $analyticsData->element?->value,
			subSection: $analyticsData->subSection?->value,
			status: $status,
			params: $parameters,
		);
	}

	private function getEvent(array $fields): Analytics\Event
	{
		$parentId = (int)($fields['PARENT_ID'] ?? null);
		$templateId = (int)($fields['TEMPLATE_ID'] ?? null);

		if ($templateId > 0)
		{
			return Analytics\Event::PatternTaskCreate;
		}

		if ($parentId > 0)
		{
			return Analytics\Event::SubTaskAdd;
		}

		return Analytics\Event::TaskCreate;
	}

	private function getParameters(int $taskId, ?array $additionalParameters): array
	{
		$defaultParameters = [
			'p1' => 'taskId_' . $taskId,
		];

		return array_merge($defaultParameters, $additionalParameters ?? []);
	}
}
