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

		// The per-type option must work as an operational switch: turning it off has to stop an
		// already created dataset from being published and queried, not only hide the creation UI.
		if (
			$this->getDataset()->getEnumType() === ExternalSource\Type::Clickhouse
			&& !ExternalSource\SourceManager::isClickhouseConnectionsAvailable()
		)
		{
			$result->addError(new Main\Error('Clickhouse connections are not available.'));
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
		return Connector\Factory::getConnector($this->getDataset()->getEnumType(), $name, $fields, $datasetInfo);
	}

	protected function getResultTableName(): string
	{
		return $this->getDataset()->getName() ?? '';
	}

	public function getSqlTableAlias(): string
	{
		$dataset = $this->getDataset();
		if (in_array($dataset->getEnumType(), [ExternalSource\Type::Mysql, ExternalSource\Type::Pgsql], true))
		{
			return 'EXTERNAL_DATASET';
		}

		$alias = sprintf(
			'%s%s',
			strtoupper($dataset->getType() ?? ''),
			strtoupper($dataset->getExternalCode() ?? ''),
		);

		if ($dataset->getEnumType() === ExternalSource\Type::Clickhouse)
		{
			// The alias prefixes every field expression unquoted, so it has to stay a bare identifier
			// whatever the table is called.
			return (string)preg_replace('/[^A-Z0-9_]+/', '_', $alias);
		}

		return $alias;
	}

	protected function getConnectionTableName(): string
	{
		$tableName = $this->getDataset()->getExternalCode() ?? '';
		if (
			in_array($this->getDataset()->getEnumType(), [ExternalSource\Type::Mysql, ExternalSource\Type::Pgsql], true)
			&& ($tableName === '' || str_contains($tableName, "\0"))
		)
		{
			return '';
		}

		if ($this->getDataset()->getEnumType() === ExternalSource\Type::Clickhouse)
		{
			// The name goes into FROM as is, and ClickHouse allows names the unquoted syntax cannot
			// carry - spaces, dashes, reserved words - so everything selectable stays queryable.
			return '`' . str_replace('`', '``', $tableName) . '`';
		}

		return match ($this->getDataset()->getEnumType()) {
			ExternalSource\Type::Mysql => '`' . str_replace('`', '``', $tableName) . '`',
			ExternalSource\Type::Pgsql => '"' . str_replace('"', '""', strtolower($tableName)) . '"',
			default => $tableName,
		};
	}

	protected function getField(BIConnector\ExternalSource\Internal\ExternalDatasetField $datasetField): BIConnector\DataSource\DatasetField
	{
		$type = $datasetField->getEnumType();
		$name = $datasetField->getName();
		$externalCode = $datasetField->getExternalCode();
		$isExternalDatabase = in_array(
			$this->getDataset()->getEnumType(),
			[ExternalSource\Type::Mysql, ExternalSource\Type::Pgsql],
			true,
		);
		if ($isExternalDatabase)
		{
			$externalCode = $this->getSqlTableAlias() . '.' . $this->quoteExternalFieldName($externalCode);
		}

		$field = match ($type) {
			BIConnector\ExternalSource\FieldType::Int => new BIConnector\DataSource\Field\IntegerField($name),
			BIConnector\ExternalSource\FieldType::String => new BIConnector\DataSource\Field\StringField($name),
			BIConnector\ExternalSource\FieldType::Double, BIConnector\ExternalSource\FieldType::Money => new BIConnector\DataSource\Field\DoubleField($name),
			BIConnector\ExternalSource\FieldType::Date => new BIConnector\DataSource\Field\DateField($name),
			BIConnector\ExternalSource\FieldType::DateTime => new BIConnector\DataSource\Field\DateTimeField($name),
			default => new BIConnector\DataSource\Field\StringField($name),
		};

		$field->setExpression($externalCode, isPrepared: $isExternalDatabase);
		$field->setDescription($datasetField->getName());
		$field->setDescriptionFull($datasetField->getName());

		return $field;
	}

	private function quoteExternalFieldName(string $fieldName): string
	{
		return match ($this->getDataset()->getEnumType()) {
			ExternalSource\Type::Mysql => '`' . str_replace('`', '``', $fieldName) . '`',
			ExternalSource\Type::Pgsql => '"' . str_replace('"', '""', strtolower($fieldName)) . '"',
			default => $fieldName,
		};
	}
}
