<?php
namespace Bitrix\BIConnector\Services;

use Bitrix\BIConnector\DataSourceConnector\ApacheSupersetFieldDto;
use Bitrix\BIConnector\DataSourceConnector\Connector;
use Bitrix\BIConnector\DataSourceConnector\FieldDto;

class ApacheSuperset extends MicrosoftPowerBI
{
	protected static $serviceId = 'superset';

	/**
	 * @param string $fieldName
	 * @param array $fieldInfo
	 *
	 * @return FieldDto
	 */
	protected function prepareFieldDto(string $fieldName, array $fieldInfo): FieldDto
	{
		$type = $fieldInfo['FIELD_TYPE_EX'] ?? $fieldInfo['FIELD_TYPE'];

		$parentDto = parent::prepareFieldDto($fieldName, $fieldInfo);

		return new ApacheSupersetFieldDto(
			$parentDto->id,
			$parentDto->name,
			$parentDto->description,
			$type ?? 'string',
			$parentDto->isMetric,
			$parentDto->isPrimary,
			$parentDto->isSystem,
			$parentDto->aggregationType,
			$parentDto->groupKey,
			$parentDto->groupConcat,
			$parentDto->groupCount,
			$parentDto->isValueSplitable
		);
	}

	/**
	 * Returns all available data sources descriptions.
	 *
	 * @return array
	 */
	protected function loadDataSourceConnectors(): array
	{
		$dataSourceConnectors = parent::loadDataSourceConnectors();

		$dataSources = [];
		$event = new \Bitrix\Main\Event('biconnector', 'OnBIBuilderDataSources', [
			$this->manager,
			&$dataSources,
			$this->languageId,
		]);
		$event->send();

		foreach ($dataSources as $source)
		{
			if ($source instanceof Connector\Base)
			{
				$dataSourceConnectors[$source->getName()] = $source;
			}
		}

		return $dataSourceConnectors;
	}

	/**
	 *
	 * @deprecated
	 *
	 * Type mapping is realised into Bitrix\BIConnector\DataSourceConnector\ApacheSupersetFieldDto
	 *
	 * Returns trino supported type by internal.
	 *
	 * @param string $internalType Internal (CSQLWhere) type.
	 * @param null $fieldName Field name - ID, TITLE, UF_CRM_100 etc.
	 *
	 * @see \CSQLWhere
	 */
	protected function mapType($internalType, $fieldName = null): string
	{
		if (is_string($fieldName) && str_starts_with($fieldName, 'UF_'))
		{
			return 'STRING';
		}

		return match ($internalType)
		{
			'file', 'enum', 'int' => 'INT',
			'double' => 'DOUBLE',
			'date' => 'DATE',
			'datetime' => 'DATETIME',
			'bool' => 'BOOLEAN',
			'array_string' => 'ARRAY_STRING',
			'map_string' => 'MAP_STRING',
			default => 'STRING',
		};
	}
}
