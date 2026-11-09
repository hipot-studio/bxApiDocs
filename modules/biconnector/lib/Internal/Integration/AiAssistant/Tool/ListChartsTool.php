<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Internal\Feature\FlagGate;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Operation\OperationImpact;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\Argument\BuilderArgumentContract;
use Bitrix\BIConnector\Internal\Operation\Builder\Channel;
use Bitrix\BIConnector\Internal\Operation\Builder\Chart\ListChartsOperation;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

final class ListChartsTool extends BaseBiTool
{
	private const LIMIT_DEFAULT = 20;
	private const LIMIT_MAX = 50;
	private const DESCRIPTION_MAX_LENGTH = 200;

	public function getName(): string
	{
		return 'list_charts';
	}

	public function getImpact(): OperationImpact
	{
		return OperationImpact::Read;
	}

	public function getDescription(): string
	{
		return 'Returns a paginated list of Superset charts available on this portal. '
			. 'Each entry contains `id`, `chart_name`, `viz_type`, `dataset_id`, and a short '
			. '`description` (truncated to ' . self::DESCRIPTION_MAX_LENGTH . ' chars). '
			. 'Use this tool before creating a new chart to avoid duplicates and to '
			. 'discover existing charts the user can reference or reuse on dashboards. '
			. 'Paginate with `limit` (default ' . self::LIMIT_DEFAULT . ', max ' . self::LIMIT_MAX
			. ') and `offset` (default 0); the response carries `total`, `limit`, `offset` and '
			. '`has_more`. When `has_more` is true, request the next page with `offset` += `limit`.';
	}

	public function getInputSchema(): array
	{
		return BuilderArgumentContract::schema(
			'biconnector.builder.chart.list',
			'mcp',
			[
				'limit' => 'Page size. Default 20, maximum 50.',
				'offset' => 'Offset for pagination. Default 0. Rounded down to a '
					. 'multiple of `limit` (page-aligned); the response echoes the '
					. 'effective `offset`. When `has_more` is true, send the next '
					. 'request with `offset` = previous `offset` + `limit`.',
			],
		);
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		if (!(new FlagGate())->isEnabled(FlagGate::BUILDER_MCP_OPERATIONS, $userId))
		{
			return $this->legacyExecuteStructured($userId, ...$args);
		}

		self::requireBiconnectorModule();

		$limit = self::LIMIT_DEFAULT;
		if (isset($args['limit']) && is_numeric($args['limit']))
		{
			$limit = (int)$args['limit'];
		}

		$offset = 0;
		if (isset($args['offset']) && is_numeric($args['offset']))
		{
			$offset = (int)$args['offset'];
		}

		// Resolved per call: aiassistant keeps a tool as a process-wide singleton.
		$result = ServiceLocator::getInstance()->get(ListChartsOperation::class)->execute(
			$limit,
			$offset,
			new Actor($userId, Channel::Mcp),
		);
		if (!$result->isSuccess())
		{
			throw self::toMcpException(OperationRefusal::forTool($result));
		}

		return $result->getData();
	}

	private function legacyExecuteStructured(int $userId, ...$args): array
	{
		Loader::includeModule('biconnector');

		if (!$this->getAccessController($userId)->check(ActionDictionary::ACTION_BIC_ACCESS))
		{
			throw new McpException('Access denied: ACTION_BIC_ACCESS is required.');
		}

		$limit = self::LIMIT_DEFAULT;
		if (isset($args['limit']) && is_numeric($args['limit']))
		{
			$limit = max(1, min(self::LIMIT_MAX, (int)$args['limit']));
		}

		$offset = 0;
		if (isset($args['offset']) && is_numeric($args['offset']))
		{
			$offset = max(0, (int)$args['offset']);
		}

		// Superset paginates by page index, so translate offset → page. The
		// effective offset is realigned to a page boundary (offset rounded down
		// to a multiple of limit); agents that iterate by adding `limit` stay
		// aligned. This keeps the call to a single Superset request regardless of
		// how many charts the instance holds — never fetch-all-then-slice.
		$page = intdiv($offset, $limit);
		$effectiveOffset = $page * $limit;

		$response = IntegratorFactory::getInstance()
			->listCharts(self::findActAsUser($userId), [], null, $page, $limit)
		;
		if ($response->hasErrors())
		{
			throw self::unavailableBiException(
				$response->getErrors(),
				['stage' => 'list_charts', 'user_id' => $userId],
			);
		}

		$data = $response->getData();
		if (!is_array($data))
		{
			$data = [];
		}

		$rawCharts = is_array($data['charts'] ?? null) ? $data['charts'] : [];
		$total = (int)($data['count'] ?? count($rawCharts));

		$charts = [];
		foreach ($rawCharts as $chart)
		{
			if (!is_array($chart))
			{
				continue;
			}

			$charts[] = [
				'id' => (int)($chart['id'] ?? 0),
				'chart_name' => (string)($chart['chart_name'] ?? $chart['slice_name'] ?? ''),
				'viz_type' => (string)($chart['viz_type'] ?? ''),
				'dataset_id' => (int)($chart['dataset_id'] ?? $chart['datasource_id'] ?? 0),
				'description' => self::truncate((string)($chart['description'] ?? '')),
			];
		}

		return [
			'charts' => $charts,
			'total' => $total,
			'limit' => $limit,
			'offset' => $effectiveOffset,
			'has_more' => ($effectiveOffset + count($rawCharts)) < $total,
		];
	}

	private static function truncate(string $value): string
	{
		if ($value === '')
		{
			return '';
		}

		if (function_exists('mb_strlen') && mb_strlen($value) > self::DESCRIPTION_MAX_LENGTH)
		{
			return mb_substr($value, 0, self::DESCRIPTION_MAX_LENGTH);
		}

		if (strlen($value) > self::DESCRIPTION_MAX_LENGTH)
		{
			return substr($value, 0, self::DESCRIPTION_MAX_LENGTH);
		}

		return $value;
	}
}
