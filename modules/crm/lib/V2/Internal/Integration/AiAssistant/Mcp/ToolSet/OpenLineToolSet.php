<?php

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\ToolSet;

use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;
use Bitrix\AiAssistant\Definition\Dto\UsesToolsDto;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\OpenLines\Agent\AddDealUserFieldValueFromOpenLine\AddDealUserFieldValueFromOpenLineTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\OpenLines\Agent\AddLeadUserFieldValueFromOpenLine\AddLeadUserFieldValueFromOpenLineTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\OpenLines\Agent\CreateDealCommentFromOpenLine\CreateDealCommentFromOpenLineTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\OpenLines\Agent\CreateDealToDoFromOpenLine\CreateDealToDoFromOpenLineTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\OpenLines\Agent\CreateLeadCommentFromOpenLine\CreateLeadCommentFromOpenLineTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\OpenLines\Agent\CreateLeadToDoFromOpenLine\CreateLeadToDoFromOpenLineTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\OpenLines\Agent\ListDealUserFieldForOpenLine\ListDealUserFieldForOpenLineTool;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\OpenLines\Agent\ListLeadUserFieldForOpenLine\ListLeadUserFieldForOpenLineTool;
use Bitrix\ImOpenLines\V2\Feature\AiOpenLinesOperatorAgentFeature;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

final class OpenLineToolSet extends AbstractToolSet
{
	public function canList(int $userId): bool
	{
		return $this->isEnabled();
	}

	public function canRun(int $userId): bool
	{
		return $this->isEnabled();
	}

	private function isEnabled(): bool
	{
		return Loader::includeModule('imopenlines')
			&& class_exists(AiOpenLinesOperatorAgentFeature::class)
			&& ServiceLocator::getInstance()->get(AiOpenLinesOperatorAgentFeature::class)?->isAvailable()
		;
	}

	public function getCode(): string
	{
		return 'openline';
	}

	public function getMetadata(): DefinitionMetadataDto
	{
		return new DefinitionMetadataDto(
			'CRM openlines tools',
			'Public CRM open lines tools for MCP. Use these tools to work with external users in open lines',
		);
	}

	public function getUsesTools(): UsesToolsDto
	{
		return new UsesToolsDto([
			CreateLeadToDoFromOpenLineTool::class,
			CreateLeadCommentFromOpenLineTool::class,
			ListLeadUserFieldForOpenLineTool::class,
			AddLeadUserFieldValueFromOpenLineTool::class,
			CreateDealToDoFromOpenLineTool::class,
			CreateDealCommentFromOpenLineTool::class,
			ListDealUserFieldForOpenLineTool::class,
			AddDealUserFieldValueFromOpenLineTool::class,
		]);
	}
}
