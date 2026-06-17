<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\ToolSets;

use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;
use Bitrix\AiAssistant\Definition\Dto\UsesToolsDto;
use Bitrix\AiAssistant\Definition\ToolSet\BaseToolSet;
use Bitrix\Intranet\Internal\Integration\AiAssistant\Tools\Department\SearchDepartmentsTool;

class DepartmentToolSet extends BaseToolSet
{
	public function getCode(): string
	{
		return 'department';
	}

	public function getMetadata(): DefinitionMetadataDto
	{
		return new DefinitionMetadataDto(
			'Department Tool Set',
			'Public tool set for resolving invitation-eligible departments before invite actions.',
		);
	}

	public function getUsesTools(): UsesToolsDto
	{
		return new UsesToolsDto([
			SearchDepartmentsTool::class,
		]);
	}
}
