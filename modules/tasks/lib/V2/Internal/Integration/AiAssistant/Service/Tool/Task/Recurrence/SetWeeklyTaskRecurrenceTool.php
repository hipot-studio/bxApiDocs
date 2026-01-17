<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\Recurrence;

use Bitrix\Tasks\V2\Internal\Entity\Template\Period;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\MakeTaskRecurringDto;

class SetWeeklyTaskRecurrenceTool extends BaseRecurrenceTool
{
	public const ACTION_NAME = 'set_weekly_task_recurrence';

	public function getDescription(): string
	{
		return
			'Sets up a weekly recurrence for a task. '
			. 'Allows selecting specific days of the week (e.g., Monday, Wednesday) '
			. 'and setting a weekly interval (e.g., every 2 weeks).'
		;
	}

	protected function buildDto(array $args): MakeTaskRecurringDto
	{
		return MakeTaskRecurringDto::fromArray([...$args, 'period' => Period::Weekly->value]);
	}
}
