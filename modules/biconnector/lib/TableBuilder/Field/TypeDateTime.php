<?php

namespace Bitrix\BIConnector\TableBuilder\Field;

class TypeDateTime extends Base
{
	public function getField(): string
	{
		$type = ($this->sqlHelper instanceof \Bitrix\Main\DB\PgsqlSqlHelper) ? 'TIMESTAMP' : 'DATETIME';

		return sprintf('%s %s', $this->sqlHelper->quote($this->name), $type);
	}
}
