<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder;

use Bitrix\Tasks\V2\Internal\Entity\Priority;
use Bitrix\Tasks\V2\Internal\Entity\Task\Status;
use Bitrix\Tasks\V2\Internal\Entity\Template\RepeatTill;
use Bitrix\Tasks\V2\Internal\Entity\Template\ReplicateParams;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\ClearTaskDeadlineTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\CreateTaskTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\DeleteTaskTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\DetachTaskFromGroupTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\GetTaskByIdTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\Recurrence\SetDailyTaskRecurrenceTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\Recurrence\SetMonthlyByMonthDaysTaskRecurrenceTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\Recurrence\SetMonthlyByWeekDaysTaskRecurrenceTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\Recurrence\SetWeeklyTaskRecurrenceTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\Recurrence\SetYearlyByMonthDaysTaskRecurrenceTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\Recurrence\SetYearlyByWeekDaysTaskRecurrenceTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\SearchTasksTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\UpdateTaskTool;

class TaskSchemaBuilder extends BaseSchemaBuilder
{
	protected function getProperties(?string $action): array
	{
		return match ($action)
		{
			CreateTaskTool::ACTION_NAME => $this->buildCreateTaskProperties(),
			UpdateTaskTool::ACTION_NAME => $this->buildUpdateTaskProperties(),
			ClearTaskDeadlineTool::ACTION_NAME,
			DetachTaskFromGroupTool::ACTION_NAME => $this->buildClearTaskFieldProperties(),
			DeleteTaskTool::ACTION_NAME => $this->buildDeleteTaskProperties(),
			SearchTasksTool::ACTION_NAME => $this->buildSearchTasksProperties(),
			SetDailyTaskRecurrenceTool::ACTION_NAME => $this->buildSetDailyRecurrenceProperties(),
			SetMonthlyByMonthDaysTaskRecurrenceTool::ACTION_NAME => $this->buildSetMonthlyByDaysRecurrenceProperties(),
			SetMonthlyByWeekDaysTaskRecurrenceTool::ACTION_NAME => $this->buildSetMonthlyByWeeksRecurrenceProperties(),
			SetWeeklyTaskRecurrenceTool::ACTION_NAME => $this->buildSetWeeklyRecurrenceProperties(),
			SetYearlyByMonthDaysTaskRecurrenceTool::ACTION_NAME => $this->buildSetYearlyByDaysRecurrenceProperties(),
			SetYearlyByWeekDaysTaskRecurrenceTool::ACTION_NAME => $this->buildSetYearlyByWeeksRecurrenceProperties(),
			GetTaskByIdTool::ACTION_NAME => $this->buildGetTaskByIdProperties(),
			default => [],
		};
	}

	protected function getRequiredFields(?string $action): array
	{
		return match ($action)
		{
			UpdateTaskTool::ACTION_NAME,
			SetDailyTaskRecurrenceTool::ACTION_NAME,
			SetMonthlyByMonthDaysTaskRecurrenceTool::ACTION_NAME,
			SetMonthlyByWeekDaysTaskRecurrenceTool::ACTION_NAME,
			SetWeeklyTaskRecurrenceTool::ACTION_NAME,
			SetYearlyByMonthDaysTaskRecurrenceTool::ACTION_NAME,
			SetYearlyByWeekDaysTaskRecurrenceTool::ACTION_NAME,
			DeleteTaskTool::ACTION_NAME,
			GetTaskByIdTool::ACTION_NAME
			=> ['taskId'],
			CreateTaskTool::ACTION_NAME => ['title'],
			default => [],
		};
	}

