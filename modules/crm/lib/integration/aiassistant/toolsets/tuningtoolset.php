<?php

namespace Bitrix\Crm\Integration\AiAssistant\ToolSets;

use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;
use Bitrix\AiAssistant\Definition\Dto\UsesToolsDto;
use Bitrix\AiAssistant\Definition\ToolSet\BaseToolSet;
use Bitrix\Crm\Integration\AiAssistant\Tools\CategoryChangeStageTool;
use Bitrix\Crm\Integration\AiAssistant\Tools\CategoryCreate;
use Bitrix\Crm\Integration\AiAssistant\Tools\CategoryCreateDeal;
use Bitrix\Crm\Integration\AiAssistant\Tools\CategoryCreateWithStages;
use Bitrix\Crm\Integration\AiAssistant\Tools\CategoryDeleteTool;
use Bitrix\Crm\Integration\AiAssistant\Tools\CategoryMoveItems;
use Bitrix\Crm\Integration\AiAssistant\Tools\CategoryRenameTool;
use Bitrix\Crm\Integration\AiAssistant\Tools\CategoryUpdateStagesList;
use Bitrix\Crm\Integration\AiAssistant\Tools\CreateUserFieldTool;
use Bitrix\Crm\Integration\AiAssistant\Tools\StageCreateTool;
use Bitrix\Crm\Integration\AiAssistant\Tools\StageDeleteTool;
use Bitrix\Crm\Integration\AiAssistant\Tools\StageRenameTool;

final class TuningToolSet extends BaseToolSet
{
	public function getCode(): string
	{
		return 'tuning';
	}

	public function getMetadata(): DefinitionMetadataDto
	{
		return new DefinitionMetadataDto(
			'CRM tuning tools',
			'Public CRM tuning tools',
		);
	}

	public function getUsesTools(): UsesToolsDto
	{
		return new UsesToolsDto([
			CategoryChangeStageTool::class,
			CategoryCreate::class,
			CategoryCreateDeal::class,
			CategoryCreateWithStages::class,
			CategoryDeleteTool::class,
			CategoryMoveItems::class,
			CategoryRenameTool::class,
			CategoryUpdateStagesList::class,
			CreateUserFieldTool::class,
			StageCreateTool::class,
			StageDeleteTool::class,
			StageRenameTool::class,
		]);
	}
}
