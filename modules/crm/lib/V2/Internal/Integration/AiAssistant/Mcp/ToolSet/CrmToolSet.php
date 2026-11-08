<?php

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\ToolSet;

use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;
use Bitrix\AiAssistant\Definition\Dto\UsesToolsDto;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AutomatedSolution\CreateAutomatedSolution\CreateAutomatedSolutionTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AutomatedSolution\ListAutomatedSolutions\ListAutomatedSolutionsTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\EntityType\ListEntityTypes\ListEntityTypesTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\Category\CreateCategory\CreateCategoryTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\Category\DeleteCategory\DeleteCategoryTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\Category\ListCategories\ListCategoriesTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\Category\RenameCategory\RenameCategoryTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\Category\Stage\CreateStage\CreateStageTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\Category\Stage\DeleteStage\DeleteStageTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\Category\Stage\ListStages\ListStagesTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\Category\Stage\RenameStage\RenameStageTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\Category\Stage\SetStageList\SetStageListTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\CreateItem\CreateItemTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\GetItemsAmountByStage\GetItemsAmountByStageTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\ListItems\ListItemsTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\ListItemUserFields\ListItemUserFieldsTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\MoveItemsBetweenCategories\MoveItemsBetweenCategoriesTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\MoveItemsBetweenStages\MoveItemsBetweenStagesTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\PageLayout\CreatePageLayout\CreatePageLayoutTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\PageLayout\GetCurrentPageLayout\GetCurrentPageLayoutTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\PageLayout\ListPageLayoutElementsEnum\ListPageLayoutElementsEnumTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\PageLayout\ListPageLayouts\ListPageLayoutTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\PageLayout\SwitchCurrentPageLayout\SwitchCurrentPageLayoutTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\PageLayout\SwitchUsersPageLayout\SwitchUsersPageLayoutTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\PageLayout\UpdatePageLayout\UpdatePageLayoutTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\Timeline\CreateComment\CreateCommentTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\Timeline\CreateToDo\CreateToDoTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\UpdateItemUserFieldValue\UpdateItemUserFieldValueTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\UserField\CreateUserField\CreateUserFieldTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\SmartProcess\CreateSmartProcess\CreateSmartProcessTool;

final class CrmToolSet extends AbstractToolSet
{
	public function getCode(): string
	{
		return 'crm';
	}

	public function getMetadata(): DefinitionMetadataDto
	{
		return new DefinitionMetadataDto(
			name: 'CRM Public Toolset',
			description: 'Tools for working with CRM entities',
		);
	}

	public function getUsesTools(): UsesToolsDto
	{
		return new UsesToolsDto([
			// automated solution
			CreateAutomatedSolutionTool::class,
			ListAutomatedSolutionsTool::class,

			// entity type
			ListEntityTypesTool::class,

			// item
			CreateItemTool::class,
			GetItemsAmountByStageTool::class,
			ListItemsTool::class,
			ListItemUserFieldsTool::class,
			MoveItemsBetweenCategoriesTool::class,
			MoveItemsBetweenStagesTool::class,
			UpdateItemUserFieldValueTool::class,

			// card view
			ListPageLayoutTool::class,
			GetCurrentPageLayoutTool::class,
			CreatePageLayoutTool::class,
			UpdatePageLayoutTool::class,
			ListPageLayoutElementsEnumTool::class,
			SwitchCurrentPageLayoutTool::class,
			SwitchUsersPageLayoutTool::class,

			// category
			CreateCategoryTool::class,
			DeleteCategoryTool::class,
			ListCategoriesTool::class,
			RenameCategoryTool::class,

			// stages
			CreateStageTool::class,
			DeleteStageTool::class,
			ListStagesTool::class,
			RenameStageTool::class,
			SetStageListTool::class,

			// timeline
			CreateCommentTool::class,
			CreateToDoTool::class,

			// userfields
			CreateUserFieldTool::class,

			// smart process
			CreateSmartProcessTool::class,
		]);
	}
}
