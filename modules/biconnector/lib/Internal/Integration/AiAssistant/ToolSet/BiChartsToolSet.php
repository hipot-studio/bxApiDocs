<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\ToolSet;

use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;
use Bitrix\AiAssistant\Definition\Dto\UsesToolsDto;
use Bitrix\AiAssistant\Definition\ToolSet\BaseToolSet;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Operation\AgentOperationsDescription;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\CreateChartTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\DeleteChartTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\GetChartTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\ListChartsTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\ReplaceChartConfigTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\UpdateChartMetadataTool;

final class BiChartsToolSet extends BaseToolSet
{
	public function getCode(): string
	{
		return 'bi_charts';
	}

	public function getMetadata(): DefinitionMetadataDto
	{
		$preamble = 'Tools for managing charts in BI Constructor (Apache Superset). '
			. 'Charts are built on top of datasets (bar, line, scatter, pie, table, big_number). '
			. 'Destructive operations require explicit two-step user confirmation.';

		return new DefinitionMetadataDto(
			'BI Charts',
			$preamble . (new AgentOperationsDescription())->getForToolSet($this->getCode()),
		);
	}

	public function getUsesTools(): UsesToolsDto
	{
		return new UsesToolsDto([
			ListChartsTool::class,
			GetChartTool::class,
			CreateChartTool::class,
			UpdateChartMetadataTool::class,
			ReplaceChartConfigTool::class,
			DeleteChartTool::class,
		]);
	}

	public function getAdditionalRequiredModules(): array
	{
		return ['biconnector'];
	}
}
