<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\SmartProcess\ConfigureAutomatization;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\BooleanProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\IntegerProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service\SmartProcessSettingsService;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class ConfigureAutomatizationTool extends AbstractTool
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
			name: 'configure_smart_process_automatization',
			description: 'Configures automatization settings of a CRM smart process (dynamic type).'
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
					'allowRobotsAndTriggers',
					'Allow using robots and triggers in the smart process.',
				),
				new BooleanProperty(
					'allowBusinessProcesses',
					'Allow using the business process designer.',
				),
			])
		;
	}

	protected function getArgsDtoClass(): string
	{
		return ConfigureAutomatizationToolDto::class;
	}

	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		/** @var ConfigureAutomatizationToolDto $args */
		$smartProcessId = $args->smartProcessId;

		$resolved = $this->settingsService->resolveUpdatableType((int)$smartProcessId, $args->getUserId());
		if ($resolved instanceof ToolResult)
		{
			return $resolved;
		}
		$type = $resolved;

		$changed = false;

		if ($args->allowRobotsAndTriggers !== null)
		{
			$type->setIsAutomationEnabled($args->allowRobotsAndTriggers);
			$changed = true;
		}

		if ($args->allowBusinessProcesses !== null)
		{
			$type->setIsBizProcEnabled($args->allowBusinessProcesses);
			$changed = true;
		}

		if (!$changed)
		{
			return ToolResult::success(
				smartProcessId: $smartProcessId,
				message: 'Nothing to change: no settings were provided.',
			);
		}

		$result = $type->save();
		if (!$result->isSuccess())
		{
			$messages = implode('; ', $result->getErrorMessages());

			return ToolResult::fail("Failed to configure smart process: {$messages}");
		}

		return ToolResult::success(
			smartProcessId: $type->getEntityTypeId(),
			allowRobotsAndTriggers: (bool)$type->getIsAutomationEnabled(),
			allowBusinessProcesses: (bool)$type->getIsBizProcEnabled(),
		);
	}
}
