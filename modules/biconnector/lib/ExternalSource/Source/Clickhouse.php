<?php

namespace Bitrix\BIConnector\ExternalSource\Source;

final class Clickhouse extends ExternalSql
{
	protected function buildConnection(array $config): DB\ExternalSqlConnectionInterface
	{
		return new DB\ClickhouseConnection($config);
	}
}
