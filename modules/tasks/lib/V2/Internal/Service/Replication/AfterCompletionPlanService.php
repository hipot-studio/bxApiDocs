<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Replication;

use Bitrix\Tasks\Replication\Agent\AfterCompletionAgent;

class AfterCompletionPlanService
{
	/**
	 * @param int[] $templateIds
	 * @return array<int, int> [templateId => next execution timestamp]
	 */
	public function getNextExecutionTimes(array $templateIds): array
	{
		return AfterCompletionAgent::getNextExecTsByTemplateIds($templateIds);
	}
}
