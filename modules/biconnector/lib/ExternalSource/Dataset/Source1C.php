<?php

namespace Bitrix\BIConnector\ExternalSource\Dataset;

final class Source1C extends Base
{
	protected function getResultTableName(): string
	{
		return $this->getDataset()->getName();
	}

	public function getSqlTableAlias(): string
	{
		return sprintf(
			'%s_%s',
			'SOURCE_1C',
			strtoupper($this->getDataset()->getName())
		);
	}

	protected function getConnectionTableName(): string
	{
		return $this->getDataset()->getName();
	}
}
