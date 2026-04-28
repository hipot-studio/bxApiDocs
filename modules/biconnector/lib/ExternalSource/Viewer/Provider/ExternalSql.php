<?php

namespace Bitrix\BIConnector\ExternalSource\Viewer\Provider;

use Bitrix\BIConnector\ExternalSource\DatasetManager;
use Bitrix\Biconnector\ExternalSource\FieldType;
use Bitrix\BIConnector\ExternalSource\Source;
use Bitrix\BIConnector\ExternalSource;
use Bitrix\Main;
use Bitrix\Main\Localization\Loc;

final class ExternalSql implements Provider
{
	private const N_FIRST = 20;
	private int $sourceId;
	private ?array $settings = null;

	public function __construct(private readonly ExternalSource\Type $type)
	{
	}

	public function getData(): ProviderDataDto
	{
		/* @var ExternalSource\Source\ExternalSql $source */
		$source = Source\Factory::getSource($this->type, $this->sourceId);
		$datasetId = (int)($this->settings['dataset']['ID'] ?? null);
		$tableName = $this->settings['dataset']['EXTERNAL_CODE'];
		if ($datasetId)
		{
			$description = array_map(
				fn($item) => ['NAME' => $item->getName(), 'EXTERNAL_CODE' => $item->getExternalCode(), 'TYPE' => $item->getType()],
				DatasetManager::getDatasetFieldsById($datasetId)->getAll()
			);
		}
		else
		{
			$ormDescription = $source->getDescription($tableName);

			$description = [];
			/** @var \Bitrix\Main\ORM\Fields\Field $field */
			foreach ($ormDescription as $field)
			{
				$description[] = [
					'NAME' => $field->getName(),
					'EXTERNAL_CODE' => $field->getName(),
					'TYPE' => match ($field->getDataType())
					{
						'float', 'decimal' => FieldType::Double,
						'date' => FieldType::Date,
						'datetime' => FieldType::DateTime,
						'integer' => FieldType::Int,
						default => FieldType::String,
					},
				];
			}
		}

		$headers = [];
		$externalCodes = [];
		$types = [];
		$namesMap = [];
		foreach ($description as $item)
		{
			$name = $item['NAME'];
			$externalCode = $item['EXTERNAL_CODE'];
			$headers[] = $name;
			$externalCodes[] = $externalCode;
			$types[] = $item['TYPE'];
			$namesMap[$externalCode] = $externalCode;
		}

		$sourceData = $source->getFirstNData($tableName, self::N_FIRST, $namesMap);

		$rowCollection = new RowCollection();
		foreach ($sourceData as $sourceRow)
		{
			$row = new Row();

			foreach ($namesMap as $externalCode)
			{
				$row->add($sourceRow[$externalCode] ?? '');
			}

			$rowCollection->add($row);
		}

		return new ProviderDataDto(
			$headers,
			$externalCodes,
			$types,
			$rowCollection,
		);
	}

	public function setSourceId(int $sourceId): self
	{
		$this->sourceId = $sourceId;

		return $this;
	}

	public function setSettings(?array $settings): self
	{
		$this->settings = $settings;

		return $this;
	}
}
