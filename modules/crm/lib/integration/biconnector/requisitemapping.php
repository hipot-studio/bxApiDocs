<?php

namespace Bitrix\Crm\Integration\BiConnector;

class RequisiteMapping
{
	private const COLUMNS = [
		'ID',
		'ENTITY_TYPE_ID',
		'ENTITY_ID',
		'PRESET_ID',
		'DATE_CREATE',
		'DATE_MODIFY',
		'CREATED_BY_ID',
		'MODIFY_BY_ID',
		'NAME',
		'CODE',
		'XML_ID',
		'ORIGINATOR_ID',
		'ACTIVE',
		'ADDRESS_ONLY',
		'SORT',
		'RQ_NAME',
		'RQ_FIRST_NAME',
		'RQ_LAST_NAME',
		'RQ_SECOND_NAME',
		'RQ_COMPANY_ID',
		'RQ_COMPANY_NAME',
		'RQ_COMPANY_FULL_NAME',
		'RQ_COMPANY_REG_DATE',
		'RQ_DIRECTOR',
		'RQ_ACCOUNTANT',
		'RQ_CEO_NAME',
		'RQ_CEO_WORK_POS',
		'RQ_CONTACT',
		'RQ_EMAIL',
		'RQ_PHONE',
		'RQ_FAX',
		'RQ_IDENT_DOC',
		'RQ_IDENT_DOC_SER',
		'RQ_IDENT_DOC_NUM',
		'RQ_IDENT_DOC_PERS_NUM',
		'RQ_IDENT_DOC_DATE',
		'RQ_IDENT_DOC_ISSUED_BY',
		'RQ_IDENT_DOC_DEP_CODE',
		'RQ_INN',
		'RQ_KPP',
		'RQ_USRLE',
		'RQ_IFNS',
		'RQ_OGRN',
		'RQ_OGRNIP',
		'RQ_OKPO',
		'RQ_OKTMO',
		'RQ_OKVED',
		'RQ_EDRPOU',
		'RQ_DRFO',
		'RQ_KBE',
		'RQ_IIN',
		'RQ_BIN',
		'RQ_ST_CERT_SER',
		'RQ_ST_CERT_NUM',
		'RQ_ST_CERT_DATE',
		'RQ_VAT_PAYER',
		'RQ_VAT_ID',
		'RQ_VAT_CERT_SER',
		'RQ_VAT_CERT_NUM',
		'RQ_VAT_CERT_DATE',
		'RQ_RESIDENCE_COUNTRY',
		'RQ_BASE_DOC',
		'RQ_REGON',
		'RQ_KRS',
		'RQ_PESEL',
		'RQ_LEGAL_FORM',
		'RQ_SIRET',
		'RQ_SIREN',
		'RQ_CAPITAL',
		'RQ_RCS',
		'RQ_CNPJ',
		'RQ_STATE_REG',
		'RQ_MNPL_REG',
		'RQ_CPF',
		'RQ_IDENT_TYPE',
		'RQ_TAX_REGIME',
		'RQ_SIGNATURE',
		'RQ_STAMP',
	];

	private const INTEGER_COLUMNS = [
		'ID',
		'ENTITY_TYPE_ID',
		'ENTITY_ID',
		'PRESET_ID',
		'CREATED_BY_ID',
		'MODIFY_BY_ID',
		'SORT',
		'RQ_SIGNATURE',
		'RQ_STAMP',
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
				'IS_METRIC' => 'N',
				'FIELD_NAME' => 'R.' . $column,
				'FIELD_TYPE' => self::getFieldType($column),
			];
			if ($column === 'ID')
			{
				$field['IS_PRIMARY'] = 'Y';
			}

			$fields[$column] = $field;
		}

		return [
			'TABLE_NAME' => 'b_crm_requisite',
			'TABLE_ALIAS' => 'R',
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
