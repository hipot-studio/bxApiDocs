<?php

namespace Bitrix\HumanResources\Integration\AiAssistant\ToolSets;

use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;
use Bitrix\AiAssistant\Definition\Dto\UsesToolsDto;
use Bitrix\AiAssistant\Definition\ToolSet\BaseToolSet;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\CompanyStructure\GetTotalEmployeeCountTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\CompanyStructure\GetTotalNodeCountTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\CompanyStructure\SearchEmployeeTool;

class CompanyStructureToolSet extends BaseToolSet
{
	public function getCode(): string
	{
		return 'company_structure';
	}

	public function getMetadata(): DefinitionMetadataDto
	{
		return new DefinitionMetadataDto(
			'Humanresources company structure tools',
			'Public tools for company-wide employee search and structure overview from HR module',
		);
	}

	public function getUsesTools(): UsesToolsDto
	{
		return new UsesToolsDto([
			SearchEmployeeTool::class,
			GetTotalEmployeeCountTool::class,
			GetTotalNodeCountTool::class,
		]);
	}
}
