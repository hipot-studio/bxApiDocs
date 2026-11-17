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
			. 'and setting a weekly interval (e.g., every 2 weeks). '
			. 'Provide exactly one of templateId or taskId. When taskId identifies a generated instance, '
			. 'this updates the recurrence rule of the source template instead of creating a new '
			. 'series from the instance. Saving the rule activates recurrence, including for an inactive template.'
		;
	}

	protected function buildDto(array $args, int $userId): MakeTaskRecurringDto
	{
		return MakeTaskRecurringDto::fromArray([
			...$args,
			'period' => Period::Weekly->value,
			'userId' => $userId,
		]);
	}
}
