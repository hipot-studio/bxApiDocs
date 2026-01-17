<?php

namespace Bitrix\HumanResources\Integration\AiAssistant\ToolSets;

use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;
use Bitrix\AiAssistant\Definition\Dto\UsesToolsDto;
use Bitrix\AiAssistant\Definition\ToolSet\BaseToolSet;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Team\TeamChangeParentTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Team\TeamCreateTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Team\TeamEditEmployeesTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Team\TeamEditTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Team\TeamGetChildrenTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Team\TeamGetCommunicationsTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Team\TeamMoveEmployeesTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Team\TeamSaveCommunicationsTool;
use Bitrix\HumanResources\Integration\AiAssistant\Tools\Team\TeamShowTool;

class TeamToolSet extends BaseToolSet
{
	public function getCode(): string
	{
		return 'team';
	}

	public function getMetadata(): DefinitionMetadataDto
	{
		return new DefinitionMetadataDto(
			'Humanresources team tools',
			'Public tools for working with teams from HR module',
		);
	}

	public function getUsesTools(): UsesToolsDto
	{
		return new UsesToolsDto([
			TeamCreateTool::class,
			TeamChangeParentTool::class,
			TeamEditEmployeesTool::class,
			TeamEditTool::class,
			TeamMoveEmployeesTool::class,
			TeamSaveCommunicationsTool::class,
			TeamShowTool::class,
			TeamGetChildrenTool::class,
			TeamGetCommunicationsTool::class,
		]);
	}
}
