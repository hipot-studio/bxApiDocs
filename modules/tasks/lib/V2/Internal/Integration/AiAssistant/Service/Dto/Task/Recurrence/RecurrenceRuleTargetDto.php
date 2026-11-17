<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\Recurrence;

class RecurrenceRuleTargetDto extends TemplateIdDto
{
	public static function fromArray(array $props): self
	{
		return new self(
			templateId: static::mapInteger($props, 'templateId'),
			taskId: static::mapInteger($props, 'taskId'),
		);
	}
}
