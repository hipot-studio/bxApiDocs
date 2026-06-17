<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\ToolSet;

use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;
use Bitrix\AiAssistant\Definition\Dto\UsesToolsDto;
use Bitrix\AiAssistant\Definition\ToolSet\BaseToolSet;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\GetChartDataTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\GetDashboardMetaTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\GetFilterValuesTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\ListDashboardsTool;

final class BiDashboardsToolSet extends BaseToolSet
{
	public function getCode(): string
	{
		return 'bi_dashboards';
	}

	public function getMetadata(): DefinitionMetadataDto
	{
		return new DefinitionMetadataDto(
			'BI Dashboards',
			'Tools for working with BI Constructor dashboards (Apache Superset). '
			. 'Use these tools to find dashboards by name/description, '
			. 'and to fetch dashboard data with filters for analysis.',
		);
	}

	public function getUsesTools(): UsesToolsDto
	{
		return new UsesToolsDto([
			ListDashboardsTool::class,
			GetDashboardMetaTool::class,
			GetChartDataTool::class,
			GetFilterValuesTool::class,
		]);
	}

	public function getAdditionalRequiredModules(): array
	{
		return ['biconnector'];
	}
}
