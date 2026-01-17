<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\ToolSet;

use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;
use Bitrix\AiAssistant\Definition\Dto\UsesToolsDto;
use Bitrix\AiAssistant\Definition\ToolSet\BaseToolSet;
use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\Integration\AiAssistant\Service\Tool\SearchGroupsTool;
use Bitrix\Tasks\V2\FormV2Feature;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\AddReminderTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\AddResultTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\CheckList\CreateCheckListItemTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\CheckList\CreateCheckListTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\CheckList\DeleteCheckListItemTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\CheckList\DeleteCheckListTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\CheckList\UpdateCheckListItemTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\CheckList\UpdateCheckListTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Member\AddAccomplicesTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Member\AddAuditorsTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Member\AddCurrentUserAsAuditorTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Member\DeleteAccomplicesTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Member\DeleteAuditorsTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\SearchUsersTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\SendChatMessageTool;
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

class TasksToolSet extends BaseToolSet
{
	public function getCode(): string
	{
		return 'tasks';
	}

	public function getMetadata(): DefinitionMetadataDto
	{
		return new DefinitionMetadataDto(
			'Tasks Tool Set',
			'Public Tasks Tool Set',
		);
	}

	public function getUsesTools(): UsesToolsDto
	{
		$tools = [
			CreateCheckListItemTool::class,
			CreateCheckListTool::class,
			DeleteCheckListItemTool::class,
			DeleteCheckListTool::class,
			UpdateCheckListItemTool::class,
			UpdateCheckListTool::class,
			AddAccomplicesTool::class,
			AddAuditorsTool::class,
			AddCurrentUserAsAuditorTool::class,
			DeleteAccomplicesTool::class,
			DeleteAuditorsTool::class,
			SetDailyTaskRecurrenceTool::class,
			SetMonthlyByMonthDaysTaskRecurrenceTool::class,
			SetMonthlyByWeekDaysTaskRecurrenceTool::class,
			SetWeeklyTaskRecurrenceTool::class,
			SetYearlyByMonthDaysTaskRecurrenceTool::class,
			SetYearlyByWeekDaysTaskRecurrenceTool::class,
			ClearTaskDeadlineTool::class,
			CreateTaskTool::class,
			DeleteTaskTool::class,
			DetachTaskFromGroupTool::class,
			GetTaskByIdTool::class,
			SearchTasksTool::class,
			UpdateTaskTool::class,
			AddReminderTool::class,
			AddResultTool::class,
			SearchUsersTool::class,
		];

		if (FormV2Feature::isOn())
		{
			$tools[] = SendChatMessageTool::class;
		}

		if (Loader::includeModule('socialnetwork'))
		{
			$tools[] = SearchGroupsTool::class;
		}

		return new UsesToolsDto($tools);
	}
}
