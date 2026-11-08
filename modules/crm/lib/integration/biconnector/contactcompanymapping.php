<?php

namespace Bitrix\Crm\Integration\BiConnector;

class ContactCompanyMapping
{
	public static function getMapping(): array
	{
		return [
			'TABLE_NAME' => 'b_crm_contact_company',
			'TABLE_ALIAS' => 'CC',
			'FIELDS' => [
				'CONTACT_ID' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'CC.CONTACT_ID',
					'FIELD_TYPE' => 'int',
				],
				'COMPANY_ID' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'CC.COMPANY_ID',
					'FIELD_TYPE' => 'int',
				],
				'SORT' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'CC.SORT',
					'FIELD_TYPE' => 'int',
				],
				'IS_PRIMARY' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'CC.IS_PRIMARY',
					'FIELD_TYPE' => 'string',
				],
			],
		];
	}
}
