<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Dataset;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\Refusal;
use Bitrix\BIConnector\Internal\Operation\Builder\SupersetIdentity;
use Bitrix\Main\Result;

/**
 * A page of the Superset datasets of the portal, the answer of list_datasets: `datasets`, `total`, `limit`,
 * `offset` and `has_more`.
 *
 * Known gap: only ACTION_BIC_ACCESS is checked and the page is not narrowed to the datasets the actor
 * may use. It is kept on purpose, as list_datasets does it today; closing it belongs to S2/S3
 * of PRD-bi-dataset-permissions-2026-07-30.
 */
final class ListDatasetsOperation
{
	public const LIMIT_DEFAULT = 20;
	public const LIMIT_MAX = 50;
	private const TEXT_MAX_LENGTH = 200;

	/** @var \Closure(int): AccessController */
	private readonly \Closure $accessControllerFactory;
	private readonly SupersetIdentity $identity;

	/**
	 * @param (\Closure(int): AccessController)|null $accessControllerFactory
	 */
	public function __construct(?\Closure $accessControllerFactory = null, ?SupersetIdentity $identity = null)
	{
		$this->accessControllerFactory = $accessControllerFactory
			?? static fn(int $userId): AccessController => new AccessController($userId)
		;
		$this->identity = $identity ?? new SupersetIdentity();
	}

	/**
	 * @param int $limit Page size, clamped to 1..LIMIT_MAX.
	 * @param int $offset Datasets to skip, rounded down to a multiple of the page size.
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

		// Superset paginates by page index, so translate offset → page and realign
		// offset to a page boundary (mirrors list_charts). This keeps the call to a
		// single bounded Superset request regardless of how many datasets the
		// instance holds — never fetch-all-then-slice.
		$page = intdiv($offset, $limit);
		$effectiveOffset = $page * $limit;

		$identityResult = $this->identity->forRead($userId);
		if (!$identityResult->isSuccess())
		{
			return $identityResult;
		}

		$response = IntegratorFactory::getInstance()
			->listDatasets($identityResult->getData()['user'], [], [], $page, $limit)
		;
		if ($response->hasErrors())
		{
			return Refusal::unavailableBi(
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

		return (new Result())->setData([
			'datasets' => $datasets,
			'total' => $total,
			'limit' => $limit,
			'offset' => $effectiveOffset,
			'has_more' => ($effectiveOffset + count($rawDatasets)) < $total,
		]);
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
