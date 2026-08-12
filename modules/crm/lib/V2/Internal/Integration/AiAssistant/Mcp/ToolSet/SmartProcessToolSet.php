<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\ToolSet;

use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;
use Bitrix\AiAssistant\Definition\Dto\UsesToolsDto;
use Bitrix\AiAssistant\Definition\ToolSet\BaseToolSet;
use Bitrix\Crm\Integration\AiAssistant\Tools\AutomatedSolution\CreateAutomatedSolutionTool;
use Bitrix\Crm\Integration\AiAssistant\Tools\AutomatedSolution\SearchAutomatedSolutionTool;
use Bitrix\Crm\Integration\AiAssistant\Tools\DynamicType\CreateDynamicTypeTool;
use Bitrix\Crm\Integration\AiAssistant\Tools\DynamicType\SearchDynamicTypeTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AutomatedSolution;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\SmartProcess;

final class SmartProcessToolSet extends BaseToolSet
{
	public function getCode(): string
	{
		return 'smart_process';
	}

	public function getMetadata(): DefinitionMetadataDto
	{
		return new DefinitionMetadataDto(
			'CRM smart process tools',
			'Tools for configuring smart processes (dynamic types) and automated solutions'
				. ' (workplaces). Use these tools to read and manage settings of CRM smart processes.',
		);
	}

	public function getUsesTools(): UsesToolsDto
	{
		return new UsesToolsDto([
			SearchAutomatedSolutionTool::class,
			CreateAutomatedSolutionTool::class,
			SearchDynamicTypeTool::class,
			CreateDynamicTypeTool::class,
			SmartProcess\GetSettings\GetSettingsTool::class,
			AutomatedSolution\Update\UpdateTool::class,
			SmartProcess\Update\UpdateTool::class,
			SmartProcess\ConfigureAutomatedSolution\ConfigureAutomatedSolutionTool::class,
			SmartProcess\ConfigureAutomatization\ConfigureAutomatizationTool::class,
			SmartProcess\ConfigurePipeline\ConfigurePipelineTool::class,
			SmartProcess\ConfigureSystemFields\ConfigureSystemFieldsTool::class,
			SmartProcess\ToggleCounters\ToggleCountersTool::class,
			SmartProcess\ToggleDocumentsGenerator\ToggleDocumentsGeneratorTool::class,
			SmartProcess\ToggleProducts\ToggleProductsTool::class,
			SmartProcess\ToggleRecyclebin\ToggleRecyclebinTool::class,
			SmartProcess\ToggleRecurring\ToggleRecurringTool::class,
			SmartProcess\ConfigureExternalVisibility\ConfigureExternalVisibilityTool::class,
			SmartProcess\AddRelations\AddRelationsTool::class,
			SmartProcess\RemoveRelations\RemoveRelationsTool::class,
			SmartProcess\ConfigureRelations\ConfigureRelationsTool::class,
			SmartProcess\GetRelations\GetRelationsTool::class,
		]);
	}
}
