<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\ToolSet;

use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;
use Bitrix\AiAssistant\Definition\Dto\UsesToolsDto;
use Bitrix\AiAssistant\Definition\ToolSet\BaseToolSet;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Operation\AgentOperationsDescription;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\CreateDashboardTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\DeleteDashboardTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\GetChartDataTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\GetDashboardMetaTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\GetFilterValuesTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\SearchDashboardParametersTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\ListDashboardsTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\PublishDashboardTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\SetDashboardContentTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\UnpublishDashboardTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\UpdateDashboardMetadataTool;

final class BiDashboardsToolSet extends BaseToolSet
{
	public function getCode(): string
	{
		return 'bi_dashboards';
	}

	public function getMetadata(): DefinitionMetadataDto
	{
		$preamble = 'Tools for working with BI Constructor dashboards (Apache Superset) — saved reports '
			. 'built over tasks, deals, calls and other entities. '
			. 'Prefer these tools for any analytical or reporting question about an entity across '
			. 'many records — summaries, top/bottom-N, distributions, anomalies, trends over time, '
			. 'and group-by aggregates over a period (e.g. "how many tasks did the team close this '
			. 'year") — and for analytical follow-ups even when the entity changes (e.g. "now the '
			. 'same for deals"). '
			. 'Destructive operations require explicit two-step user confirmation. '
			. 'Not for creating or editing CRM entities, acting on one specific record, or '
			. 'managing access — those stay with the entity-specific tools.';

		return new DefinitionMetadataDto(
			'BI Dashboards',
			$preamble . (new AgentOperationsDescription())->getForToolSet($this->getCode()),
		);
	}

	public function getUsesTools(): UsesToolsDto
	{
		return new UsesToolsDto([
			ListDashboardsTool::class,
			SearchDashboardParametersTool::class,
			GetDashboardMetaTool::class,
			GetChartDataTool::class,
			GetFilterValuesTool::class,
			CreateDashboardTool::class,
			UpdateDashboardMetadataTool::class,
			SetDashboardContentTool::class,
			PublishDashboardTool::class,
			UnpublishDashboardTool::class,
			DeleteDashboardTool::class,
		]);
	}

	public function getAdditionalRequiredModules(): array
	{
		return ['biconnector'];
	}
}
