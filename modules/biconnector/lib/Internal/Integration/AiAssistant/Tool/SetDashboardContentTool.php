<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Internal\Feature\FlagGate;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Dashboard\DashboardJsonMetadataReader;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Dashboard\DashboardLayoutBuilder;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Dashboard\NativeFilterBuilder;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Operation\OperationImpact;
use Bitrix\BIConnector\Internal\Operation\Builder\Argument\BuilderArgumentContract;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\Channel;
use Bitrix\BIConnector\Internal\Operation\Builder\Dashboard\DraftPolicy;
use Bitrix\BIConnector\Internal\Operation\Builder\Dashboard\SetContentCommand;
use Bitrix\BIConnector\Internal\Operation\Builder\Dashboard\SetContentOperation;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

final class SetDashboardContentTool extends BaseBiTool
{
	public function getName(): string
	{
		return 'set_dashboard_content';
	}

	public function getImpact(): OperationImpact
	{
		return OperationImpact::Modify;
	}

	public function getDescription(): string
	{
		return 'Replaces the chart layout and/or filter configuration of a custom BI '
			. 'dashboard. Pass at least one of `charts` / `filters`. '
			. 'For rename use update_dashboard_metadata; for publish/unpublish use '
			. 'publish_dashboard / unpublish_dashboard — this tool does NOT '
			. 'touch the title or published state. '
			. 'IMPORTANT — replace semantics: `charts` REPLACES the entire '
			. 'dashboard layout (send the full chart list every time, not a '
			. 'delta); `filters` REPLACES the entire filter configuration. '
			. 'Other dashboard metadata edited in the UI (color scheme, chart '
			. 'cross-filter wiring, etc.) is preserved across these updates. '
			. 'Layout: charts flow into rows of total width 12 grid units; '
			. 'each chart declares `width` (1..12), heights default to 50 grid '
			. 'units. Charts are placed in input order, wrapping to a new row '
			. 'whenever the row width would overflow. '
			. 'Filters: `time_range` is a global date filter that applies to '
			. 'all charts with a temporal axis (no datasetId/column needed); '
			. '`value` is a multi-select dropdown filtering by a column value; '
			. '`range` is a numeric range filter. `value`/`range` need '
			. '`datasetId` and `column` (use list_datasets / get_bi_table_schema '
			. 'to look up real column names — never guess). '
			. 'By default a filter applies to all charts in the resulting layout; '
			. 'restrict scope with `chartIds`; an empty list applies to no charts. '
			. 'HARD INVARIANT — every dashboard MUST have a `time_range` filter. '
			. 'The tool rejects `charts=[...]` updates when no `time_range` '
			. 'filter is configured (either supplied in the same call or '
			. 'already present on the dashboard). When attaching the first '
			. 'chart, send `charts` and `filters` (with a time_range) together. '
			. 'Supply at least one `time_range` with a nonblank `defaultValue` — a Superset '
			. 'time expression that becomes the filter\'s initial value when '
			. 'the user opens the dashboard. Translate the user\'s phrasing '
			. 'literally: "first quarter <year>" → "<year>-01-01 : <year>-03-31"; '
			. '"current month" / "this month" → "previous calendar month" or '
			. 'an explicit date range starting on the 1st of the current '
			. 'month; "last week"/"last month"/"last quarter"/"last year" → '
			. '"Last week" / "Last month" / "Last quarter" / "Last year"; '
			. '"today" → "Today". For arbitrary user-supplied ranges use ISO '
			. 'date pairs `YYYY-MM-DD : YYYY-MM-DD`. If the user did NOT '
			. 'mention any period, default to "Last quarter". '
			. 'TWO-STEP CONFIRMATION (MANDATORY): the first call (omit `confirm`, or pass '
			. '`confirm=false`) returns a preview of what will be overwritten (layout and/or '
			. 'filters) and changes nothing. Relay it to the user, get EXPLICIT approval, then '
			. 'call again with `confirm=true`. Never set `confirm=true` on your own, never '
			. 'chain the two calls, never infer approval from earlier conversation.';
	}

