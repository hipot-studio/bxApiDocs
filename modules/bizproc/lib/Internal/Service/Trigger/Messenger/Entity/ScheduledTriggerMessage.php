<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Trigger\Messenger\Entity;

use Bitrix\Main\Messenger\Entity\AbstractMessage;

class ScheduledTriggerMessage extends AbstractMessage
{
	public function __construct(
		public readonly int $scheduleId,
		public readonly int $templateId,
		public readonly string $triggerName,
		public readonly ?string $scheduledAt,
	)
	{
	}

	public function jsonSerialize(): array
	{
		return [
			'scheduleId' => $this->scheduleId,
			'templateId' => $this->templateId,
			'triggerName' => $this->triggerName,
			'scheduledAt' => $this->scheduledAt,
		];
	}
}
