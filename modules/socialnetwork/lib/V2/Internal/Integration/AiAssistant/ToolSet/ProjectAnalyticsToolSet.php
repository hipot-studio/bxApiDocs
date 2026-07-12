<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\AiAssistant\ToolSet;

use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;
use Bitrix\AiAssistant\Definition\Dto\UsesToolsDto;
use Bitrix\AiAssistant\Definition\ToolSet\BaseToolSet;
use Bitrix\Socialnetwork\V2\Internal\Integration\AiAssistant\Tool\FindProjectByNameTool;
use Bitrix\Socialnetwork\V2\Internal\Integration\AiAssistant\Tool\GetProjectForAnalysisTool;

class ProjectAnalyticsToolSet extends BaseToolSet
{
	public function getCode(): string
	{
		return 'project_analytics';
	}

	public function getMetadata(): DefinitionMetadataDto
	{
		return new DefinitionMetadataDto(
			'Project Analytics Tool Set',
			'Public Tool Set for project analysis',
		);
	}

	public function getUsesTools(): UsesToolsDto
	{
		$tools = [
			FindProjectByNameTool::class,
			GetProjectForAnalysisTool::class,
		];

		return new UsesToolsDto($tools);
	}
}
