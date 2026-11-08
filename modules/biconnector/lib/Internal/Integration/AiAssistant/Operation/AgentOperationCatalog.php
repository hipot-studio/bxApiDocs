<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Operation;

use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\CreateChartTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\CreateDashboardTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\CreateDatasetFromSqlTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\CreateVirtualDatasetTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\DeleteChartTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\DeleteDashboardTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\DeleteDatasetTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\ExecuteSqlTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\GetBiTableSchemaTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\GetChartDataTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\GetChartTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\GetDashboardMetaTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\GetDatasetTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\GetFilterValuesTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\ListBiTablesTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\ListChartsTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\ListDashboardsTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\ListDatasetsTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\PublishDashboardTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\ReplaceChartConfigTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\SearchDashboardParametersTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\SetDashboardContentTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\UnpublishDashboardTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\UpdateChartMetadataTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\UpdateDashboardMetadataTool;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool\UpdateDatasetTool;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Everything the four BI tool sets let an agent do, told to the person who grants the connection
 * and to the agent itself.
 *
 * The catalogue declares one record per tool and keeps no impact of its own: the tool declares
 * its impact once, and the record reads it back. Nothing here is parameterised by a report or by
 * a user — the catalogue describes what a set can do, not what a person may do with it.
 */
final class AgentOperationCatalog
{
	/**
	 * Phrase ids of every operation, grouped by the code the tool set class answers with.
	 * `warning` belongs to an operation that cannot be undone or that overwrites the previous
	 * state; the rest carry no warning.
	 */
	private const OPERATIONS = [
		'bi_dashboards' => [
			ListDashboardsTool::class => ['title' => 'BIC_AI_OP_LIST_DASHBOARDS'],
			SearchDashboardParametersTool::class => ['title' => 'BIC_AI_OP_SEARCH_DASHBOARD_PARAMETERS'],
			GetDashboardMetaTool::class => ['title' => 'BIC_AI_OP_GET_DASHBOARD_META'],
			GetChartDataTool::class => ['title' => 'BIC_AI_OP_GET_CHART_DATA'],
			GetFilterValuesTool::class => ['title' => 'BIC_AI_OP_GET_FILTER_VALUES'],
			CreateDashboardTool::class => ['title' => 'BIC_AI_OP_CREATE_DASHBOARD'],
			UpdateDashboardMetadataTool::class => ['title' => 'BIC_AI_OP_UPDATE_DASHBOARD_METADATA'],
			SetDashboardContentTool::class => [
				'title' => 'BIC_AI_OP_SET_DASHBOARD_CONTENT',
				'warning' => 'BIC_AI_OP_SET_DASHBOARD_CONTENT_WARNING',
			],
			PublishDashboardTool::class => ['title' => 'BIC_AI_OP_PUBLISH_DASHBOARD'],
			UnpublishDashboardTool::class => [
				'title' => 'BIC_AI_OP_UNPUBLISH_DASHBOARD',
				'warning' => 'BIC_AI_OP_UNPUBLISH_DASHBOARD_WARNING',
			],
			DeleteDashboardTool::class => [
				'title' => 'BIC_AI_OP_DELETE_DASHBOARD',
				'warning' => 'BIC_AI_OP_DELETE_DASHBOARD_WARNING',
			],
		],
		'bi_charts' => [
			ListChartsTool::class => ['title' => 'BIC_AI_OP_LIST_CHARTS'],
			GetChartTool::class => ['title' => 'BIC_AI_OP_GET_CHART'],
			CreateChartTool::class => ['title' => 'BIC_AI_OP_CREATE_CHART'],
			UpdateChartMetadataTool::class => ['title' => 'BIC_AI_OP_UPDATE_CHART_METADATA'],
			ReplaceChartConfigTool::class => [
				'title' => 'BIC_AI_OP_REPLACE_CHART_CONFIG',
				'warning' => 'BIC_AI_OP_REPLACE_CHART_CONFIG_WARNING',
			],
			DeleteChartTool::class => [
				'title' => 'BIC_AI_OP_DELETE_CHART',
				'warning' => 'BIC_AI_OP_DELETE_CHART_WARNING',
			],
		],
		'bi_datasets' => [
			ListDatasetsTool::class => ['title' => 'BIC_AI_OP_LIST_DATASETS'],
			GetDatasetTool::class => ['title' => 'BIC_AI_OP_GET_DATASET'],
			CreateVirtualDatasetTool::class => ['title' => 'BIC_AI_OP_CREATE_VIRTUAL_DATASET'],
			CreateDatasetFromSqlTool::class => ['title' => 'BIC_AI_OP_CREATE_DATASET_FROM_SQL'],
			UpdateDatasetTool::class => [
				'title' => 'BIC_AI_OP_UPDATE_DATASET',
				'warning' => 'BIC_AI_OP_UPDATE_DATASET_WARNING',
			],
			DeleteDatasetTool::class => [
				'title' => 'BIC_AI_OP_DELETE_DATASET',
				'warning' => 'BIC_AI_OP_DELETE_DATASET_WARNING',
			],
		],
		'bi_tables' => [
			ListBiTablesTool::class => ['title' => 'BIC_AI_OP_LIST_BI_TABLES'],
			GetBiTableSchemaTool::class => ['title' => 'BIC_AI_OP_GET_BI_TABLE_SCHEMA'],
			ExecuteSqlTool::class => ['title' => 'BIC_AI_OP_EXECUTE_SQL'],
		],
	];

