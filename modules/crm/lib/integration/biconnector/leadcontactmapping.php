<?php

namespace Bitrix\Crm\Integration\BiConnector;

class LeadContactMapping
{
	public static function getMapping(): array
	{
		return [
			'TABLE_NAME' => 'b_crm_lead_contact',
			'TABLE_ALIAS' => 'LC',
			'FIELDS' => [
				'LEAD_ID' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'LC.LEAD_ID',
					'FIELD_TYPE' => 'int',
				],
				'CONTACT_ID' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'LC.CONTACT_ID',
					'FIELD_TYPE' => 'int',
				],
				'SORT' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'LC.SORT',
					'FIELD_TYPE' => 'int',
				],
				'IS_PRIMARY' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'LC.IS_PRIMARY',
					'FIELD_TYPE' => 'string',
				],
			],
		];
	}
}
