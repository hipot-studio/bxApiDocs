<?php

namespace Bitrix\Crm\Integration\BiConnector;

class BankDetailMapping
{
	private const COLUMNS = [
		'ID',
		'ENTITY_TYPE_ID',
		'ENTITY_ID',
		'COUNTRY_ID',
		'DATE_CREATE',
		'DATE_MODIFY',
		'CREATED_BY_ID',
		'MODIFY_BY_ID',
		'NAME',
		'CODE',
		'XML_ID',
		'ORIGINATOR_ID',
		'ACTIVE',
		'SORT',
		'RQ_BANK_NAME',
		'RQ_BANK_CODE',
		'RQ_BANK_ADDR',
		'RQ_BANK_ROUTE_NUM',
		'RQ_BIK',
		'RQ_MFO',
		'RQ_ACC_NAME',
		'RQ_ACC_NUM',
		'RQ_ACC_TYPE',
		'RQ_IIK',
		'RQ_ACC_CURRENCY',
		'RQ_COR_ACC_NUM',
		'RQ_IBAN',
		'RQ_SWIFT',
		'RQ_BIC',
		'RQ_CODEB',
		'RQ_CODEG',
		'RQ_RIB',
		'RQ_AGENCY_NAME',
		'COMMENTS',
	];

	private const INTEGER_COLUMNS = [
		'ID',
		'ENTITY_TYPE_ID',
		'ENTITY_ID',
		'COUNTRY_ID',
		'CREATED_BY_ID',
		'MODIFY_BY_ID',
		'SORT',
	];

	private const DATETIME_COLUMNS = [
		'DATE_CREATE',
		'DATE_MODIFY',
	];

	public static function getMapping(): array
	{
		$fields = [];
		foreach (self::COLUMNS as $column)
		{
			$field = [
				'FIELD_NAME' => 'BD.' . $column,
				'FIELD_TYPE' => self::getFieldType($column),
				'IS_METRIC' => 'N',
			];
			if ($column === 'ID')
			{
				$field['IS_PRIMARY'] = 'Y';
			}

			$fields[$column] = $field;
		}

		return [
			'TABLE_NAME' => 'b_crm_bank_detail',
			'TABLE_ALIAS' => 'BD',
			'FIELDS' => $fields,
		];
	}

	private static function getFieldType(string $column): string
	{
		if (in_array($column, self::INTEGER_COLUMNS, true))
		{
			return 'int';
		}

		if (in_array($column, self::DATETIME_COLUMNS, true))
		{
			return 'datetime';
		}

		return 'string';
	}
}
