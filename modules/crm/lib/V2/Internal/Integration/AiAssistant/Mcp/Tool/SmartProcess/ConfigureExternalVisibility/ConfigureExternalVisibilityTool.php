<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\SmartProcess\ConfigureExternalVisibility;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Crm\Integration\Calendar;
use Bitrix\Crm\Integration\TaskManager;
use Bitrix\Crm\UserField\UserFieldManager;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\BooleanProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\IntegerProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service\SmartProcessSettingsService;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class ConfigureExternalVisibilityTool extends AbstractTool
{
	private readonly SmartProcessSettingsService $settingsService;

	public function __construct(
		TracedLogger $tracedLogger,
		?SmartProcessSettingsService $settingsService = null,
	)
	{
		parent::__construct($tracedLogger);
		$this->settingsService = $settingsService ?? new SmartProcessSettingsService();
	}

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
			name: 'configure_smart_process_external_visibility',
			description: 'Controls visibility of the smart process in the CRM binding field'
				. ' of tasks (and task templates), calendar events and custom user fields.'
				. ' All flag parameters are optional; only provided ones are changed.',
		))
			->setProperties([
				(new IntegerProperty(
					'smartProcessId',
					'ENTITY_TYPE_ID of the smart process. Use search_dynamic_type to find it.',
				))
					->setIsRequired(true)
				,
				new BooleanProperty(
					'allowInCrmField',
					'Allow this smart process to appear in CRM binding user fields.',
				),
				new BooleanProperty(
					'allowInTasks',
					'Allow this smart process to appear in the CRM binding field'
						. ' of tasks and task templates.',
				),
				new BooleanProperty(
					'allowInCalendar',
					'Allow this smart process to appear in the CRM binding field'
						. ' of calendar events.',
				),
			])
		;
	}

	protected function getArgsDtoClass(): string
	{
		return ConfigureExternalVisibilityToolDto::class;
	}

	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		/** @var ConfigureExternalVisibilityToolDto $args */
		$smartProcessId = $args->smartProcessId;

		$resolved = $this->settingsService->resolveUpdatableType((int)$smartProcessId, $args->getUserId());
		if ($resolved instanceof ToolResult)
		{
			return $resolved;
		}
		$type = $resolved;

		$changed = false;
		$modelChanged = false;

		if ($args->allowInCrmField !== null)
		{
			$type->setIsUseInUserfieldEnabled($args->allowInCrmField);
			$modelChanged = true;
			$changed = true;
		}

		$linkedFieldsChanged = (
			$args->allowInTasks !== null
			|| $args->allowInCalendar !== null
		);

		if ($linkedFieldsChanged)
		{
			$changed = true;
		}

		if (!$changed)
		{
			return ToolResult::success(
				smartProcessId: $smartProcessId,
				message: 'Nothing to change: no settings were provided.',
			);
		}

		$entityTypeName = \CCrmOwnerType::ResolveName($type->getEntityTypeId());

		if ($modelChanged)
		{
			$result = $type->save();
			if (!$result->isSuccess())
			{
				$messages = implode('; ', $result->getErrorMessages());

				return ToolResult::fail("Failed to configure smart process: {$messages}");
			}
		}

		if ($linkedFieldsChanged)
		{
			$userFieldsMap = UserFieldManager::getLinkedUserFieldsMap();

			if ($args->allowInTasks !== null)
			{
				$taskKey = UserFieldManager::combineUserFieldFieldsToString(
					TaskManager::TASK_USER_FIELD_ENTITY_ID,
					TaskManager::TASK_FIELD_NAME,
				);
				if (isset($userFieldsMap[$taskKey]))
				{
					UserFieldManager::enableEntityInUserField(
						$userFieldsMap[$taskKey],
						$entityTypeName,
						$args->allowInTasks,
					);
				}

				$taskTemplateKey = UserFieldManager::combineUserFieldFieldsToString(
					TaskManager::TASK_TEMPLATE_USER_FIELD_ENTITY_ID,
					TaskManager::TASK_FIELD_NAME,
				);
				if (isset($userFieldsMap[$taskTemplateKey]))
				{
					UserFieldManager::enableEntityInUserField(
						$userFieldsMap[$taskTemplateKey],
						$entityTypeName,
						$args->allowInTasks,
					);
				}
			}

			if ($args->allowInCalendar !== null)
			{
				$calendarKey = UserFieldManager::combineUserFieldFieldsToString(
					Calendar::USER_FIELD_ENTITY_ID,
					Calendar::EVENT_FIELD_NAME,
				);
				if (isset($userFieldsMap[$calendarKey]))
				{
					UserFieldManager::enableEntityInUserField(
						$userFieldsMap[$calendarKey],
						$entityTypeName,
						$args->allowInCalendar,
					);
				}
			}
		}

		return ToolResult::success(
			smartProcessId: $type->getEntityTypeId(),
			allowInCrmField: (bool)$type->getIsUseInUserfieldEnabled(),
			allowInTasks: UserFieldManager::isEnabledInTasksUserField($entityTypeName),
			allowInCalendar: UserFieldManager::isEnabledInCalendarUserField($entityTypeName),
		);
	}
}
