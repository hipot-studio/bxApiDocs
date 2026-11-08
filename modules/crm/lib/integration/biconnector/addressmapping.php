<?php

namespace Bitrix\Crm\Integration\BiConnector;

use Bitrix\Crm\EntityAddressType;
use Bitrix\Main\DB\MysqliSqlHelper;
use Bitrix\Main\DB\PgsqlSqlHelper;
use Bitrix\Main\Localization\Loc;

class AddressMapping
{
	public static function getMapping(MysqliSqlHelper|PgsqlSqlHelper $helper, string $languageId): array
	{
		$messages = Loc::loadLanguageFile(dirname(__DIR__, 2) . '/entityaddresstype.php', $languageId);
		$cases = [];
		foreach (EntityAddressType::getAllIDs() as $typeId)
		{
			$message = $messages['CRM_ADDRESS_TYPE_' . EntityAddressType::resolveName($typeId)] ?? null;
			if ($message !== null)
			{
				$cases[] = 'WHEN ' . $typeId . " THEN '" . $helper->forSql($message) . "'";
			}
		}
		$typeName = $cases === [] ? 'NULL' : 'CASE A.TYPE_ID ' . implode(' ', $cases) . ' ELSE NULL END';
		$type = $helper->getConcatFunction(
			"'['",
			'A.TYPE_ID',
			"']'",
			'COALESCE(' . $helper->getConcatFunction("' '", $typeName) . ", '')",
		);

		return [
			'TABLE_NAME' => 'b_crm_addr',
			'TABLE_ALIAS' => 'A',
			'FIELDS' => [
				'ID' => [
					'IS_PRIMARY' => 'Y',
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'A.ID',
					'FIELD_TYPE' => 'int',
				],
				'TYPE_ID' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'A.TYPE_ID',
					'FIELD_TYPE' => 'int',
				],
				'TYPE_NAME' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => $typeName,
					'FIELD_TYPE' => 'string',
				],
				'TYPE' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => $type,
					'FIELD_TYPE' => 'string',
				],
				'ENTITY_TYPE_ID' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'A.ENTITY_TYPE_ID',
					'FIELD_TYPE' => 'int',
				],
				'ENTITY_ID' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'A.ENTITY_ID',
					'FIELD_TYPE' => 'int',
				],
				'ANCHOR_TYPE_ID' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'A.ANCHOR_TYPE_ID',
					'FIELD_TYPE' => 'int',
				],
				'ANCHOR_ID' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'A.ANCHOR_ID',
					'FIELD_TYPE' => 'int',
				],
				'ADDRESS_1' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'A.ADDRESS_1',
					'FIELD_TYPE' => 'string',
				],
				'ADDRESS_2' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'A.ADDRESS_2',
					'FIELD_TYPE' => 'string',
				],
				'CITY' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'A.CITY',
					'FIELD_TYPE' => 'string',
				],
				'POSTAL_CODE' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'A.POSTAL_CODE',
					'FIELD_TYPE' => 'string',
				],
				'REGION' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'A.REGION',
					'FIELD_TYPE' => 'string',
				],
				'PROVINCE' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'A.PROVINCE',
					'FIELD_TYPE' => 'string',
				],
				'COUNTRY' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'A.COUNTRY',
					'FIELD_TYPE' => 'string',
				],
				'COUNTRY_CODE' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'A.COUNTRY_CODE',
					'FIELD_TYPE' => 'string',
				],
				'IS_DEF' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'A.IS_DEF',
					'FIELD_TYPE' => 'int',
				],
			],
		];
	}
}
