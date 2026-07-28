<?php

namespace Bitrix\BIConnector\ExternalSource\Dataset;

use Bitrix\BIConnector;

final class Csv extends Base
{
	protected function getResultTableName(): string
	{
		return $this->getDataset()->getName();
	}

	public function getSqlTableAlias(): string
	{
		return sprintf(
			'%s%s',
			strtoupper($this->getDataset()->getType()),
			strtoupper($this->getDataset()->getName())
		);
	}

	protected function getConnectionTableName(): string
	{
		return BIConnector\ExternalSource\Source\Csv::TABLE_NAME_PREFIX . $this->getDataset()->getName();
	}
}
