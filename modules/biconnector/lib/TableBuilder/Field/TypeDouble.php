<?php

namespace Bitrix\BIConnector\TableBuilder\Field;

class TypeDouble extends Base
{
	public function getField(): string
	{
		$type = ($this->sqlHelper instanceof \Bitrix\Main\DB\PgsqlSqlHelper) ? 'DOUBLE PRECISION' : 'DOUBLE';

		return sprintf('%s %s', $this->sqlHelper->quote($this->name), $type);
	}
}
