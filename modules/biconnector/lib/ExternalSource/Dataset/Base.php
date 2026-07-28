<?php

namespace Bitrix\BIConnector\ExternalSource\Dataset;

use Bitrix\Main;
use Bitrix\BIConnector;

abstract class Base extends BIConnector\DataSource\BIBuilderDataset
{
	protected ?BIConnector\ExternalSource\Internal\ExternalDataset $dataset = null;

	/**
	 * @return Main\Result
	 */
	protected function onBeforeEvent(): Main\Result
	{
		$result = parent::onBeforeEvent();

		if (!BIConnector\Configuration\Feature::isExternalEntitiesEnabled())
		{
			$result->addError(new Main\Error('Feature is not enabled for this dataset'));
		}

		return $result;
	}

	public static function createDataset(
		BIConnector\ExternalSource\Internal\ExternalDataset $dataset,
		Main\DB\Connection $dataConnection,
		?string $languageId = null,
	): self
	{
		return (new static($dataConnection, $languageId))->setDataset($dataset);
	}

	protected function getDataset(): BIConnector\ExternalSource\Internal\ExternalDataset
	{
		if ($this->dataset === null)
		{
			throw new \Bitrix\Main\SystemException('Dataset is not initialized. Use createDataset() factory method.');
		}

		return $this->dataset;
	}

	protected function setDataset(BIConnector\ExternalSource\Internal\ExternalDataset $dataset): self
	{
		$this->dataset = $dataset;

		return $this;
	}

	/**
	 * @inheritDoc
	 */
	abstract protected function getResultTableName(): string;

	/**
	 * @inheritDoc
	 */
	abstract public function getSqlTableAlias(): string;

	/**
	 * @inheritDoc
	 */
	abstract protected function getConnectionTableName(): string;

	protected function getFields(): array
	{
		$result = [];

		$fields = BIConnector\ExternalSource\DatasetManager::getDatasetFieldsById($this->getDataset()->getId());
		foreach ($fields as $field)
		{
			if (!$field->getVisible())
			{
				continue;
			}

			$result[] = $this->getField($field);
		}

		return $result;
	}

	protected function getField(BIConnector\ExternalSource\Internal\ExternalDatasetField $datasetField): BIConnector\DataSource\DatasetField
	{
		$type = $datasetField->getEnumType();
		$name = $datasetField->getName();

		$filed = match ($type) {
			BIConnector\ExternalSource\FieldType::Int => new BIConnector\DataSource\Field\IntegerField($name),
			BIConnector\ExternalSource\FieldType::String => new BIConnector\DataSource\Field\StringField($name),
			BIConnector\ExternalSource\FieldType::Double, BIConnector\ExternalSource\FieldType::Money => new BIConnector\DataSource\Field\DoubleField($name),
			BIConnector\ExternalSource\FieldType::Date => new BIConnector\DataSource\Field\DateField($name),
			BIConnector\ExternalSource\FieldType::DateTime => new BIConnector\DataSource\Field\DateTimeField($name),
			default => new BIConnector\DataSource\Field\StringField($name),
		};

		$filed->setDescription($datasetField->getName());
		$filed->setDescriptionFull($datasetField->getName());

		return $filed;
	}

	protected function getTableDescription(): string
	{
		return $this->getDataset()->getDescription() ?: $this->getDataset()->getName();
	}

	protected function getConnector(string $name, BIConnector\DataSourceConnector\FieldCollection $fields, array $datasetInfo): BIConnector\DataSourceConnector\Connector\Base
	{
		return Connector\Factory::getConnector($this->getDataset()->getEnumType(), $name, $fields, $datasetInfo);
	}

	public static function onBIBuilderExternalDataSources(Main\Event $event)
	{
		$params = $event->getParameters();
		$manager = $params[0];
		$languageId = $params[2];
		$connection = $manager->getDatabaseConnection();
		if (!$connection)
		{
			return;
		}

		$result = &$params[1];
		$eventTableName = $params[3];

		$externalDatasets = BIConnector\ExternalSource\DatasetManager::getList();
		foreach ($externalDatasets as $externalDataset)
		{
			try
			{
				$dataset = Factory::getDataset($externalDataset, $connection, $languageId);

				if (!empty($eventTableName) && $dataset->getResultTableName() !== $eventTableName)
				{
					continue;
				}

				if (!$dataset->onBeforeEvent()->isSuccess())
				{
					continue;
				}

				$fields = new BIConnector\DataSourceConnector\FieldCollection();
				foreach ($dataset->getDatasetFields() as $field)
				{
					$fields->add($dataset->prepareFieldDto($field));
				}

				$tableName = $dataset->getResultTableName();
				$result[$tableName] = $dataset->getConnector($tableName, $fields, $dataset->getResult());
			}
			catch (\Exception $e)
			{
				continue;
			}
		}
	}
}