	private function buildCreateTaskProperties(): array
	{
		return [
			'title' => [
				'type' => 'string',
				'description' => 'Task title. Must be a non-empty string',
				'minLength' => 1,
			],
			'description' => [
				'type' => ['string', 'null'],
				'description' => 'Task description',
			],
			'creatorId' => [
				'type' => ['integer', 'null'],
				'description' => 'Identifier of the creator. Defaults to the current user.',
				'minimum' => 1,
			],
			'responsibleId' => [
				'type' => ['integer', 'null'],
				'description' => 'Identifier of the responsible user. Defaults to the current user.',
				'minimum' => 1,
			],
			'deadlineDate' => [
				'type' => ['string', 'null'],
				'format' => 'date-time',
				'description' => "Task deadline in '" . BaseSchemaBuilder::DATE_FORMAT . "' format",
			],
			'groupId' => [
				'type' => ['integer', 'null'],
				'description' => 'Identifier of the group for the task. Must be a positive integer.',
				'minimum' => 1,
			],
			'priority' => [
				'type' => ['string', 'null'],
				'description' =>
					"Task priority. '"
					. Priority::High->value . "' marks it as important, '"
					. Priority::Average->value . "' unmarks it."
				,
				'enum' => [...Priority::values(), null],
			],
			'status' => [
				'type' => ['string', 'null'],
				'description' => 'Task status',
				'enum' => [...Status::values(), null],
			],
			'parentTaskId' => [
				'type' => ['integer', 'null'],
				'description' => 'Identifier of the parent task to create a subtask. Must be a positive integer',
				'minimum' => 1,
			],
			'accompliceIds' => [
				'type' => ['array', 'null'],
				'items' => [
					'type' => 'integer',
					'minimum' => 1,
				],
				'description' => 'List of user IDs to add as accomplices',
				'minItems' => 1,
			],
			'auditorIds' => [
				'type' => ['array', 'null'],
				'items' => [
					'type' => 'integer',
					'minimum' => 1,
				],
				'description' => 'List of user IDs to add as auditors',
				'minItems' => 1,
			],
		];
	}

	private function buildUpdateTaskProperties(): array
	{
		return [
			'taskId' => [
				'type' => 'integer',
				'description' => 'Identifier of the task to update. Must be a positive integer.',
				'minimum' => 1,
			],
			'title' => [
				'type' => ['string', 'null'],
				'description' => 'New title. Null to leave unchanged',
				'minLength' => 1,
			],
			'description' => [
				'type' => ['string', 'null'],
				'description' => 'New description. Null to leave unchanged.',
			],
			'creatorId' => [
				'type' => ['integer', 'null'],
				'description' => "New creator's user ID. Must be a positive integer. Null to leave unchanged.",
				'minimum' => 1,
			],
			'responsibleId' => [
				'type' => ['integer', 'null'],
				'description' => "New responsible user's ID. Must be a positive integer. Null to leave unchanged.",
				'minimum' => 1,
			],
			'deadlineDate' => [
				'type' => ['string', 'null'],
				'format' => 'date-time',
				'description' =>
					"New deadline in '" . BaseSchemaBuilder::DATE_FORMAT
					. "' format. Null to leave unchanged. "
					. 'To clear the deadline, use a dedicated tool.'
				,
			],
			'groupId' => [
				'type' => ['integer', 'null'],
				'description' =>
					'New group ID. Must be a positive integer. Null to leave unchanged. '
					. 'To detach from a group, use a dedicated tool.'
				,
				'minimum' => 1,
			],
			'priority' => [
				'type' => ['string', 'null'],
				'description' =>
					"New priority. '"
					. Priority::High->value . "' marks it as important, '"
					. Priority::Average->value . "' unmarks it."
					. 'Null to leave unchanged.'
				,
				'enum' => [...Priority::values(), null],
			],
			'status' => [
				'type' => ['string', 'null'],
				'description' => 'New status. Null to leave unchanged.',
				'enum' => [...Status::values(), null],
			],
			'parentTaskId' => [
				'type' => ['integer', 'null'],
				'description' => 'New parent task ID. Must be a positive integer. Null to leave unchanged.',
				'minimum' => 1,
			],
		];
	}

	private function buildClearTaskFieldProperties(): array
	{
		return [
			'taskId' => [
				'type' => 'integer',
				'description' => 'Identifier of the task to update. Must be a positive integer.',
				'minimum' => 1,
			],
		];
	}

	private function buildDeleteTaskProperties(): array
	{
		return [
			'taskId' => [
				'type' => 'integer',
				'description' => 'Identifier of the task to delete. Must be a positive integer.',
				'minimum' => 1,
			],
		];
	}

