<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\SmartProcess\GetSettings;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\UserField\UserFieldManager;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\IntegerProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class GetSettingsTool extends AbstractTool
{
	public function canList(int $userId): bool
	{
		return true;
	}

	public function canRun(int $userId): bool
	{
		return true;
	}

	protected function getDefinition(): ToolDefinition
	{
		return (new ToolDefinition(
			name: 'get_smart_process_settings',
			description: 'Returns the current settings of a CRM smart process (dynamic type).'
				. ' Use search_dynamic_type to find the smartProcessId (ENTITY_TYPE_ID) first.',
		))
			->setProperties([
				(new IntegerProperty(
					'smartProcessId',
					'ENTITY_TYPE_ID of the smart process. Use search_dynamic_type to find it.',
				))
					->setIsRequired(true)
				,
			])
		;
	}

	protected function getArgsDtoClass(): string
	{
		return GetSettingsToolDto::class;
	}

	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		/** @var GetSettingsToolDto $args */
		$smartProcessId = $args->smartProcessId;

		$type = Container::getInstance()->getTypeByEntityTypeId($smartProcessId);
		if ($type === null)
		{
			return ToolResult::fail(
				"Smart process with ENTITY_TYPE_ID={$smartProcessId} was not found.",
			);
		}

		$canUpdate = Container::getInstance()
			->getUserPermissions($args->getUserId())
			->dynamicType()
			->canUpdate($smartProcessId)
		;
		if (!$canUpdate)
		{
			return ToolResult::fail(
				"Access denied: you do not have permission to manage smart process"
					. " with ENTITY_TYPE_ID={$smartProcessId}.",
			);
		}

		$entityTypeName = \CCrmOwnerType::ResolveName($smartProcessId);

		$automatedSolutionId = $type->getCustomSectionId();

		return ToolResult::success(
			smartProcessId: $type->getEntityTypeId(),
			title: (string)$type->getTitle(),
			automatedSolutionId: $automatedSolutionId > 0 ? $automatedSolutionId : null,
			allowRobotsAndTriggers: (bool)$type->getIsAutomationEnabled(),
			allowBusinessProcesses: (bool)$type->getIsBizProcEnabled(),
			allowPipelines: (bool)$type->getIsCategoriesEnabled(),
			allowStagesAndKanban: (bool)$type->getIsStagesEnabled(),
			allowClient: (bool)$type->getIsClientEnabled(),
			allowBeginEndDates: (bool)$type->getIsBeginCloseDatesEnabled(),
			allowMyCompany: (bool)$type->getIsMycompanyEnabled(),
			allowSource: (bool)$type->getIsSourceEnabled(),
			allowObservers: (bool)$type->getIsObserversEnabled(),
			allowCounters: (bool)$type->getIsCountersEnabled(),
			allowDocumentsGenerator: (bool)$type->getIsDocumentsEnabled(),
			allowProducts: (bool)$type->getIsLinkWithProductsEnabled(),
			allowRecyclebin: (bool)$type->getIsRecyclebinEnabled(),
			allowRecurring: (bool)$type->getIsRecurringEnabled(),
			allowInCrmField: (bool)$type->getIsUseInUserfieldEnabled(),
			allowInTasks: UserFieldManager::isEnabledInTasksUserField($entityTypeName),
			allowInCalendar: UserFieldManager::isEnabledInCalendarUserField($entityTypeName),
		);
	}
}