	/**
	 * @param string $toolSetCode Code the tool set class answers with.
	 * @param string|null $language Language of the wording; null takes the language of the request.
	 *        A caller whose text is stored beyond the request that built it names the language.
	 * @return AgentOperation[] Records of the set in the declared order; empty for a code the
	 * catalogue does not know, which keeps an unknown set from failing the text it describes.
	 */
	public function getOperations(string $toolSetCode, ?string $language = null): array
	{
		$operations = [];
		foreach (self::OPERATIONS[$toolSetCode] ?? [] as $toolClass => $phraseIds)
		{
			$operations[] = $this->createOperation($toolSetCode, $toolClass, $phraseIds, $language);
		}

		return $operations;
	}

	/**
	 * @param string|null $language Language of the wording; null takes the language of the request.
	 * @return array<string, AgentOperation[]> Records of all four sets, keyed by tool set code.
	 */
	public function getAllOperations(?string $language = null): array
	{
		$operations = [];
		foreach (array_keys(self::OPERATIONS) as $toolSetCode)
		{
			$operations[$toolSetCode] = $this->getOperations($toolSetCode, $language);
		}

		return $operations;
	}

	private function createOperation(
		string $toolSetCode,
		string $toolClass,
		array $phraseIds,
		?string $language,
	): AgentOperation
	{
		$tool = $this->getTool($toolClass);

		return new AgentOperation(
			$toolSetCode,
			$tool->getName(),
			$tool->getImpact()->value,
			(string)Loc::getMessage($phraseIds['title'], null, $language),
			$this->getWarning($phraseIds['warning'] ?? null, $language),
		);
	}

	/**
	 * A phrase missing in the language answers with nothing, and an empty warning would read
	 * as a warning that says nothing: the record carries no warning at all instead.
	 */
	private function getWarning(?string $phraseId, ?string $language): ?string
	{
		if ($phraseId === null)
		{
			return null;
		}

		$warning = (string)Loc::getMessage($phraseId, null, $language);

		return trim($warning) === '' ? null : $warning;
	}

	/**
	 * The name and the impact are constants of the tool class, so the catalogue reads them from
	 * an instance built without the constructor: describing an operation needs none of the
	 * dependencies a call would need.
	 */
	private function getTool(string $toolClass): ImpactAwareTool
	{
		return (new \ReflectionClass($toolClass))->newInstanceWithoutConstructor();
	}
}
