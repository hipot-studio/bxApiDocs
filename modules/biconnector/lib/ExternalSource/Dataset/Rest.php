<?php

namespace Bitrix\BIConnector\ExternalSource\Dataset;

use Bitrix\BIConnector\DataSource\DatasetField;
use Bitrix\BIConnector\ExternalSource\Internal\ExternalDatasetField;

final class Rest extends Base
{
	protected function getField(ExternalDatasetField $datasetField): DatasetField
	{
		$field = parent::getField($datasetField);
		$description = $datasetField->getDescription();
		if ($description !== null && trim($description) !== '')
		{
			$field->setDescriptionFull($description);
		}

		return $field;
	}

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
