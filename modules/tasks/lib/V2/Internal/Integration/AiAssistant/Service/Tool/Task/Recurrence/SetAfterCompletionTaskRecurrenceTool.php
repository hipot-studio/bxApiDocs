<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\Recurrence;

use Bitrix\Main\Config\Feature;
use Bitrix\Tasks\Feature\RegularTaskTypesFeature;
use Bitrix\Tasks\Replication\Template\Enum\RegularityType;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\MakeTaskRecurringDto;

class SetAfterCompletionTaskRecurrenceTool extends BaseRecurrenceTool
{
	public const ACTION_NAME = 'set_after_completion_task_recurrence';

	public function canList(int $userId): bool
	{
		return parent::canList($userId) && Feature::isEnabled(RegularTaskTypesFeature::class);
	}

	public function getDescription(): string
	{
		return
			'Sets up task recurrence after the previous task instance is completed. '
			. 'completionDelay is measured in days; deadlineOffset is measured in seconds. '
			. 'Provide exactly one of templateId or taskId. When taskId identifies a generated instance, '
			. 'this updates the recurrence rule of the source template instead of creating a new '
			. 'series from the instance. Saving the rule activates recurrence, including for an inactive template.'
		;
	}

	protected function buildDto(array $args, int $userId): MakeTaskRecurringDto
	{
		return MakeTaskRecurringDto::fromArray([
			...$args,
			'regularityType' => RegularityType::AfterCompletion->value,
			'userId' => $userId,
		]);
	}
}
