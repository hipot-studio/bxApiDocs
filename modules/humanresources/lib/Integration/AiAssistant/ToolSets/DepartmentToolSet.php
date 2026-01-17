<?php

namespace Bitrix\HumanResources\Integration\AiAssistant\ToolSets;

use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;
use Bitrix\AiAssistant\Definition\Dto\UsesToolsDto;
use Bitrix\AiAssistant\Definition\ToolSet\BaseToolSet;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Department\DepartmentChangeParentTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Department\DepartmentCreateTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Department\DepartmentEditEmployeesTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Department\DepartmentEditTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Department\DepartmentGetChildrenTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Department\DepartmentGetCommunicationsTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Department\DepartmentMoveEmployeesTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Department\DepartmentSaveCommunicationsTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Department\DepartmentShowTool;

class DepartmentToolSet extends BaseToolSet
{
	public function getCode(): string
	{
		return 'department';
	}

	public function getMetadata(): DefinitionMetadataDto
	{
		return new DefinitionMetadataDto(
			'Humanresources department tools',
			'Public tools for working with departments from HR module',
		);
	}

	public function getUsesTools(): UsesToolsDto
	{
		return new UsesToolsDto([
			DepartmentCreateTool::class,
			DepartmentChangeParentTool::class,
			DepartmentEditEmployeesTool::class,
			DepartmentEditTool::class,
			DepartmentMoveEmployeesTool::class,
			DepartmentSaveCommunicationsTool::class,
			DepartmentShowTool::class,
			DepartmentGetChildrenTool::class,
			DepartmentGetCommunicationsTool::class,
		]);
	}
}