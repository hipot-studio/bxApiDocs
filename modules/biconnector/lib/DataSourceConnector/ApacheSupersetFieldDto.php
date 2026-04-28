<?php

namespace Bitrix\BIConnector\DataSourceConnector;

use Bitrix\BIConnector\Superset\Config\DatasetSettings;
use Bitrix\Main\UserField\Types\BooleanType;
use Bitrix\Main\UserField\Types\DateTimeType;
use Bitrix\Main\UserField\Types\DateType;
use Bitrix\Main\UserField\Types\DoubleType;
use Bitrix\Main\UserField\Types\IntegerType;

final class ApacheSupersetFieldDto extends FieldDto
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
		if (
			str_starts_with($this->id, 'UF_')
			&& !$this->isSystem
		)
		{
			if (!DatasetSettings::isTypingEnabled())
			{
				return 'STRING';
			}

			return match ($internalType)
			{
				'int', IntegerType::USER_TYPE_ID => 'INT',
				'double', DoubleType::USER_TYPE_ID => 'DOUBLE',
				'date', DateType::USER_TYPE_ID => 'DATE',
				'datetime', DateTimeType::USER_TYPE_ID => 'DATETIME',
				'bool', BooleanType::USER_TYPE_ID => 'BOOLEAN',
				default => 'STRING',
			};
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
