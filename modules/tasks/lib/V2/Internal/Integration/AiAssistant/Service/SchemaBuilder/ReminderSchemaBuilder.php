<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder;

use Bitrix\Tasks\V2\Internal\Entity\Task\Reminder\Recipient;
use Bitrix\Tasks\V2\Internal\Entity\Task\Reminder\RemindBy;
use Bitrix\Tasks\V2\Internal\Entity\Task\Reminder\RemindVia;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\AddReminderTool;

class ReminderSchemaBuilder extends BaseSchemaBuilder
{
	public const DATE_FORMAT = 'c';
	public const DEFAULT_REMIND_BY = RemindBy::Date;
	public const DEFAULT_REMIND_VIA = RemindVia::Notification;

	protected function getProperties(?string $action): array
	{
		return match ($action)
		{
			AddReminderTool::ACTION_NAME => $this->buildAddReminderProperties(),
			default => [],
		};
	}

	protected function getRequiredFields(?string $action): array
	{
		return match ($action)
		{
			AddReminderTool::ACTION_NAME => ['taskId', 'remindAt', 'recipient'],
			default => [],
		};
	}

	private function buildAddReminderProperties(): array
	{
		return [
			'taskId' => [
				'type' => 'integer',
				'description' => 'Identifier of the task. Must be a positive integer.',
				'minimum' => 1,
			],
			'remindAt' => [
				'type' => 'string',
				'format' => 'date-time',
				'description' =>
					"When to send the reminder, in '"
					. BaseSchemaBuilder::DATE_FORMAT
					. "' format (e.g., '2024/08/15 10:00')."
				,
			],
			'recipient' => [
				'type' => 'string',
				'description' => 'Task participant to send a reminder to.',
				'enum' => Recipient::values(),
			],
			'remindBy' => [
				'type' => ['string', 'null'],
				'description' => "The principle for reminding. Defaults to '" . self::DEFAULT_REMIND_BY->value . "'.",
				'enum' => [...RemindBy::values(), null],
			],
			'remindVia' => [
				'type' => ['string', 'null'],
				'description' => "How to send the reminder. Defaults to '" . self::DEFAULT_REMIND_VIA->value . "'.",
				'enum' => [...RemindVia::values(), null],
			],
		];
	}
}
