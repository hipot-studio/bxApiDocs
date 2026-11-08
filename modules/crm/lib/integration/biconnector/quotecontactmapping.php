<?php

namespace Bitrix\Crm\Integration\BiConnector;

class QuoteContactMapping
{
	public static function getMapping(): array
	{
		return [
			'TABLE_NAME' => 'b_crm_quote_contact',
			'TABLE_ALIAS' => 'QC',
			'FIELDS' => [
				'QUOTE_ID' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'QC.QUOTE_ID',
					'FIELD_TYPE' => 'int',
				],
				'CONTACT_ID' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'QC.CONTACT_ID',
					'FIELD_TYPE' => 'int',
				],
				'SORT' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'QC.SORT',
					'FIELD_TYPE' => 'int',
				],
				'IS_PRIMARY' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'QC.IS_PRIMARY',
					'FIELD_TYPE' => 'string',
				],
			],
		];
	}
}
