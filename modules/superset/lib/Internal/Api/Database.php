<?php

namespace Bitrix\Superset\Internal\Api;

use Bitrix\Main;
use Bitrix\Superset\Internal\Connector\SupersetInstance;
use Bitrix\Superset\Internal\RequestResult;

class Database
{
	public const TRINO_DATABASE_NAME = 'trino';

	private const DATABASE_API_LINK = '/api/v1/database/';

	private ?SupersetInstance $connector;

	public function __construct(SupersetInstance $connector)
	{
		$this->connector = $connector;
	}

	/**
	 * Gets databases
	 *
	 * @param int|null $page
	 * @param int|null $pageSize
	 * @return RequestResult
	 */
	public function getDatabases(?int $page, ?int $pageSize): RequestResult
	{
		$url = self::DATABASE_API_LINK;

		if ($page || $pageSize)
		{
			$query = [];
			if ($page)
			{
				$query['page'] = $page;
			}

			if ($pageSize)
			{
				$query['page_size'] = $pageSize;
			}

			$query = Main\Web\Json::encode($query);
			$url = self::DATABASE_API_LINK . '?q=' . $query;
		}

		return $this->connector->get($url);
	}

	/**
	 * Gets database by id
	 *
	 * @param int $id
	 * @return RequestResult
	 */
	public function getDatabaseById(int $id): RequestResult
	{
		$url = self::DATABASE_API_LINK . $id;
		return $this->connector->get($url);
	}

	/**
	 * Gets database by name
	 *
	 * @param string $name
	 * @return RequestResult
	 * @throws Main\ArgumentException
	 */
	public function getDatabaseByName(string $name, array $columns = []): RequestResult
	{
		$query = [
			'filters' => [
				[
					'col' => 'database_name',
					'opr' => 'eq',
					'value' => $name,
				],
			],
		];

		if (!empty($columns))
		{
			$query['columns'] = $columns;
		}

		$query = Main\Web\Json::encode($query);
		$url = self::DATABASE_API_LINK . '?q=' . $query;

		return $this->connector->get($url);
	}

	/**
	 * Gets database connection
	 *
	 * @param int $id
	 * @return RequestResult
	 */
	public function getDatabaseConnection(int $id): RequestResult
	{
		$url = self::DATABASE_API_LINK . "$id/connection";
		return $this->connector->get($url);
	}

	/**
	 * Updates database connection fields
	 *
	 * @param int $id
	 * @param array $payload
	 * @return RequestResult
	 */
	public function updateDatabaseConnection(int $id, array $payload): RequestResult
	{
		$url = self::DATABASE_API_LINK . $id;
		return $this->connector->put($url, $payload);
	}
}
