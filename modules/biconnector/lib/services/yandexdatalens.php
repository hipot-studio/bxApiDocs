<?php
namespace Bitrix\BIConnector\Services;

use Bitrix\BIConnector\DataSourceConnector\YandexDataLensFieldDto;
use Bitrix\BIConnector\DataSourceConnector\FieldDto;
use Bitrix\BIConnector\Service;

class YandexDataLens extends MicrosoftPowerBI
{
	private const CONVERT_TO_DATE = 'date';
	private const CONVERT_TO_SMALLEST_ID = 'smallestId';
	private const DATE_LENGTH = 10;
	private const ID_SEPARATOR = ', ';

	/**
	 * The DataLens connector types columns by its own static schema and never reads ?desc.
	 * It types these columns as date and integer, while we send a datetime and a list of IDs.
	 */
	private const SCHEMA_MISMATCHED_COLUMNS = [
		'task' => [
			'DATE_START' => self::CONVERT_TO_DATE,
			'DEADLINE' => self::CONVERT_TO_DATE,
			'START_DATE_PLAN' => self::CONVERT_TO_DATE,
			'END_DATE_PLAN' => self::CONVERT_TO_DATE,
			'DEPENDS_ON' => self::CONVERT_TO_SMALLEST_ID,
		],
		'task_elapsed_time' => [
			'DATE_START' => self::CONVERT_TO_DATE,
		],
		'task_efficiency' => [
			'DATETIME_REPAIR' => self::CONVERT_TO_DATE,
		],
	];

	protected static $serviceId = 'datalens';

	/**
	 * @inheritDoc
	 */
	public static function validateDashboardUrl(\Bitrix\Main\Event $event)
	{
		$url = $event->getParameters()[0];
		$uri = new \Bitrix\Main\Web\Uri($url);
		$isUrlOk =
			($uri->getScheme() === 'https')
			&& ($uri->getHost() === 'datalens.yandex.ru')
		;
		$result = new \Bitrix\Main\EventResult(\Bitrix\Main\EventResult::SUCCESS, ($isUrlOk ? 3 : 0));
		return $result;
	}

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

		return new YandexDataLensFieldDto(
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
	 * @inheritDoc
	 */
	protected function getOutputRowConverter(string $tableName, array $fields): ?\Closure
	{
		$mismatchedColumns = self::SCHEMA_MISMATCHED_COLUMNS[$tableName] ?? [];
		$conversions = [];
		foreach ($fields as $index => $fieldId)
		{
			if (isset($mismatchedColumns[$fieldId]))
			{
				$conversions[$index] = $mismatchedColumns[$fieldId];
			}
		}

		if (!$conversions)
		{
			return null;
		}

		return static function (array $row) use ($conversions): array {
			foreach ($conversions as $index => $conversion)
			{
				if (is_string($row[$index]))
				{
					$row[$index] = self::convertValue($conversion, $row[$index]);
				}
			}

			return $row;
		};
	}

	private static function convertValue(string $conversion, string $value): string
	{
		// Dates are already shifted to the data timezone by SQL, so the day is their leading Y-m-d part.
		return match ($conversion)
		{
			self::CONVERT_TO_DATE => substr($value, 0, self::DATE_LENGTH),
			self::CONVERT_TO_SMALLEST_ID => self::getSmallestId($value),
		};
	}

	private static function getSmallestId(string $ids): string
	{
		$smallestId = PHP_INT_MAX;
		foreach (explode(self::ID_SEPARATOR, $ids) as $id)
		{
			$smallestId = min($smallestId, (int)$id);
		}

		return (string)$smallestId;
	}
}
