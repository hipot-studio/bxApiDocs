<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\SmartProcess\ConfigurePipeline;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\BooleanProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\IntegerProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\ToolDefinition;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Result\ToolResult;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service\SmartProcessSettingsService;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class ConfigurePipelineTool extends AbstractTool
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
			name: 'configure_smart_process_pipeline',
			description: 'Configures pipeline and kanban settings of a CRM smart process (dynamic type).'
				. ' All flag parameters are optional; only provided ones are changed.'
				. ' Pipelines cannot be disabled while the smart process has more than one pipeline'
				. ' (funnel); the model returns an error in that case.',
		))
			->setProperties([
				(new IntegerProperty(
					'smartProcessId',
					'ENTITY_TYPE_ID of the smart process. Use search_dynamic_type to find it.',
				))
					->setIsRequired(true)
				,
				new BooleanProperty(
					'allowPipelines',
					'Allow using multiple pipelines (funnels) in the smart process.',
				),
				new BooleanProperty(
					'allowStagesAndKanban',
					'Allow using stages and kanban board in the smart process.',
				),
			])
		;
	}

	protected function getArgsDtoClass(): string
	{
		return ConfigurePipelineToolDto::class;
	}

	protected function internalExecute(AbstractToolDto $args): ToolResult
	{
		/** @var ConfigurePipelineToolDto $args */
		$smartProcessId = $args->smartProcessId;

		$resolved = $this->settingsService->resolveUpdatableType((int)$smartProcessId, $args->getUserId());
		if ($resolved instanceof ToolResult)
		{
			return $resolved;
		}
		$type = $resolved;

		$changed = false;

		if ($args->allowPipelines !== null)
		{
			$type->setIsCategoriesEnabled($args->allowPipelines);
			$changed = true;
		}

		if ($args->allowStagesAndKanban !== null)
		{
			$type->setIsStagesEnabled($args->allowStagesAndKanban);
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
			allowPipelines: (bool)$type->getIsCategoriesEnabled(),
			allowStagesAndKanban: (bool)$type->getIsStagesEnabled(),
		);
	}
}
