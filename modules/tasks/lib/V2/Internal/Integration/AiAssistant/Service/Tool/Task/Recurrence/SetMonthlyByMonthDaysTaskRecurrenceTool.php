<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\Recurrence;

use Bitrix\Tasks\V2\Internal\Entity\Template\MonthlyType;
use Bitrix\Tasks\V2\Internal\Entity\Template\Period;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\MakeTaskRecurringDto;

class SetMonthlyByMonthDaysTaskRecurrenceTool extends BaseRecurrenceTool
{
	public const ACTION_NAME = 'set_monthly_by_month_days_task_recurrence';

	public function getDescription(): string
	{
		return
			'Sets up a monthly recurrence for a task based on a specific day of the month. '
			. 'Use this to make a task repeat on the same date every month or every few months. '
			. 'Provide exactly one of templateId or taskId. When taskId identifies a generated instance, '
			. 'this updates the recurrence rule of the source template instead of creating a new '
			. 'series from the instance. Saving the rule activates recurrence, including for an inactive template.'
		;
	}

	protected function buildDto(array $args, int $userId): MakeTaskRecurringDto
	{
		return MakeTaskRecurringDto::fromArray([
			...$args,
			'period' => Period::Monthly->value,
			'monthlyType' => MonthlyType::MonthDay->value,
			'userId' => $userId,
		]);
	}
}
