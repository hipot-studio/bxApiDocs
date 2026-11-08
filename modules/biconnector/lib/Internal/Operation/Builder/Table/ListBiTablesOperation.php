<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Table;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\Refusal;
use Bitrix\Main\Result;

/**
 * A page of the physical tables of the BI database, the answer of list_bi_tables: `tables`, `database_id`,
 * `total`, `limit`, `offset` and `has_more`.
 *
 * The right to edit dashboards is checked globally, as list_bi_tables does it: the axis of the table rights
 * is not applied here, it belongs to S2/S3 of PRD-bi-dataset-permissions-2026-07-30.
 */
final class ListBiTablesOperation
{
	public const LIMIT_DEFAULT = 20;
	public const LIMIT_MAX = 50;

	/** @var \Closure(int): AccessController */
	private readonly \Closure $accessControllerFactory;

	/**
	 * @param (\Closure(int): AccessController)|null $accessControllerFactory
	 */
	public function __construct(?\Closure $accessControllerFactory = null)
	{
		$this->accessControllerFactory = $accessControllerFactory
			?? static fn(int $userId): AccessController => new AccessController($userId)
		;
	}

	/**
	 * @param string|null $schema Schema of the tables; null or blank means the default BI schema.
	 * @param int $limit Page size, clamped to 1..LIMIT_MAX.
	 * @param int $offset Tables to skip.
	 */
	public function execute(?string $schema, int $limit, int $offset, Actor $actor): Result
	{
		$userId = $actor->userId;

		if (!($this->accessControllerFactory)($userId)->check(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT))
		{
			return Refusal::error(
				FailureCode::AccessDenied,
				'Access denied: report editing permission (ACTION_BIC_DASHBOARD_EDIT) is required to browse BI tables.',
			);
		}

		$schema = $schema !== '' ? $schema : null;
		$limit = max(1, min(self::LIMIT_MAX, $limit));
		$offset = max(0, $offset);

		$response = IntegratorFactory::getInstance()->listTables($schema);
		if ($response->hasErrors())
		{
			return Refusal::unavailableBi(
				$response->getErrors(),
				['stage' => 'list_bi_tables', 'user_id' => $userId, 'schema' => $schema],
			);
		}

		$data = $response->getData();
		if (!is_array($data))
		{
			$data = [];
		}

		$rawTables = is_array($data['tables'] ?? null) ? $data['tables'] : [];
		$total = count($rawTables);

		$page = $offset > 0 ? array_slice($rawTables, $offset, $limit) : array_slice($rawTables, 0, $limit);

		$tables = [];
		foreach ($page as $table)
		{
			if (!is_array($table))
			{
				continue;
			}

			$entry = [
				'name' => (string)($table['name'] ?? ''),
			];
			if (isset($table['type']) && $table['type'] !== '' && $table['type'] !== null)
			{
				$entry['type'] = (string)$table['type'];
			}
			if (isset($table['schema']) && $table['schema'] !== '' && $table['schema'] !== null)
			{
				$entry['schema'] = (string)$table['schema'];
			}

			$tables[] = $entry;
		}

		return (new Result())->setData([
			'tables' => $tables,
			'database_id' => (int)($data['databaseId'] ?? 0),
			'total' => $total,
			'limit' => $limit,
			'offset' => $offset,
			'has_more' => ($offset + count($page)) < $total,
		]);
	}
}