	private function buildSearchTasksProperties(): array
	{
		return [
			'title' => [
				'type' => ['string', 'null'],
				'description' => 'Keyword to search in the task title or null if not needed.',
				'minLength' => 1,
			],
			'description' => [
				'type' => ['string', 'null'],
				'description' => 'Keyword to search in the task description or null if not needed.',
				'minLength' => 1,
			],
			'deadlineFrom' => [
				'type' => ['string', 'null'],
				'format' => 'date-time',
				'description' =>
					"The start of the deadline range in '"
					. BaseSchemaBuilder::DATE_FORMAT
					. "' format or null if not needed."
				,
			],
			'deadlineTo' => [
				'type' => ['string', 'null'],
				'format' => 'date-time',
				'description' =>
					"The end of the deadline range in '"
					. BaseSchemaBuilder::DATE_FORMAT
					. "' format or null if not needed."
				,
			],
			'groupId' => [
				'type' => ['integer', 'null'],
				'description' => 'Identifier of the group. Must be a positive integer or null if not needed.',
				'minimum' => 1,
			],
			'responsibleId' => [
				'type' => ['integer', 'null'],
				'description' =>
					'Identifier of the responsible user. '
					. 'Must be a positive integer or null if not needed.'
				,
				'minimum' => 1,
			],
			'creatorId' => [
				'type' => ['integer', 'null'],
				'description' =>
					'Identifier of the user who created the task. '
					. 'Must be a positive integer or null if not needed.'
				,
				'minimum' => 1,
			],
			'memberId' => [
				'type' => ['integer', 'null'],
				'description' => 'Identifier of a task member. Leave null for the current user.',
				'minimum' => 1,
			],
			'tag' => [
				'type' => ['string', 'null'],
				'description' => 'Tag name to search for or null if not needed.',
				'minimum' => 1,
			],
			'auditorId' => [
				'type' => ['integer', 'null'],
				'description' => 'Identifier of the auditor. Must be a positive integer or null if not needed.',
				'minimum' => 1,
			],
			'accompliceId' => [
				'type' => ['integer', 'null'],
				'description' => 'Identifier of the accomplice. Must be a positive integer or null if not needed.',
				'minimum' => 1,
			],
			'status' => [
				'type' => ['string', 'null'],
				'description' => 'Task status to search for. By default, tasks in progress are searched.',
				'enum' => [...Status::values(), null],
			],
		];
	}

	private function buildSetDailyRecurrenceProperties(): array
	{
		return [
			...$this->buildGeneralRecurrenceProperties(),
			'everyDay' => [
				'type' => ['integer', 'null'],
				'description' => 'The number of days between repetitions.',
				'minimum' => 1,
			],
			'dailyMonthInterval' => [
				'type' => ['integer', 'null'],
				'description' => 'The interval in months between repetitions of the task. Default is 0.',
				'minimum' => 0,
			],
		];
	}

	private function buildSetWeeklyRecurrenceProperties(): array
	{
		return [
			...$this->buildGeneralRecurrenceProperties(),
			'everyWeek' => [
				'type' => ['integer', 'null'],
				'description' => 'The number of weeks between repetitions.',
				'minimum' => 1,
			],
			'weekDays' => [
				'type' => ['array', 'null'],
				'items' => [
					'type' => 'integer',
					'description' => 'Number of the day of the week. Starts with 1 and ends with 7.',
					'minimum' => 1,
					'maximum' => 7,
				],
				'description' => 'The numbers of the days of the week.',
			],
		];
	}

	private function buildSetMonthlyByDaysRecurrenceProperties(): array
	{
		return [
			...$this->buildGeneralRecurrenceProperties(),
			'monthlyDayNum' => [
				'type' => ['integer', 'null'],
				'description' => 'The date on which the task repetition in the month will be created. Starts with 1.',
				'minimum' => 1,
			],
			'monthlyMonthNum1' => [
				'type' => ['integer', 'null'],
				'description' => 'The month on which the task repetition will be created. Starts with 0.',
				'minimum' => 0,
			],
			'monthlyMonthNum2' => [
				'type' => ['integer', 'null'],
				'description' =>
					'The month in which the task repetition will be created in the week is specified here. '
					. 'Starts with 0.'
				,
				'minimum' => 0,
			],
		];
	}

