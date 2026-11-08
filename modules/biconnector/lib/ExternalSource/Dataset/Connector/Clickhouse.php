<?php

namespace Bitrix\BIConnector\ExternalSource\Dataset\Connector;

use Bitrix\BIConnector\ExternalSource\Type;

final class Clickhouse extends ExternalSql
{
	protected function getType(): Type
	{
		return Type::Clickhouse;
	}
}
