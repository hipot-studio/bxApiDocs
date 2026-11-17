<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder;

use Bitrix\Main\Config\Feature;
use Bitrix\Tasks\Feature\RegularTaskTypesFeature;
use Bitrix\Tasks\Replication\Template\Enum\DeadlineType;
use Bitrix\Tasks\V2\Internal\Entity\Priority;
use Bitrix\Tasks\V2\Internal\Entity\Task\Status;
use Bitrix\Tasks\V2\Internal\Entity\Template\RepeatTill;
use Bitrix\Tasks\V2\Internal\Entity\Template\ReplicateParams;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Enum\ProjectTasksForAnalysisSort;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\GetProjectTasksForAnalysisDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\GetTaskForAnalysisDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\MakeTaskRecurringDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\Recurrence\RecurrenceRuleTargetDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\Recurrence\SetRecurringInstanceDeadlineDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\ClearTaskDeadlineTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\CreateTaskTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\DeleteTaskTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\DetachTaskFromGroupTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\GetProjectTasksForAnalysisTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\GetTaskForAnalysisTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\GetTaskByIdTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\Recurrence\SetAfterCompletionTaskRecurrenceTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\Recurrence\SetDailyTaskRecurrenceTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\Recurrence\SetMonthlyByMonthDaysTaskRecurrenceTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\Recurrence\SetMonthlyByWeekDaysTaskRecurrenceTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\Recurrence\SetRecurringInstanceDeadlineTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\Recurrence\SetWeeklyTaskRecurrenceTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\Recurrence\SetYearlyByMonthDaysTaskRecurrenceTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\Recurrence\SetYearlyByWeekDaysTaskRecurrenceTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\SearchTasksTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\UpdateTaskTool;

