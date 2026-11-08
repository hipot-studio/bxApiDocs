<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\V2\Internal\Entity\Task\Reminder\Recipient;
use Bitrix\Tasks\V2\Internal\Entity\Task\Reminder\RemindBy;
use Bitrix\Tasks\V2\Internal\Entity\Task\Reminder\RemindVia;
use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Trait\MapTimestampTrait;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder\ReminderSchemaBuilder;

class AddReminderDto
{
	use MapTypeTrait;
	use MapTimestampTrait;

	private function __construct(
		#[PositiveNumber]
		public readonly ?int $userId = null,
		#[PositiveNumber]
		public readonly ?int $taskId = null,
		#[PositiveNumber]
		public readonly ?int $nextRemindTs = null,
		#[NotEmpty]
		public readonly ?RemindBy $remindBy = null,
		#[NotEmpty]
		public readonly ?RemindVia $remindVia = null,
		#[NotEmpty]
		public readonly ?Recipient $recipient = null,
	)
	{
	}

	public static function fromArray(array $props): self
	{
		return new self(
			userId: static::mapInteger($props, 'userId'),
			taskId: static::mapInteger($props, 'taskId'),
			nextRemindTs: static::mapTimestampWithTimeZone($props, 'remindAt'),
			remindBy: static::mapBackedEnum($props, 'remindBy', RemindBy::class) ?? ReminderSchemaBuilder::DEFAULT_REMIND_BY,
			remindVia: static::mapBackedEnum($props, 'remindVia', RemindVia::class) ?? ReminderSchemaBuilder::DEFAULT_REMIND_VIA,
			recipient: static::mapBackedEnum($props, 'recipient', Recipient::class),
		);
	}
}
