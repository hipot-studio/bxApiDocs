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
			. 'Use this to make a task repeat on the same date every month or every few months.'
		;
	}

	protected function buildDto(array $args): MakeTaskRecurringDto
	{
		return MakeTaskRecurringDto::fromArray([
			...$args,
			'period' => Period::Monthly->value,
			'monthlyType' => MonthlyType::MonthDay->value,
		]);
	}
}
