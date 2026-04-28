<?php

namespace Bitrix\BIConnector\ExternalSource\Source;

final class Pgsql extends ExternalSql
{
	protected function buildConnection(array $config): DB\ExternalSqlConnectionInterface
	{
		return new DB\PgsqlConnection($config);
	}
}
