<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\Recurrence;

use Bitrix\Tasks\V2\Internal\Entity\Template\Period;
use Bitrix\Tasks\V2\Internal\Entity\Template\YearlyType;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\MakeTaskRecurringDto;

class SetYearlyByMonthDaysTaskRecurrenceTool extends BaseRecurrenceTool
{
	public const ACTION_NAME = 'set_yearly_by_month_days_task_recurrence';

	public function getDescription(): string
	{
		return
			'Sets up a yearly (annual) recurrence for a task on a specific calendar date. '
			. 'Use this to make a task repeat on the same day and month each year, such as "every April 25th". '
			. 'Provide exactly one of templateId or taskId. When taskId identifies a generated instance, '
			. 'this updates the recurrence rule of the source template instead of creating a new '
			. 'series from the instance. Saving the rule activates recurrence, including for an inactive template.'
		;
	}

	protected function buildDto(array $args, int $userId): MakeTaskRecurringDto
	{
		return MakeTaskRecurringDto::fromArray([
			...$args,
			'period' => Period::Yearly->value,
			'yearlyType' => YearlyType::MonthDay->value,
			'userId' => $userId,
		]);
	}
}
