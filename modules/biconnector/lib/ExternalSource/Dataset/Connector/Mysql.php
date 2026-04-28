<?php

namespace Bitrix\BIConnector\ExternalSource\Dataset\Connector;

use Bitrix\BIConnector\ExternalSource\Type;

final class Mysql extends ExternalSql
{
	protected function getType(): Type
	{
		return Type::Mysql;
	}
}
