<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\ToolSet;

use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;
use Bitrix\AiAssistant\Definition\Dto\UsesToolsDto;
use Bitrix\AiAssistant\Definition\ToolSet\BaseToolSet;
use Bitrix\AiAssistant\Definition\ToolSet\Contract\ToolProviderDefinitionInterface;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Operation\AgentOperationsDescription;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\ExecuteSqlTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\GetBiGuideTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\GetBiTableSchemaTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\ListBiTablesTool;

final class BiTablesToolSet extends BaseToolSet implements ToolProviderDefinitionInterface
{
	use BuilderPairToolProvider;

	public function getCode(): string
	{
		return 'bi_tables';
	}

	public function getMetadata(): DefinitionMetadataDto
	{
		$preamble = 'Tools for exploring the BI database (Apache Superset) and running read-only SQL. '
			. 'Use these tools to discover tables and their columns before creating virtual datasets, '
			. 'and to run ad-hoc SELECT queries through SQL Lab to get data for analysis.';

		return new DefinitionMetadataDto(
			'BI Tables',
			$preamble . (new AgentOperationsDescription())->getForToolSet($this->getCode()),
		);
	}

	public function getUsesTools(): UsesToolsDto
	{
		return new UsesToolsDto([
			ListBiTablesTool::class,
			GetBiTableSchemaTool::class,
			ExecuteSqlTool::class,
			GetBiGuideTool::class,
		]);
	}

	public function getAdditionalRequiredModules(): array
	{
		return ['biconnector'];
	}
}
