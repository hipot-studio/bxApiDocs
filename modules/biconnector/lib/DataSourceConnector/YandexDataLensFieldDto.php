<?php

namespace Bitrix\BIConnector\DataSourceConnector;

use Bitrix\BIConnector\Superset\Config\DatasetSettings;
use Bitrix\Main\UserField\Types\BooleanType;
use Bitrix\Main\UserField\Types\DateTimeType;
use Bitrix\Main\UserField\Types\DateType;
use Bitrix\Main\UserField\Types\DoubleType;
use Bitrix\Main\UserField\Types\IntegerType;

final class YandexDataLensFieldDto extends FieldDto
{
	/**
	 * Returns internal type external representation.
	 *
	 * @param string $internalType Internal (CSQLWhere) type.
	 * @return string
	 * @see \CSQLWhere
	 */
	protected function mapType(string $internalType): string
	{
		return match ($internalType)
		{
			'int' => 'INT',
			'double' => 'DOUBLE',
			'date' => 'DATE',
			'datetime' => 'DATETIME',
			'bool' => 'BOOLEAN',
			default => 'STRING',
		};
	}
}
