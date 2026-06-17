<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Delete;

use Bitrix\Tasks\V2\Internal\Service\Task\Action\Delete\Trait\ConfigTrait;
use Bitrix\Tasks\Helper\Analytics;

class SendAnalytics
{
	use ConfigTrait;

	public function __invoke(array $fullTaskData, bool $status = true): void
	{
		$analyticsData = $this->config->getAnalyticsData();

		if ($analyticsData?->section === null)
		{
			return;
		}

		$taskId = (int)($fullTaskData['ID'] ?? 0);

		$parameters = $this->getParameters($taskId, $analyticsData->parameters);

		Analytics::getInstance($this->config->getUserId())->onTaskDelete(
			section: $analyticsData->section->value,
			element: $analyticsData->element?->value,
			subSection: $analyticsData->subSection?->value,
			status: $status,
			params: $parameters,
		);
	}

	private function getParameters(int $taskId, ?array $additionalParameters): array
	{
		$defaultParameters = [
			'p1' => 'taskId_' . $taskId,
		];

		return array_merge($defaultParameters, $additionalParameters ?? []);
	}
}
