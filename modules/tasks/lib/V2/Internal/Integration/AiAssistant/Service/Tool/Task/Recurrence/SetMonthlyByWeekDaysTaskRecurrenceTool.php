<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\Recurrence;

use Bitrix\Tasks\V2\Internal\Entity\Template\MonthlyType;
use Bitrix\Tasks\V2\Internal\Entity\Template\Period;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\MakeTaskRecurringDto;

class SetMonthlyByWeekDaysTaskRecurrenceTool extends BaseRecurrenceTool
{
	public const ACTION_NAME = 'set_monthly_by_week_days_task_recurrence';

	public function getDescription(): string
	{
		return
			'Sets up a monthly recurrence for a task based on the day of the week and its order within the month. '
			. 'Use for complex patterns like "the second Friday of every month" or "the last Monday every 3 months".'
		;
	}

	protected function buildDto(array $args): MakeTaskRecurringDto
	{
		return MakeTaskRecurringDto::fromArray([
			...$args,
			'period' => Period::Monthly->value,
			'monthlyType' => MonthlyType::WeekDay->value,
		]);
	}
}
