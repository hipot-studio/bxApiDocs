<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AiAssistant\ToolSets;

use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;
use Bitrix\AiAssistant\Definition\Dto\UsesToolsDto;
use Bitrix\AiAssistant\Definition\ToolSet\BaseToolSet;
use Bitrix\Crm\Integration\AiAssistant\Tools\Search\CompanyListTool;
use Bitrix\Crm\Integration\AiAssistant\Tools\Search\ContactListTool;
use Bitrix\Crm\Integration\AiAssistant\Tools\Search\DealAmountTool;
use Bitrix\Crm\Integration\AiAssistant\Tools\Search\DealCategoryListTool;
use Bitrix\Crm\Integration\AiAssistant\Tools\Search\DealListTool;
use Bitrix\Crm\Integration\AiAssistant\Tools\Search\DealStageListTool;
use Bitrix\Crm\Integration\AiAssistant\Tools\Search\LeadAmountTool;
use Bitrix\Crm\Integration\AiAssistant\Tools\Search\LeadListTool;
use Bitrix\Crm\Integration\AiAssistant\Tools\Search\LeadStageListTool;
use Bitrix\Crm\Service\Container;
use CCrmOwnerType;

final class SearchToolSet extends BaseToolSet
{
	public function getCode(): string
	{
		return 'search';
	}

	public function getMetadata(): DefinitionMetadataDto
	{
		return new DefinitionMetadataDto(
			'CRM entity search tools',
			'Public CRM search tools for MCP. Use these tools to search for leads, deals, contacts, and companies.'
		);
	}

	public function canRun(int $userId): bool
	{
		$entityTypePermission = Container::getInstance()->getUserPermissions($userId)->entityType();

		return $entityTypePermission->canReadItems(CCrmOwnerType::Lead)
			|| $entityTypePermission->canReadItems(CCrmOwnerType::Deal)
			|| $entityTypePermission->canReadItems(CCrmOwnerType::Contact)
			|| $entityTypePermission->canReadItems(CCrmOwnerType::Company)
		;
	}

	public function getUsesTools(): UsesToolsDto
	{
		return new UsesToolsDto([
			CompanyListTool::class,
			ContactListTool::class,
			DealAmountTool::class,
			DealCategoryListTool::class,
			DealListTool::class,
			DealStageListTool::class,
			LeadAmountTool::class,
			LeadListTool::class,
			LeadStageListTool::class,
		]);
	}
}
