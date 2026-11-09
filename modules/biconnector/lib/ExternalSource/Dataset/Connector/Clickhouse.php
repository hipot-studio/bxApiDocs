<?php

namespace Bitrix\BIConnector\ExternalSource\Dataset\Connector;

use Bitrix\BIConnector\ExternalSource\Type;

final class Clickhouse extends ExternalSql
{
	/**
	 * Budget of a query when the load protection sets none. It matches the time the connection used to wait for an
	 * answer before the budget existed, so a report that used to finish keeps finishing.
	 */
	private const UNPROTECTED_SQL_TIME_LIMIT_SECONDS = 300;

	protected function getType(): Type
	{
		return Type::Clickhouse;
	}

	/**
	 * The query runs on an external server over HTTP and does not load the database of the portal,
	 * so it is not queued behind the requests that do.
	 *
	 * @return bool
	 */
	public function requiresPortalDataLock(): bool
	{
		return false;
	}

	/**
	 * Nothing outside of the load protection limits a ClickHouse query, so the server is always given a budget:
	 * the one of the load protection when it is on, a fixed one otherwise.
	 *
	 * @return int
	 */
	protected function getConnectionTimeLimitSeconds(): int
	{
		return $this->sqlTimeLimitSeconds ?? self::UNPROTECTED_SQL_TIME_LIMIT_SECONDS;
	}
}
