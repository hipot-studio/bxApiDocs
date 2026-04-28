<?php
namespace Bitrix\BIConnector\ExternalSource\Source\DB;

interface ExternalSqlConnectionInterface
{
	public function showTables(): array;
}