class TaskSchemaBuilder extends BaseSchemaBuilder
{
	public function build(?string $action): array
	{
		$schema = parent::build($action);
		if ($action === SetRecurringInstanceDeadlineTool::ACTION_NAME)
		{
			$schema['oneOf'] = SetRecurringInstanceDeadlineDto::getIdentifierOneOf();
		}
		elseif ($this->isRecurrenceRuleAction($action))
		{
			$schema['oneOf'] = RecurrenceRuleTargetDto::getIdentifierOneOf();
		}

		return $schema;
	}

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
			SetAfterCompletionTaskRecurrenceTool::ACTION_NAME => $this->buildSetAfterCompletionRecurrenceProperties(),
			SetDailyTaskRecurrenceTool::ACTION_NAME => $this->buildSetDailyRecurrenceProperties(),
			SetMonthlyByMonthDaysTaskRecurrenceTool::ACTION_NAME => $this->buildSetMonthlyByDaysRecurrenceProperties(),
			SetMonthlyByWeekDaysTaskRecurrenceTool::ACTION_NAME => $this->buildSetMonthlyByWeeksRecurrenceProperties(),
			SetWeeklyTaskRecurrenceTool::ACTION_NAME => $this->buildSetWeeklyRecurrenceProperties(),
			SetYearlyByMonthDaysTaskRecurrenceTool::ACTION_NAME => $this->buildSetYearlyByDaysRecurrenceProperties(),
			SetYearlyByWeekDaysTaskRecurrenceTool::ACTION_NAME => $this->buildSetYearlyByWeeksRecurrenceProperties(),
			SetRecurringInstanceDeadlineTool::ACTION_NAME => $this->buildSetRecurringInstanceDeadlineProperties(),
			GetTaskByIdTool::ACTION_NAME => $this->buildGetTaskByIdProperties(),
			GetTaskForAnalysisTool::ACTION_NAME => $this->buildGetTaskForAnalysisProperties(),
			GetProjectTasksForAnalysisTool::ACTION_NAME => $this->buildGetProjectTasksForAnalysisProperties(),
			default => [],
		};
	}

	protected function getRequiredFields(?string $action): array
	{
		return match ($action)
		{
			UpdateTaskTool::ACTION_NAME,
			ClearTaskDeadlineTool::ACTION_NAME,
			DetachTaskFromGroupTool::ACTION_NAME,
			DeleteTaskTool::ACTION_NAME,
			GetTaskByIdTool::ACTION_NAME,
			GetTaskForAnalysisTool::ACTION_NAME
			=> ['taskId'],
			SetWeeklyTaskRecurrenceTool::ACTION_NAME => ['weekDays'],
			SetAfterCompletionTaskRecurrenceTool::ACTION_NAME,
			SetDailyTaskRecurrenceTool::ACTION_NAME,
			SetMonthlyByMonthDaysTaskRecurrenceTool::ACTION_NAME,
			SetMonthlyByWeekDaysTaskRecurrenceTool::ACTION_NAME,
			SetYearlyByMonthDaysTaskRecurrenceTool::ACTION_NAME,
			SetYearlyByWeekDaysTaskRecurrenceTool::ACTION_NAME => [],
			SetRecurringInstanceDeadlineTool::ACTION_NAME => [],
			CreateTaskTool::ACTION_NAME => ['title'],
			GetProjectTasksForAnalysisTool::ACTION_NAME => ['projectId'],
			default => [],
		};
	}

	private function buildSetAfterCompletionRecurrenceProperties(): array
	{
		return [
			...$this->buildRecurrenceRuleTargetProperties(),
			'completionDelay' => [
				'type' => 'integer',
				'description' => 'Delay in days after completion before creating the next task. Default is 0.',
				'minimum' => 0,
				'default' => 0,
			],
			...$this->buildDeadlineProperties('Deadline offset in seconds from the next task creation time.'),
			'workdayOnly' => [
				'type' => 'boolean',
				'description' => 'If true, the delay is counted in workdays. Default is false.',
				'default' => false,
			],
			'time' => [
				'type' => 'string',
				'pattern' => MakeTaskRecurringDto::TIME_PATTERN,
				'description' =>
					"Current user's local wall-clock time of day in 'HH:MM' 24-hour format. "
					. "Legacy 'HH:MM:SS' values are accepted and normalized to minute precision. "
					. 'The MCP adapter converts it to server time; do not add a timezone suffix or convert it to UTC. '
					. 'Default is '
					. ReplicateParams::DEFAULT_TIME . '.'
				,
				'default' => ReplicateParams::DEFAULT_TIME,
			],
			'startDate' => [
				'type' => 'string',
				'format' => 'date-time',
				'description' =>
					'Recurrence start date. Default is save date.' . BaseSchemaBuilder::DATE_TIME_NOTE
				,
			],
			'repeatTill' => [
				'type' => 'string',
				'description' => 'Condition for stopping recurrence. Default is endless.',
				'enum' => RepeatTill::values(),
				'default' => RepeatTill::ENDLESS->value,
			],
			'endDate' => [
				'type' => 'string',
				'format' => 'date-time',
				'description' =>
					"End date for recurrence if repeatTill is 'date'." . BaseSchemaBuilder::DATE_TIME_NOTE
				,
			],
			'times' => [
				'type' => 'integer',
				'description' => 'Number of repeats if repeatTill is "times". Default is 0.',
				'minimum' => 0,
				'default' => 0,
			],
		];
	}

	private function buildSetRecurringInstanceDeadlineProperties(): array
	{
		return [
			...SetRecurringInstanceDeadlineDto::getIdentifierProperties(),
			'deadlineAfter' => [
				'type' => 'integer',
				'description' =>
					'Deadline offset in seconds for task instances created from the template. '
					. 'Provide deadlineAfter, deadlineType, or both. '
					. '0 without deadlineType removes the deadline, including a previously set '
					. DeadlineType::CreationDay->value . '. '
					. '0 with deadlineType=' . DeadlineType::CreationDay->value
					. ' puts the deadline on the creation day. '
					. 'A positive value replaces deadlineType.'
				,
				'minimum' => 0,
			],
			'matchesWorkTime' => [
				'type' => 'boolean',
				'description' => 'If true, the deadline is adjusted to work time.',
			],
			'deadlineType' => [
				'type' => 'string',
				'enum' => [DeadlineType::CreationDay->value],
				'description' =>
					'Set to ' . DeadlineType::CreationDay->value . ' to put the deadline on the task creation day; '
					. 'use it with deadlineAfter 0 or absent. A positive deadlineAfter replaces it. '
					. 'Provide deadlineAfter, deadlineType, or both.'
				,
			],
		];
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
				'description' => 'Task description.' . BaseSchemaBuilder::FORMATTING_NOTE,
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
				'description' => 'Task deadline.'
					. ' If omitted, the default deadline from the creator settings is applied automatically;'
					. ' to create a task with no deadline at all, set "withoutDeadline" to true.'
					. BaseSchemaBuilder::DATE_TIME_NOTE
				,
			],
			'withoutDeadline' => [
				'type' => ['boolean', 'null'],
				'description' => 'Set to true only if the task must have no deadline at all.'
					. ' Must not be combined with "deadlineDate". Default is false.'
				,
				'default' => false,
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
				'description' => 'New description. Null to leave unchanged.' . BaseSchemaBuilder::FORMATTING_NOTE,
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
					'New deadline. Null to leave unchanged. To clear the deadline, use a dedicated tool.'
					. BaseSchemaBuilder::DATE_TIME_NOTE
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
					'The start of the deadline range, or null if not needed.' . BaseSchemaBuilder::DATE_TIME_NOTE
				,
			],
			'deadlineTo' => [
				'type' => ['string', 'null'],
				'format' => 'date-time',
				'description' =>
					'The end of the deadline range, or null if not needed.' . BaseSchemaBuilder::DATE_TIME_NOTE
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
				'minLength' => 1,
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
				'type' => 'array',
				'items' => [
					'type' => 'integer',
					'description' => 'Number of the day of the week. Starts with 1 and ends with 7.',
					'minimum' => 1,
					'maximum' => 7,
				],
				'description' => 'The numbers of the days of the week.',
				'minItems' => 1,
				'uniqueItems' => true,
			],
		];
	}

	private function buildSetMonthlyByDaysRecurrenceProperties(): array
	{
		return [
			...$this->buildGeneralRecurrenceProperties(),
			'monthlyDayNum' => [
				'type' => ['integer', 'null'],
				'description' => 'Calendar day of the month, from 1 to 31. Default is 1.',
				'minimum' => 1,
				'maximum' => 31,
				'default' => 1,
			],
			'monthlyMonthNum1' => [
				'type' => ['integer', 'null'],
				'description' => 'Number of months between repetitions. Default is 1.',
				'minimum' => 1,
				'default' => 1,
			],
		];
	}

	private function buildSetMonthlyByWeeksRecurrenceProperties(): array
	{
		return [
			...$this->buildGeneralRecurrenceProperties(),
			'monthlyWeekDayNum' => [
				'type' => ['integer', 'null'],
				'description' => 'Zero-based ordinal occurrence of the weekday in the month, from 0 to 4. Default is 0.',
				'minimum' => 0,
				'maximum' => 4,
				'default' => 0,
			],
			'monthlyWeekDay' => [
				'type' => ['integer', 'null'],
				'description' => 'Zero-based weekday index from 0 to 6. Default is 0.',
				'minimum' => 0,
				'maximum' => 6,
				'default' => 0,
			],
			'monthlyMonthNum2' => [
				'type' => ['integer', 'null'],
				'description' => 'Number of months between repetitions. Default is 1.',
				'minimum' => 1,
				'default' => 1,
			],
		];
	}

	private function buildSetYearlyByDaysRecurrenceProperties(): array
	{
		return [
			...$this->buildGeneralRecurrenceProperties(),
			'yearlyDayNum' => [
				'type' => ['integer', 'null'],
				'description' => 'Calendar day of the month, from 1 to 31. Default is 1.',
				'minimum' => 1,
				'maximum' => 31,
				'default' => 1,
			],
			'yearlyMonth1' => [
				'type' => ['integer', 'null'],
				'description' => 'Zero-based month index from 0 to 11; for example, November is 10. Default is 0.',
				'minimum' => 0,
				'maximum' => 11,
				'default' => 0,
			],
		];
	}

	private function buildSetYearlyByWeeksRecurrenceProperties(): array
	{
		return [
			...$this->buildGeneralRecurrenceProperties(),
			'yearlyWeekDayNum' => [
				'type' => ['integer', 'null'],
				'description' => 'Zero-based ordinal occurrence of the weekday in the month, from 0 to 4. Default is 0.',
				'minimum' => 0,
				'maximum' => 4,
				'default' => 0,
			],
			'yearlyWeekDay' => [
				'type' => ['integer', 'null'],
				'description' => 'Zero-based weekday index from 0 to 6. Default is 0.',
				'minimum' => 0,
				'maximum' => 6,
				'default' => 0,
			],
			'yearlyMonth2' => [
				'type' => ['integer', 'null'],
				'description' => 'Zero-based month index from 0 to 11; for example, November is 10. Default is 0.',
				'minimum' => 0,
				'maximum' => 11,
				'default' => 0,
			],
		];
	}

	private function buildGeneralRecurrenceProperties(): array
	{
		return [
			...$this->buildRecurrenceRuleTargetProperties(),
			...$this->buildDeadlineRecurrenceProperties(),
			'workdayOnly' => [
				'type' => ['boolean', 'null'],
				'description' => 'If true, the task will only recur on workdays. Default is false.',
			],
			'time' => [
				'type' => ['string', 'null'],
				'pattern' => MakeTaskRecurringDto::TIME_PATTERN,
				'description' =>
					"Current user's local wall-clock time of day in 'HH:MM' 24-hour format. "
					. "Legacy 'HH:MM:SS' values are accepted and normalized to minute precision. "
					. 'The MCP adapter converts it to server time; do not add a timezone suffix or convert it to UTC. '
					. 'Default is '
					. ReplicateParams::DEFAULT_TIME . '.'
				,
			],
			'startDate' => [
				'type' => ['string', 'null'],
				'format' => 'date-time',
				'description' =>
					'Recurrence start date. Default is save date.' . BaseSchemaBuilder::DATE_TIME_NOTE
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
					"End date for recurrence if repeatTill is 'date'." . BaseSchemaBuilder::DATE_TIME_NOTE
				,
			],
			'times' => [
				'type' => ['integer', 'null'],
				'description' => 'If repeatTill is "times" it must be a number of repeats',
				'minimum' => 0,
			],
		];
	}

	/**
	 * The deadline-driven recurrence settings ship with the release flag, so they are not announced to
	 * the agent while the flag is off.
	 */
	private function buildDeadlineRecurrenceProperties(): array
	{
		if (!Feature::isEnabled(RegularTaskTypesFeature::class))
		{
			return [];
		}

		return [
			'anchorToDeadline' => [
				'type' => 'object',
				'description' => 'Creates task instances before the deadline of the previous instance.',
				'properties' => [
					'leadDays' => [
						'type' => 'integer',
						'description' => 'Number of days before the deadline. Default is 0.',
						'minimum' => 0,
						'default' => 0,
					],
				],
				'additionalProperties' => false,
			],
			...$this->buildDeadlineProperties('Deadline offset in seconds from the task creation time.'),
		];
	}

	private function buildDeadlineProperties(string $offsetDescription): array
	{
		return [
			'deadlineOffset' => [
				'type' => 'integer',
				'description' =>
					$offsetDescription . ' '
					. 'On update, omit both deadlineOffset and deadlineType to keep the current deadline. '
					. 'On create, omitting both creates instances without a deadline. '
					. 'Set 0 without deadlineType to clear the deadline. A positive value replaces deadlineType.'
				,
				'minimum' => 0,
			],
			'matchesWorkTime' => [
				'type' => 'boolean',
				'description' => 'If true, the deadline is adjusted to work time.',
			],
			'deadlineType' => [
				'type' => 'string',
				'enum' => [DeadlineType::CreationDay->value],
				'description' =>
					'Set to ' . DeadlineType::CreationDay->value . ' to put the deadline on the task creation day; '
					. 'use it with deadlineOffset 0 or absent. A positive deadlineOffset replaces it. '
					. 'On update, omit both deadline fields to keep the current deadline; on create, omitting both '
					. 'creates instances without a deadline. Set deadlineOffset 0 alone to clear the deadline.'
				,
			],
		];
	}

	private function buildRecurrenceRuleTargetProperties(): array
	{
		return [
			'taskId' => [
				'type' => 'integer',
				'description' =>
					'ID of the task to make recurring, an original recurring task, or one of its generated instances. '
					. 'A generated instance updates the rule of its source template; no new recurring series is created '
					. 'from the instance. Use this field instead of templateId.'
				,
				'minimum' => 1,
			],
			'templateId' => [
				'type' => 'integer',
				'description' => 'ID of an existing recurring task template. Use this field instead of taskId.',
				'minimum' => 1,
			],
		];
	}

	private function isRecurrenceRuleAction(?string $action): bool
	{
		return in_array(
			$action,
			[
				SetAfterCompletionTaskRecurrenceTool::ACTION_NAME,
				SetDailyTaskRecurrenceTool::ACTION_NAME,
				SetWeeklyTaskRecurrenceTool::ACTION_NAME,
				SetMonthlyByMonthDaysTaskRecurrenceTool::ACTION_NAME,
				SetMonthlyByWeekDaysTaskRecurrenceTool::ACTION_NAME,
				SetYearlyByMonthDaysTaskRecurrenceTool::ACTION_NAME,
				SetYearlyByWeekDaysTaskRecurrenceTool::ACTION_NAME,
			],
			true,
		);
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

	private function buildGetTaskForAnalysisProperties(): array
	{
		return [
			'taskId' => [
				'type' => 'integer',
				'description' => 'Identifier of the task. Must be a positive integer.',
				'minimum' => 1,
			],
			'messagesLimit' => [
				'type' => ['integer', 'null'],
				'description' =>
					'Number of chat messages to return.'
					. ' Must be an integer between ' . GetTaskForAnalysisDto::MESSAGES_LIMIT_MIN
					. ' and ' . GetTaskForAnalysisDto::MESSAGES_LIMIT_MAX . '.'
					. ' Pass null or omit if messages not needed.'
				,
				'minimum' => GetTaskForAnalysisDto::MESSAGES_LIMIT_MIN,
				'maximum' => GetTaskForAnalysisDto::MESSAGES_LIMIT_MAX,
			],
			'historyLimit' => [
				'type' => ['integer', 'null'],
				'description' =>
					'Number of significant history entries to return.'
					. ' Must be an integer between ' . GetTaskForAnalysisDto::HISTORY_LIMIT_MIN
					. ' and ' . GetTaskForAnalysisDto::HISTORY_LIMIT_MAX . '.'
					. ' Pass null or omit if history not needed.'
				,
				'minimum' => GetTaskForAnalysisDto::HISTORY_LIMIT_MIN,
				'maximum' => GetTaskForAnalysisDto::HISTORY_LIMIT_MAX,
			],
		];
	}

	private function buildGetProjectTasksForAnalysisProperties(): array
	{
		return [
			'projectId' => [
				'type' => 'integer',
				'description' => 'Project (group) ID. Must be a positive integer.',
				'minimum' => 1,
			],
			'responsibleId' => [
				'type' => ['integer', 'null'],
				'description' =>
					'Filter by responsible user ID. Use for personal context — '
					. 'returns only tasks where the user is the responsible. '
					. 'Must be a positive integer or null if not needed.'
				,
				'minimum' => 1,
			],
			'creatorId' => [
				'type' => ['integer', 'null'],
				'description' =>
					'Filter by creator user ID. Use for personal context — '
					. 'returns only tasks the user has created. '
					. 'Combined with responsibleId narrows to tasks where the user is both creator and responsible. '
					. 'Must be a positive integer or null if not needed.'
				,
				'minimum' => 1,
			],
			'limit' => [
				'type' => ['integer', 'null'],
				'description' =>
					'Number of task entries to return.'
					. ' Must be between ' . GetProjectTasksForAnalysisDto::LIMIT_MIN
					. ' and ' . GetProjectTasksForAnalysisDto::LIMIT_MAX . '.'
				,
				'minimum' => GetProjectTasksForAnalysisDto::LIMIT_MIN,
				'maximum' => GetProjectTasksForAnalysisDto::LIMIT_MAX,
				'default' => GetProjectTasksForAnalysisDto::LIMIT_DEFAULT,
			],
			'sort' => [
				'type' => 'string',
				'description' =>
					'Controls the order in which tasks are selected from the database '
					. 'and which then fill the page when the total number of matches '
					. 'exceeds "limit". Values: '
					. '"' . ProjectTasksForAnalysisSort::RecentlyChanged->value
					. '" — most recently updated first (default; best for "what changed" / overall status). '
					. '"' . ProjectTasksForAnalysisSort::Deadline->value
					. '" — closest deadline first (best for risk-of-missing-deadline analysis; '
					. 'tasks without a deadline are added at the end of the page if there is room). '
					. 'Omit the parameter to use the default ("recently_changed").'
				,
				'enum' => [...ProjectTasksForAnalysisSort::values()],
				'default' => ProjectTasksForAnalysisSort::RecentlyChanged->value,
			],
		];
	}
}
