<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\SmartProcess\ConfigureSystemFields;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\BooleanProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\IntegerProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service\SmartProcessSettingsService;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class ConfigureSystemFieldsTool extends AbstractTool
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
			name: 'configure_smart_process_system_fields',
			description: 'Configures system field visibility for a CRM smart process (dynamic type).'
				. ' All flag parameters are optional; only provided ones are changed.'
				. ' Enabling the Client field automatically creates predefined relations'
				. ' with Contact and Company.',
		))
			->setProperties([
				(new IntegerProperty(
					'smartProcessId',
					'ENTITY_TYPE_ID of the smart process. Use search_dynamic_type to find it.',
				))
					->setIsRequired(true)
				,
				new BooleanProperty(
					'allowClient',
					'Show the Client field (contact and company binding).',
				),
				new BooleanProperty(
					'allowBeginEndDates',
					'Show the begin and end date fields.',
				),
				new BooleanProperty(
					'allowMyCompany',
					'Show the My Company field.',
				),
				new BooleanProperty(
					'allowSource',
					'Show the Source field.',
				),
				new BooleanProperty(
					'allowObservers',
					'Show the Observers field.',
				),
			])
		;
	}

	protected function getArgsDtoClass(): string
	{
		return ConfigureSystemFieldsToolDto::class;
	}

	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		/** @var ConfigureSystemFieldsToolDto $args */
		$smartProcessId = $args->smartProcessId;

		$resolved = $this->settingsService->resolveUpdatableType((int)$smartProcessId, $args->getUserId());
		if ($resolved instanceof ToolResult)
		{
			return $resolved;
		}
		$type = $resolved;

		$changed = false;

		if ($args->allowClient !== null)
		{
			$type->setIsClientEnabled($args->allowClient);
			$changed = true;
		}

		if ($args->allowBeginEndDates !== null)
		{
			$type->setIsBeginCloseDatesEnabled($args->allowBeginEndDates);
			$changed = true;
		}

		if ($args->allowMyCompany !== null)
		{
			$type->setIsMycompanyEnabled($args->allowMyCompany);
			$changed = true;
		}

		if ($args->allowSource !== null)
		{
			$type->setIsSourceEnabled($args->allowSource);
			$changed = true;
		}

		if ($args->allowObservers !== null)
		{
			$type->setIsObserversEnabled($args->allowObservers);
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
			allowClient: (bool)$type->getIsClientEnabled(),
			allowBeginEndDates: (bool)$type->getIsBeginCloseDatesEnabled(),
			allowMyCompany: (bool)$type->getIsMycompanyEnabled(),
			allowSource: (bool)$type->getIsSourceEnabled(),
			allowObservers: (bool)$type->getIsObserversEnabled(),
		);
	}
}
