<?php

namespace Bitrix\Crm\Integration\BiConnector;

use Bitrix\Crm\Service\Container;
use Bitrix\Main\DB\MysqliSqlHelper;
use Bitrix\Main\DB\PgsqlSqlHelper;
use Bitrix\Main\Localization\Loc;

class EntityStageHistoryMapping
{
	public static function getMapping(MysqliSqlHelper|PgsqlSqlHelper $helper): array
	{
		$defaultCategoryName = self::getDefaultCategoryName($helper);
		$statusSemanticsSql = self::getStatusSemanticSql($helper);

		return [
			'TABLE_NAME' => 'b_crm_entity_stage_history',
			'TABLE_ALIAS' => 'ESH',
			'FIELDS' => [
				'ID' => [
					'IS_PRIMARY' => 'Y',
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'ESH.ID',
					'FIELD_TYPE' => 'int',
				],
				'TYPE_ID' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'ESH.TYPE_ID',
					'FIELD_TYPE' => 'int',
				],
				'OWNER_TYPE_ID' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'ESH.OWNER_TYPE_ID',
					'FIELD_TYPE' => 'int',
				],
				'OWNER_ID' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'ESH.OWNER_ID',
					'FIELD_TYPE' => 'int',
				],
				'DATE_CREATE' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'ESH.CREATED_TIME',
					'FIELD_TYPE' => 'datetime',
				],
				'START_DATE' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'ESH.START_DATE',
					'FIELD_TYPE' => 'date',
				],
				'END_DATE' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'ESH.END_DATE',
					'FIELD_TYPE' => 'date',
				],
				'RESPONSIBLE_BY_ID' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'ESH.RESPONSIBLE_ID',
					'FIELD_TYPE' => 'int',
				],
				'RESPONSIBLE_BY_NAME' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'if(ESH.RESPONSIBLE_ID is null, null, concat_ws(\' \', nullif(UR.NAME, \'\'), nullif(UR.LAST_NAME, \'\')))',
					'FIELD_TYPE' => 'string',
					'TABLE_ALIAS' => 'UR',
					'LEFT_JOIN' => 'LEFT JOIN b_user UR ON UR.ID = ESH.RESPONSIBLE_ID',
				],
				'RESPONSIBLE_BY' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'if(ESH.RESPONSIBLE_ID is null, null, concat_ws(\' \', concat(\'[\', ESH.RESPONSIBLE_ID, \']\'), nullif(UR.NAME, \'\'), nullif(UR.LAST_NAME, \'\')))',
					'FIELD_TYPE' => 'string',
					'TABLE_ALIAS' => 'UR',
					'LEFT_JOIN' => 'LEFT JOIN b_user UR ON UR.ID = ESH.RESPONSIBLE_ID',
				],
				'RESPONSIBLE_BY_DEPARTMENT' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'DR.VALUE_STR',
					'FIELD_TYPE' => 'string',
					'TABLE_ALIAS' => 'DR',
					'LEFT_JOIN' => 'LEFT JOIN b_biconnector_dictionary_data DR ON DR.DICTIONARY_ID = ' . \Bitrix\BIConnector\Dictionary::USER_DEPARTMENT . ' AND DR.VALUE_ID = ESH.RESPONSIBLE_ID',
				],
				'CATEGORY_ID' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'ESH.CATEGORY_ID',
					'FIELD_TYPE' => 'int',
				],
				'CATEGORY_NAME' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'if(ESH.CATEGORY_ID is null, null, concat_ws(\' \', ifnull(DC.NAME, \'' . $defaultCategoryName . '\')))',
					'FIELD_TYPE' => 'string',
					'TABLE_ALIAS' => 'DC',
					'LEFT_JOIN' => 'LEFT JOIN b_crm_item_category DC ON DC.ID = ESH.CATEGORY_ID',
				],
				'CATEGORY' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'if(ESH.CATEGORY_ID is null, null, concat_ws(\' \', concat(\'[\', ESH.CATEGORY_ID, \']\'), ifnull(DC.NAME, \'' . $defaultCategoryName . '\')))',
					'FIELD_TYPE' => 'string',
					'TABLE_ALIAS' => 'DC',
					'LEFT_JOIN' => 'LEFT JOIN b_crm_item_category DC ON DC.ID = ESH.CATEGORY_ID',
				],
				'STAGE_SEMANTIC_ID' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'ESH.STAGE_SEMANTIC_ID',
					'FIELD_TYPE' => 'string',
				],
				'STAGE_SEMANTIC' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => str_replace('#FIELD_NAME#', 'STAGE_SEMANTIC_ID', $statusSemanticsSql),
					'FIELD_TYPE' => 'string',
				],
				'STAGE_ID' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'ESH.STAGE_ID',
					'FIELD_TYPE' => 'string',
				],
				'STAGE_NAME' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'S.NAME',
					'FIELD_TYPE' => 'string',
					'TABLE_ALIAS' => 'S',
					'LEFT_JOIN' => 'LEFT JOIN b_crm_status S ON S.STATUS_ID = ESH.STAGE_ID',
				],
				'STAGE' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'if(ESH.STAGE_ID is null, null, concat_ws(\' \', concat(\'[\', ESH.STAGE_ID, \']\'), nullif(S.NAME, \'\')))',
					'FIELD_TYPE' => 'string',
					'TABLE_ALIAS' => 'S',
					'LEFT_JOIN' => 'LEFT JOIN b_crm_status S ON S.STATUS_ID = ESH.STAGE_ID',
				],
			],
		];
	}

	private static function getDefaultCategoryName(MysqliSqlHelper|PgsqlSqlHelper $helper): string
	{
		Container::getInstance()->getLocalization()->loadMessages();
		$name = Loc::getMessage('CRM_TYPE_CATEGORY_DEFAULT_NAME');

		return $helper->forSql($name);
	}

	private static function getStatusSemanticSql(MysqliSqlHelper|PgsqlSqlHelper $helper): string
	{
		$statusSemanticsForSql = [];
		$statusSemantics = \Bitrix\Crm\PhaseSemantics::getAllDescriptions();
		foreach ($statusSemantics as $id => $value)
		{
			if ($id)
			{
				$statusSemanticsForSql[] = 'when #FIELD_NAME# = \'' . $helper->forSql($id) . '\' then \'' . $helper->forSql($value) . '\'';
			}
			else
			{
				$statusSemanticsForSql[] = 'when #FIELD_NAME# is null or #FIELD_NAME# = \'\' then \'' . $helper->forSql($value) . '\'';
			}
		}

		return 'case ' . implode("\n", $statusSemanticsForSql) . ' else null end';
	}
}
