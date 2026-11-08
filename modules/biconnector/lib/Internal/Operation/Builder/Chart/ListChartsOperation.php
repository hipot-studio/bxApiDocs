<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Chart;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\Refusal;
use Bitrix\Main\Result;

/**
 * A page of the Superset charts of the portal, the answer of list_charts: `charts`, `total`, `limit`,
 * `offset` and `has_more`.
 *
 * Known gap: only ACTION_BIC_ACCESS is checked and the page is not narrowed to the dashboards the actor
 * may view, so charts of foreign dashboards are listed too. It is kept on purpose, as list_charts does it
 * today; closing it belongs to S2/S3 of PRD-bi-dataset-permissions-2026-07-30.
 */
final class ListChartsOperation
{
	public const LIMIT_DEFAULT = 20;
	public const LIMIT_MAX = 50;
	private const DESCRIPTION_MAX_LENGTH = 200;

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
	 * @param int $limit Page size, clamped to 1..LIMIT_MAX.
	 * @param int $offset Charts to skip, rounded down to a multiple of the page size.
	 */
	public function execute(int $limit, int $offset, Actor $actor): Result
	{
		$userId = $actor->userId;

		if (!($this->accessControllerFactory)($userId)->check(ActionDictionary::ACTION_BIC_ACCESS))
		{
			return Refusal::error(FailureCode::AccessDenied, 'Access denied: ACTION_BIC_ACCESS is required.');
		}

		$limit = max(1, min(self::LIMIT_MAX, $limit));
		$offset = max(0, $offset);

		// Superset paginates by page index, so translate offset → page. The
		// effective offset is realigned to a page boundary (offset rounded down
		// to a multiple of limit); agents that iterate by adding `limit` stay
		// aligned. This keeps the call to a single Superset request regardless of
		// how many charts the instance holds — never fetch-all-then-slice.
		$page = intdiv($offset, $limit);
		$effectiveOffset = $page * $limit;

		$response = IntegratorFactory::getInstance()->listCharts([], null, $page, $limit);
		if ($response->hasErrors())
		{
			return Refusal::unavailableBi(
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

		return (new Result())->setData([
			'charts' => $charts,
			'total' => $total,
			'limit' => $limit,
			'offset' => $effectiveOffset,
			'has_more' => ($effectiveOffset + count($rawCharts)) < $total,
		]);
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
