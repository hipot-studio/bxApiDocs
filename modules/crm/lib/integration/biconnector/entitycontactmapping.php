<?php

namespace Bitrix\Crm\Integration\BiConnector;

class EntityContactMapping
{
	public static function getMapping(): array
	{
		return [
			'TABLE_NAME' => 'b_crm_entity_contact',
			'TABLE_ALIAS' => 'EC',
			'FIELDS' => [
				'ENTITY_TYPE_ID' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'EC.ENTITY_TYPE_ID',
					'FIELD_TYPE' => 'int',
				],
				'ENTITY_ID' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'EC.ENTITY_ID',
					'FIELD_TYPE' => 'int',
				],
				'CONTACT_ID' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'EC.CONTACT_ID',
					'FIELD_TYPE' => 'int',
				],
				'SORT' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'EC.SORT',
					'FIELD_TYPE' => 'int',
				],
				'IS_PRIMARY' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'EC.IS_PRIMARY',
					'FIELD_TYPE' => 'string',
				],
			],
		];
	}
}
