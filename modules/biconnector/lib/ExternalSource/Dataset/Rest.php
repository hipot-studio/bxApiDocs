<?php

namespace Bitrix\BIConnector\ExternalSource\Dataset;

final class Rest extends Base
{
	protected function getResultTableName(): string
	{
		return $this->getDataset()->getName();
	}

	public function getSqlTableAlias(): string
	{
		return sprintf(
			'%s_%s',
			'EXTERNAL_REST',
			strtoupper($this->getDataset()->getName())
		);
	}

	protected function getConnectionTableName(): string
	{
		return $this->getDataset()->getName();
	}
}
