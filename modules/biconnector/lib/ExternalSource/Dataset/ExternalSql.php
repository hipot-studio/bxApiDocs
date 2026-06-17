<?php

namespace Bitrix\BIConnector\ExternalSource\Dataset;

use Bitrix\BIConnector;
use Bitrix\BIConnector\ExternalSource;
use Bitrix\Main;

final class ExternalSql extends Base
{
	/**
	 * @return Main\Result
	 */
	protected function onBeforeEvent(): Main\Result
	{
		$result = parent::onBeforeEvent();

		if (!ExternalSource\SourceManager::isExternalSqlConnectionsAvailable())
		{
			$result->addError(new Main\Error('External SQL connections are not available.'));
		}

		return $result;
	}

	public static function createDataset(
		ExternalSource\Internal\ExternalDataset $dataset,
		Main\DB\Connection $dataConnection,
		?string $languageId = null,
	): self
	{
		return (new self($dataConnection, $languageId))->setDataset($dataset);
	}

	protected function getConnector(string $name, BIConnector\DataSourceConnector\FieldCollection $fields, array $datasetInfo): BIConnector\DataSourceConnector\Connector\Base
	{
		return Connector\Factory::getConnector($this->dataset->getEnumType(), $name, $fields, $datasetInfo);
	}

	protected function getResultTableName(): string
	{
		return $this->dataset->getName();
	}

	public function getSqlTableAlias(): string
	{
		return sprintf(
			'%s%s',
			strtoupper($this->dataset->getType()),
			strtoupper($this->dataset->getExternalCode()),
		);
	}

	protected function getConnectionTableName(): string
	{
		return $this->dataset->getExternalCode();
	}

	protected function getField(BIConnector\ExternalSource\Internal\ExternalDatasetField $datasetField): BIConnector\DataSource\DatasetField
	{
		$type = $datasetField->getEnumType();
		$name = $datasetField->getName();
		$externalCode = $datasetField->getExternalCode();

		$field = match ($type) {
			BIConnector\ExternalSource\FieldType::Int => new BIConnector\DataSource\Field\IntegerField($name),
			BIConnector\ExternalSource\FieldType::String => new BIConnector\DataSource\Field\StringField($name),
			BIConnector\ExternalSource\FieldType::Double, BIConnector\ExternalSource\FieldType::Money => new BIConnector\DataSource\Field\DoubleField($name),
			BIConnector\ExternalSource\FieldType::Date => new BIConnector\DataSource\Field\DateField($name),
			BIConnector\ExternalSource\FieldType::DateTime => new BIConnector\DataSource\Field\DateTimeField($name),
		};

		$field->setExpression($externalCode, isPrepared: false);
		$field->setDescription($datasetField->getName());
		$field->setDescriptionFull($datasetField->getName());

		return $field;
	}
}
