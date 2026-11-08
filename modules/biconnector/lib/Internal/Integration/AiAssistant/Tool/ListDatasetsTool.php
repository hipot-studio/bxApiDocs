<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Internal\Feature\FlagGate;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Operation\OperationImpact;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\Channel;
use Bitrix\BIConnector\Internal\Operation\Builder\Dataset\ListDatasetsOperation;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

final class ListDatasetsTool extends BaseBiTool
{
	private const LIMIT_DEFAULT = 20;
	private const LIMIT_MAX = 50;
	private const TEXT_MAX_LENGTH = 200;

	public function getName(): string
	{
		return 'list_datasets';
	}

	public function getImpact(): OperationImpact
	{
		return OperationImpact::Read;
	}

	public function getDescription(): string
	{
		return 'Returns a paginated list of Superset datasets available on this portal. '
			. 'Each entry contains `id`, `table_name`, optional `schema`, `is_virtual`, '
			. 'and short `description` / `sql` previews (truncated to ' . self::TEXT_MAX_LENGTH
			. ' chars). '
			. 'Use this tool before creating a new virtual dataset to avoid duplicates '
			. 'and to discover existing datasets that the user can reference in charts. '
			. 'Paginate with `limit` (default ' . self::LIMIT_DEFAULT . ', max ' . self::LIMIT_MAX
			. ') and `offset` (default 0); the response carries `total`, `limit`, `offset` and '
			. '`has_more`. When `has_more` is true, request the next page with `offset` += `limit`.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'limit' => [
					'type' => 'integer',
					'minimum' => 1,
					'maximum' => self::LIMIT_MAX,
					'description' => 'Page size. Default ' . self::LIMIT_DEFAULT
						. ', maximum ' . self::LIMIT_MAX . '. Values over max are clamped.',
				],
				'offset' => [
					'type' => 'integer',
					'minimum' => 0,
					'description' => 'Offset for pagination. Default 0. Rounded down to a '
						. 'multiple of `limit` (page-aligned); the response echoes the '
						. 'effective `offset`. When `has_more` is true, send the next '
						. 'request with `offset` = previous `offset` + `limit`.',
				],
			],
			'additionalProperties' => false,
		];
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
		$result = ServiceLocator::getInstance()->get(ListDatasetsOperation::class)->execute(
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

		// Superset paginates by page index, so translate offset → page and realign
		// offset to a page boundary (mirrors list_charts). This keeps the call to a
		// single bounded Superset request regardless of how many datasets the
		// instance holds — never fetch-all-then-slice.
		$page = intdiv($offset, $limit);
		$effectiveOffset = $page * $limit;

		$response = IntegratorFactory::getInstance()->listDatasets([], [], $page, $limit);
		if ($response->hasErrors())
		{
			throw self::unavailableBiException(
				$response->getErrors(),
				['stage' => 'list_datasets', 'user_id' => $userId],
			);
		}

		$data = $response->getData();
		if (!is_array($data))
		{
			$data = [];
		}

		$rawDatasets = is_array($data['datasets'] ?? null) ? $data['datasets'] : [];
		$total = (int)($data['count'] ?? count($rawDatasets));

		$datasets = [];
		foreach ($rawDatasets as $dataset)
		{
			if (!is_array($dataset))
			{
				continue;
			}

			$sql = (string)($dataset['sql'] ?? '');
			$entry = [
				'id' => (int)($dataset['id'] ?? 0),
				'table_name' => (string)($dataset['table_name'] ?? ''),
				'is_virtual' => $sql !== '',
				'description' => self::truncate((string)($dataset['description'] ?? '')),
			];

			if (isset($dataset['schema']) && $dataset['schema'] !== '' && $dataset['schema'] !== null)
			{
				$entry['schema'] = (string)$dataset['schema'];
			}

			if ($sql !== '')
			{
				$entry['sql_preview'] = self::truncate($sql);
			}

			$datasets[] = $entry;
		}

		return [
			'datasets' => $datasets,
			'total' => $total,
			'limit' => $limit,
			'offset' => $effectiveOffset,
			'has_more' => ($effectiveOffset + count($rawDatasets)) < $total,
		];
	}

	private static function truncate(string $value): string
	{
		if ($value === '')
		{
			return '';
		}

		if (function_exists('mb_strlen') && mb_strlen($value) > self::TEXT_MAX_LENGTH)
		{
			return mb_substr($value, 0, self::TEXT_MAX_LENGTH);
		}

		if (strlen($value) > self::TEXT_MAX_LENGTH)
		{
			return substr($value, 0, self::TEXT_MAX_LENGTH);
		}

		return $value;
	}
}
