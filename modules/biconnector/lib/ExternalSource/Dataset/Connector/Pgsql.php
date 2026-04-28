<?php

namespace Bitrix\BIConnector\ExternalSource\Dataset\Connector;

use Bitrix\BIConnector\ExternalSource\Type;

final class Pgsql extends ExternalSql
{
	protected function getType(): Type
	{
		return Type::Pgsql;
	}
}
