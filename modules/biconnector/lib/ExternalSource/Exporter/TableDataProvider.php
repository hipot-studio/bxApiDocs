<?php

namespace Bitrix\BIConnector\ExternalSource\Exporter;

use Bitrix\Main\Application;

class TableDataProvider implements DataProvider
{
	public function __construct(
		private string $tableName
	)
	{
	}

	public function getTotalSize(): int
	{
		$connection = Application::getInstance()->getConnection();

		return (int)$connection->query(
			sprintf(
				'SELECT count(*) as COUNT FROM %s',
				$connection->getSqlHelper()->quote($this->tableName)
			)
		)->fetch()['COUNT'];
	}

	public function fetchChunk(int $chunkSize, int $chunkOffset): iterable
	{
		$connection = Application::getInstance()->getConnection();
		$res = $connection->query(
			sprintf(
				"SELECT * FROM %s LIMIT %d OFFSET %d",
				$connection->getSqlHelper()->quote($this->tableName),
				$chunkSize,
				$chunkOffset,
			)
		);

		while ($row = $res->fetch())
		{
			yield $row;
		}
	}
}
