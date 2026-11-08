<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\ToolSet;

use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;
use Bitrix\AiAssistant\Definition\Dto\UsesToolsDto;
use Bitrix\AiAssistant\Definition\ToolSet\BaseToolSet;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Operation\AgentOperationsDescription;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\CreateDatasetFromSqlTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\CreateVirtualDatasetTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\DeleteDatasetTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\GetDatasetTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\ListDatasetsTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\UpdateDatasetTool;

final class BiDatasetsToolSet extends BaseToolSet
{
	public function getCode(): string
	{
		return 'bi_datasets';
	}

	public function getMetadata(): DefinitionMetadataDto
	{
		$preamble = 'Tools for managing virtual datasets in BI Constructor (Apache Superset). '
			. 'A virtual dataset stands either on a physical BI table or on a raw SQL SELECT, and '
			. 'charts are built on top of it. '
			. 'Destructive operations require explicit two-step user confirmation.';

		return new DefinitionMetadataDto(
			'BI Datasets',
			$preamble . (new AgentOperationsDescription())->getForToolSet($this->getCode()),
		);
	}

	public function getUsesTools(): UsesToolsDto
	{
		return new UsesToolsDto([
			ListDatasetsTool::class,
			GetDatasetTool::class,
			CreateVirtualDatasetTool::class,
			CreateDatasetFromSqlTool::class,
			UpdateDatasetTool::class,
			DeleteDatasetTool::class,
		]);
	}

	public function getAdditionalRequiredModules(): array
	{
		return ['biconnector'];
	}
}
