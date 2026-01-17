<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\Recurrence;

use Bitrix\Tasks\V2\Internal\Entity\Template\Period;
use Bitrix\Tasks\V2\Internal\Entity\Template\YearlyType;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\MakeTaskRecurringDto;

class SetYearlyByWeekDaysTaskRecurrenceTool extends BaseRecurrenceTool
{
	public const ACTION_NAME = 'set_yearly_by_week_days_task_recurrence';

	public function getDescription(): string
	{
		return
			'Sets up a yearly recurrence for a task based on the day of the week '
			. 'and its order within a specific month. '
			. 'Use for complex annual patterns like "the fourth Thursday of November" every year.'
		;
	}

	protected function buildDto(array $args): MakeTaskRecurringDto
	{
		return MakeTaskRecurringDto::fromArray([
			...$args,
			'period' => Period::Yearly->value,
			'yearlyType' => YearlyType::WeekDay->value,
		]);
	}
}