	public function getInputSchema(): array
	{
		$schema = BuilderArgumentContract::schema(
			BuilderArgumentContract::methodForTool($this->getName()),
			'mcp',
			[
				'dashboardId' => 'Local dashboard id (from create_dashboard or list_dashboards). Positive integer.',
				'charts' => 'Full chart layout. Replaces existing layout. Charts flow left-to-right in input order; '
					. 'a chart wraps to a new row whenever the running row width would exceed 12 grid units. '
					. 'Omit to keep the layout; [] clears it.',
				'charts[].chartId' => 'Chart id (from list_charts or create_chart).',
				'charts[].width' => 'Chart width in 1..12 grid units. Defaults to 6 (half row).',
				'charts[].height' => 'Chart height in grid units. Defaults to 50. Range 10..200.',
				'charts[].sliceName' => 'Optional chart label override shown in the dashboard.',
				'filters' => 'Full filter configuration. Replaces existing filters. Omit to keep the filters. '
					. 'Supply at least one time_range with a nonblank defaultValue; [] is rejected.',
				'filters[].name' => 'Filter display name shown in the dashboard sidebar.',
				'filters[].filterType' => '`time_range` is a global date range (no dataset/column needed). '
					. '`value` is a multi-select dropdown filtering by column value. '
					. '`range` is a numeric range filter on a column.',
				'filters[].datasetId' => 'Dataset id the column belongs to. Required for value/range.',
				'filters[].column' => 'Column to filter on. Required for value/range. '
					. 'Use real column names from get_bi_table_schema.',
				'filters[].chartIds' => 'Optional chart ids the filter applies to. If omitted, applies to all charts '
					. 'of the resulting layout; [] means no charts.',
				'filters[].defaultValue' => 'For time_range: initial date range when the dashboard opens. '
					. 'Use Superset expressions such as "Last quarter", "Last month", "Last year", '
					. '"previous calendar month", "Today", "Yesterday", or ISO date pairs YYYY-MM-DD : YYYY-MM-DD. '
					. 'At least one supplied time_range must have a nonblank defaultValue.',
			],
		);
		$schema['properties']['confirm'] = [
			'type' => 'boolean',
			'description' => 'Set to true ONLY after the user has explicitly approved '
				. 'overwriting the dashboard content (layout and/or filters). Omit or set '
				. 'false to only preview. Do NOT self-confirm or assume approval from earlier context.',
		];

		return $schema;
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		if (!(new FlagGate())->isEnabled(FlagGate::BUILDER_MCP_OPERATIONS, $userId))
		{
			return $this->legacyExecuteStructured($userId, ...$args);
		}

		Loader::includeModule('biconnector');

		$dashboardId = (int)($args['dashboardId'] ?? 0);
		if ($dashboardId <= 0)
		{
			throw new McpException('dashboardId is required and must be a positive integer.');
		}

		$command = new SetContentCommand(
			$dashboardId,
			isset($args['charts']) && is_array($args['charts']) ? $args['charts'] : null,
			isset($args['filters']) && is_array($args['filters']) ? $args['filters'] : null,
		);
		$actor = new Actor($userId, Channel::Mcp);
		$policy = $this->getDraftPolicy($userId);
		// Resolved per call: aiassistant keeps a tool as a process-wide singleton.
		$operation = ServiceLocator::getInstance()->get(SetContentOperation::class);

		$confirm = ($args['confirm'] ?? false) === true;
		if (!$confirm)
		{
			$preview = $operation->preview($command, $actor, $policy);
			if (!$preview->isSuccess())
			{
				throw self::toMcpException($preview);
			}
			$hasCharts = $command->charts !== null;
			$hasFilters = $command->filters !== null;

			return [
				'requiresConfirmation' => true,
				'action' => 'set_dashboard_content',
				'id' => $dashboardId,
				'name' => $preview->getData()['name'],
				'impact' => 'The dashboard content will be overwritten: '
					. ($hasCharts ? 'the chart layout will be fully replaced' : 'the layout is kept')
					. ' and '
					. ($hasFilters ? 'the filter configuration will be fully replaced' : 'the filters are kept')
					. '. This is a full replace, not a delta, and cannot be undone.',
				'charts_count' => $preview->getData()['charts_count'],
				'filters_count' => $preview->getData()['filters_count'],
				'confirmHint' => 'Show this preview to the user; only AFTER they explicitly approve, call '
					. 'set_dashboard_content again with the same arguments and confirm=true.',
			];
		}

		$result = $operation->execute($command, $actor, $policy);
		if (!$result->isSuccess())
		{
			throw self::toMcpException($result);
		}

		return $result->getData();
	}

