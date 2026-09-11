<?php

namespace Bitrix\Superset\Internal\Api;

use Bitrix\Superset\Internal\Connector\SupersetInstance;
use Bitrix\Superset\Internal\RequestResult;

class SqlLab
{
	private const EXECUTE_API_LINK = '/api/v1/sqllab/execute/';

	public function __construct(private readonly SupersetInstance $connector)
	{
	}

	/**
	 * Executes a SQL query via Superset SQL Lab.
	 *
	 * Payload matches Superset ExecutePayloadSchema. Required: database_id, sql.
	 * For synchronous execution send runAsync=false.
	 *
	 * @param array $payload
	 * @return RequestResult
	 */
	public function execute(array $payload): RequestResult
	{
		return $this->connector->post(self::EXECUTE_API_LINK, $payload);
	}
}
