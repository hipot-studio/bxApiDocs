<?php

namespace Bitrix\Crm\Integration\BiConnector;

class DealContactMapping
{
	public static function getMapping(): array
	{
		return [
			'TABLE_NAME' => 'b_crm_deal_contact',
			'TABLE_ALIAS' => 'DC',
			'FIELDS' => [
				'DEAL_ID' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'DC.DEAL_ID',
					'FIELD_TYPE' => 'int',
				],
				'CONTACT_ID' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'DC.CONTACT_ID',
					'FIELD_TYPE' => 'int',
				],
				'SORT' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'DC.SORT',
					'FIELD_TYPE' => 'int',
				],
				'IS_PRIMARY' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'DC.IS_PRIMARY',
					'FIELD_TYPE' => 'string',
				],
			],
		];
	}
}