	/**
	 * The draft-only rule holds for MCP once the alignment of its behaviour with REST is switched on.
	 */
	private function getDraftPolicy(int $userId): DraftPolicy
	{
		return (new FlagGate())->isEnabled(FlagGate::BUILDER_MCP_ALIGNMENT, $userId)
			? DraftPolicy::Enforce
			: DraftPolicy::Skip
		;
	}

	private function legacyExecuteStructured(int $userId, ...$args): array
	{
		Loader::includeModule('biconnector');

		$dashboardId = (int)($args['dashboardId'] ?? 0);
		if ($dashboardId <= 0)
		{
			throw new McpException('dashboardId is required and must be a positive integer.');
		}

		$hasCharts = isset($args['charts']) && is_array($args['charts']);
		$hasFilters = isset($args['filters']) && is_array($args['filters']);
		if (!$hasCharts && !$hasFilters)
		{
			throw new McpException(
				'Nothing to update. Provide at least one of: charts, filters. '
				. 'For rename use update_dashboard_metadata; for publish/unpublish use '
				. 'publish_dashboard / unpublish_dashboard.'
			);
		}

		$loadResult = $this->loadDashboardForWrite($dashboardId, $userId);
		if (!$loadResult->isSuccess())
		{
			throw self::toMcpException($loadResult);
		}
		$dashboard = $loadResult->getData()['dashboard'];
		$externalId = (int)$dashboard->getExternalId();

		// HARD INVARIANT: every dashboard must have a time_range filter — without
		// one, agent-attached charts silently ignore the missing dashboard date
		// filter and return unfiltered SQL. Enforce on every write that adds
		// charts; filter-only replaces are policed by the
		// `filtersContainTimeRange` check below.
		$timeRangeProvided = $hasFilters && self::filtersContainTimeRange($args['filters']);

		if ($hasFilters && !$timeRangeProvided)
		{
			throw new McpException(
				'filters must contain at least one `time_range` filter with a non-empty '
				. '`defaultValue`. Every dashboard needs a date filter; if the user did '
				. 'not specify a period, default to "Last quarter".'
			);
		}

		// Superset's UpdateDashboardCommand replaces json_metadata wholesale, so
		// we merge our positions/native_filter_configuration onto the current
		// dict fetched from Superset — otherwise custom keys (label_colors,
		// chart_configuration, filter_scopes, etc.) would be lost on every
		// update. Also: Superset syncs `dashboard.slices` M2M only when
		// `positions` lives inside json_metadata (see DashboardDAO
		// .set_dash_metadata). Sending position_json as a top-level field
		// updates the column but leaves slices empty, making the frontend
		// render every chart as MissingChart.
		$remote = DashboardJsonMetadataReader::fetchDashboard(
			$externalId,
			$userId,
			$dashboardId,
			'set_dashboard_content',
		);
		$jsonMetadata = $remote?->jsonMetadata ?? [];
		$savedPositions = $remote ? DashboardJsonMetadataReader::savedPositions($remote) : [];

		if ($hasCharts && !$timeRangeProvided && !DashboardJsonMetadataReader::hasTimeRange($jsonMetadata))
		{
			throw new McpException(
				'Dashboard has no `time_range` filter configured. Every dashboard '
				. 'must have a date filter before charts are attached. Pass '
				. '`filters` with at least one `time_range` entry (use '
				. '"Last quarter" as default if the user did not specify a '
				. 'period).'
			);
		}

		if (!array_key_exists('cross_filters_enabled', $jsonMetadata))
		{
			$jsonMetadata['cross_filters_enabled'] = true;
		}

		// The OLD universe of chart IDs — the smart re-scope needs it to tell an
		// "all charts" filter apart from one the user narrowed by hand.
		$oldChartIds = self::extractChartIdsFromPositions($savedPositions);

		if ($hasCharts)
		{
			$jsonMetadata['positions'] = DashboardLayoutBuilder::build($args['charts']);
		}

		if ($hasFilters)
		{
			// Filters with no explicit chartIds fall back to "every chart in
			// this update". When filters are replaced alone (no charts in the
			// same call), reuse the existing chart IDs from positions so a
			// time_range filter doesn't end up with an empty chartsInScope.
			$defaultChartsInScope = $hasCharts
				? array_map(
					static fn (array $c): int => (int)($c['chartId'] ?? 0),
					$args['charts'],
				)
				: $oldChartIds
			;
			$jsonMetadata['native_filter_configuration'] = NativeFilterBuilder::build(
				$args['filters'],
				$defaultChartsInScope,
			);
		}
		elseif ($hasCharts && isset($jsonMetadata['native_filter_configuration']))
		{
			// Replacing charts changes the universe of chart IDs. Every existing
			// filter must be re-scoped onto the new set, otherwise newly added
			// charts silently fall outside the filters and removed charts linger
			// as dead IDs (chartsInScope still references the old chart IDs). The
			// re-scope preserves the user's per-filter intent: a filter that was
			// on every chart follows the new full set, a hand-narrowed one keeps
			// its selection (only losing charts that no longer exist).
			$jsonMetadata['native_filter_configuration'] = self::rescopeChartFilters(
				$jsonMetadata['native_filter_configuration'],
				$oldChartIds,
				array_map(
					static fn (array $c): int => (int)($c['chartId'] ?? 0),
					$args['charts'],
				),
			);
		}

		$access = SetContentOperation::checkContentAccess(
			$hasCharts ? $args['charts'] : null,
			$hasFilters ? $args['filters'] : null,
			$hasCharts ? self::extractChartIdsFromPositions($jsonMetadata['positions']) : $oldChartIds,
			new Actor($userId, Channel::Mcp),
			$oldChartIds,
		);
		if (!$access->isSuccess())
		{
			throw self::toMcpException($access);
		}

		$confirm = ($args['confirm'] ?? false) === true;
		if (!$confirm)
		{
			return [
				'requiresConfirmation' => true,
				'action' => 'set_dashboard_content',
				'id' => $dashboardId,
				'name' => (string)$dashboard->getTitle(),
				'impact' => 'The dashboard content will be overwritten: '
					. ($hasCharts ? 'the chart layout will be fully replaced' : 'the layout is kept')
					. ' and '
					. ($hasFilters ? 'the filter configuration will be fully replaced' : 'the filters are kept')
					. '. This is a full replace, not a delta, and cannot be undone.',
				'charts_count' => $hasCharts ? count($args['charts']) : null,
				'filters_count' => $hasFilters ? count($args['filters']) : null,
				'confirmHint' => 'Show this preview to the user; only AFTER they explicitly approve, call set_dashboard_content again with the same arguments and confirm=true.',
			];
		}

		$response = IntegratorFactory::getInstance()->updateDashboard($externalId, [
			'json_metadata' => $jsonMetadata,
		]);
		if ($response->hasErrors())
		{
			throw self::unavailableBiException(
				$response->getErrors(),
				[
					'stage' => 'set_dashboard_content',
					'user_id' => $userId,
					'dashboard_id' => $dashboardId,
					'external_id' => $externalId,
					'changed_keys' => array_values(array_filter([
						$hasCharts ? 'charts' : null,
						$hasFilters ? 'filters' : null,
					])),
				],
			);
		}

		return [
			'id' => $dashboardId,
			'name' => (string)$dashboard->getTitle(),
			'published' => $dashboard->getStatus() === SupersetDashboardTable::DASHBOARD_STATUS_READY,
			'charts_count' => $hasCharts ? count($args['charts']) : null,
			'filters_count' => $hasFilters ? count($args['filters']) : null,
		];
	}

	private static function filtersContainTimeRange(array $filters): bool
	{
		return SetContentOperation::filtersContainTimeRange($filters);
	}

	/**
	 * @return int[]
	 */
	private static function extractChartIdsFromPositions(array $positions): array
	{
		return SetContentOperation::extractChartIdsFromPositions($positions);
	}

	/**
	 * Smart re-scope of every chart filter after a charts-only layout replace,
	 * {@see SetContentOperation::rescopeChartFilters()}.
	 *
	 * @param array $config Existing native_filter_configuration.
	 * @param int[] $oldChartIds Chart IDs that were on the dashboard BEFORE the replace.
	 * @param int[] $newChartIds Chart IDs from the just-replaced layout.
	 */
	private static function rescopeChartFilters(array $config, array $oldChartIds, array $newChartIds): array
	{
		return SetContentOperation::rescopeChartFilters($config, $oldChartIds, $newChartIds);
	}
}