	private function buildSetMonthlyByWeeksRecurrenceProperties(): array
	{
		return [
			...$this->buildGeneralRecurrenceProperties(),
			'monthlyWeekDayNum' => [
				'type' => ['integer', 'null'],
				'description' => 'Which week the task will be repeated. Starts with 0.',
				'minimum' => 0,
			],
			'monthlyWeekDay' => [
				'type' => ['integer', 'null'],
				'description' => 'The day of the week on which the task will be repeated is reflected. Starts with 0.',
				'minimum' => 0,
			],
			'monthlyMonthNum2' => [
				'type' => ['integer', 'null'],
				'description' =>
					'The month in which the task repetition will be created in the week is specified here. '
					. 'Starts with 0.'
				,
				'minimum' => 0,
			],
		];
	}

	private function buildSetYearlyByDaysRecurrenceProperties(): array
	{
		return [
			...$this->buildGeneralRecurrenceProperties(),
			'yearlyDayNum' => [
				'type' => ['integer', 'null'],
				'description' => 'The date on which the task repetition in the month will be created. Starts with 1.',
				'minimum' => 1,
			],
			'yearlyMonth1' => [
				'type' => ['integer', 'null'],
				'description' => 'The month on which the task repetition will be created. Starts with 0.',
				'minimum' => 0,
			],
			'yearlyMonth2' => [
				'type' => ['integer', 'null'],
				'description' =>
					'The month in which the task repetition will be created in the week is specified here. '
					. 'Starts with 0.'
				,
				'minimum' => 0,
			],
		];
	}

	private function buildSetYearlyByWeeksRecurrenceProperties(): array
	{
		return [
			...$this->buildGeneralRecurrenceProperties(),
			'yearlyWeekDayNum' => [
				'type' => ['integer', 'null'],
				'description' => 'In which week the task will be repeated. Starts with 0.',
				'minimum' => 0,
			],
			'yearlyWeekDay' => [
				'type' => ['integer', 'null'],
				'description' => 'The day of the week on which the task repetition will be created. Starts with 0.',
				'minimum' => 0,
			],
			'yearlyMonth2' => [
				'type' => ['integer', 'null'],
				'description' =>
					'The month in which the task repetition will be created in the week is specified here. '
					. 'Starts with 0.'
				,
				'minimum' => 0,
			],
		];
	}

	private function buildGeneralRecurrenceProperties(): array
	{
		return [
			'taskId' => [
				'type' => 'integer',
				'description' => 'Identifier of the task. Must be a positive integer.',
				'minimum' => 1,
			],
			'workdayOnly' => [
				'type' => ['boolean', 'null'],
				'description' => 'If true, the task will only recur on workdays. Default is false.',
			],
			'time' => [
				'type' => ['string', 'null'],
				'format' => 'time',
				'description' =>
					"Time of day for the recurrence in 'HH:MM:SS' format. Default is "
					. ReplicateParams::DEFAULT_TIME . '.'
				,
			],
			'startDate' => [
				'type' => ['string', 'null'],
				'format' => 'date-time',
				'description' =>
					'Recurrence start date, in '
					. BaseSchemaBuilder::DATE_FORMAT
					. ' format. Default is save date.'
				,
			],
			'repeatTill' => [
				'type' => ['string', 'null'],
				'description' => 'Condition for stopping recurrence.',
				'enum' => [...RepeatTill::values(), null],
			],
			'endDate' => [
				'type' => ['string', 'null'],
				'format' => 'date-time',
				'description' =>
					"End date for recurrence if repeatTill is 'date', in '"
					. BaseSchemaBuilder::DATE_FORMAT . "' format."
				,
			],
			'times' => [
				'type' => ['integer', 'null'],
				'description' => 'If repeatTill is "times" it must be a number of repeats',
				'minimum' => 0,
			],
		];
	}

	private function buildGetTaskByIdProperties(): array
	{
		return [
			'taskId' => [
				'type' => 'integer',
				'description' => 'Identifier of the task. Must be a positive integer.',
				'minimum' => 1,
			],
		];
	}
}
