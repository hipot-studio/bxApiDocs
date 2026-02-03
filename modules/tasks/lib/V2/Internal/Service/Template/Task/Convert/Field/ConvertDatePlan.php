<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Task\Convert\Field;

use Bitrix\Tasks\V2\Internal\Entity\Template;
use Bitrix\Tasks\V2\Internal\Service\Template\Task\Convert\TaskBuilder;
use Bitrix\Tasks\V2\Internal\Service\Template\Task\Convert\Trait\ConfigTrait;
use Bitrix\Tasks\V2\Internal\Service\Time\Trait\DateCalculationTrait;

class ConvertDatePlan implements ConvertFieldInterface
{
	use ConfigTrait;
	use DateCalculationTrait;

	public function __invoke(Template $template, TaskBuilder $taskBuilder): void
	{
		$userId = $this->config->userId;
		$matchesWorkTime = $template->matchesWorkTime ?? false;

		if($template->startDatePlanAfter)
		{
			$startDatePlan = $this->calculateClosestDate(
				$template->startDatePlanAfter,
				$matchesWorkTime,
				$userId,
				false,
			);

			if ($startDatePlan)
			{
				$taskBuilder->set('startPlanTs', $startDatePlan->getTimestamp());
			}
		}

		if($template->endDatePlanAfter)
		{
			$endDatePlan = $this->calculateClosestDate(
				$template->endDatePlanAfter,
				$matchesWorkTime,
				$userId,
				false,
			);

			if ($endDatePlan)
			{
				$taskBuilder->set('endPlanTs', $endDatePlan->getTimestamp());
			}
		}

		$taskBuilder->set('allowsChangeDatePlan', (bool)$template->allowsChangeDeadline);
	}
}
