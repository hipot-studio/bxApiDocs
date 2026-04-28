<?php

namespace Bitrix\BIConnector\ExternalSource\Source;

final class Mysql extends ExternalSql
{
	protected function buildConnection(array $config): DB\ExternalSqlConnectionInterface
	{
		return new DB\MysqliConnection($config);
	}
}
