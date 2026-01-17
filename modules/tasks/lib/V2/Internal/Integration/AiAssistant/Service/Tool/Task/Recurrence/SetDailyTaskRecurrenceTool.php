<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\Recurrence;

use Bitrix\Tasks\V2\Internal\Entity\Template\Period;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\MakeTaskRecurringDto;

class SetDailyTaskRecurrenceTool extends BaseRecurrenceTool
{
	public const ACTION_NAME = 'set_daily_task_recurrence';

	public function getDescription(): string
	{
		return
			'Sets up a daily recurrence for a task. '
			. 'Allows specifying an interval and can restrict the recurrence to workdays only.'
		;
	}

	protected function buildDto(array $args): MakeTaskRecurringDto
	{
		return MakeTaskRecurringDto::fromArray([...$args, 'period' => Period::Daily->value]);
	}
}
