<?php

namespace Bitrix\Crm\Integration\BiConnector;

class LastCommunicationMapping
{
	public static function getMapping(): array
	{
		return [
			'TABLE_NAME' => 'b_crm_last_communication',
			'TABLE_ALIAS' => 'LC',
			'FIELDS' => [
				'ENTITY_TYPE_ID' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'LC.ENTITY_TYPE_ID',
					'FIELD_TYPE' => 'int',
				],
				'ENTITY_ID' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'LC.ENTITY_ID',
					'FIELD_TYPE' => 'int',
				],
				'LAST_COMMUNICATION_TIME' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'LC.LAST_COMMUNICATION_TIME',
					'FIELD_TYPE' => 'datetime',
				],
				'TYPE' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'LC.TYPE',
					'FIELD_TYPE' => 'string',
				],
				'ACTIVITY_ID' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'LC.ACTIVITY_ID',
					'FIELD_TYPE' => 'int',
				],
			],
		];
	}
}
