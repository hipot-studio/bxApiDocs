<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Tasks\Util\User;
use Bitrix\Tasks\V2\Internal\Entity\Task\Reminder\Recipient;
use Bitrix\Tasks\V2\Internal\Entity\Task\Reminder\RemindBy;
use Bitrix\Tasks\V2\Internal\Entity\Task\Reminder\RemindVia;
use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Trait\MapDateTimeTrait;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder\ReminderSchemaBuilder;

class AddReminderDto
{
	use MapTypeTrait;
	use MapDateTimeTrait;

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
		$nextRemind = static::mapFormattedDateTime($props, 'remindAt');

		$userId = static::mapInteger($props, 'userId');

		$nextRemindTimestamp = 0;
		if ($nextRemind !== null)
		{
			$nextRemindTimestamp = $nextRemind->getTimestamp();

			if ($userId !== null)
			{
				$nextRemindTimestamp -= User::getTimeZoneOffset($userId);
			}
		}

		return new self(
			userId: $userId,
			taskId: static::mapInteger($props, 'taskId'),
			nextRemindTs: $nextRemindTimestamp,
			remindBy: static::mapBackedEnum($props, 'remindBy', RemindBy::class) ?? ReminderSchemaBuilder::DEFAULT_REMIND_BY,
			remindVia: static::mapBackedEnum($props, 'remindVia', RemindVia::class) ?? ReminderSchemaBuilder::DEFAULT_REMIND_VIA,
			recipient: static::mapBackedEnum($props, 'recipient', Recipient::class),
		);
	}
}
