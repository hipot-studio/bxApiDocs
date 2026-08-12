<?php
namespace Bitrix\BIConnector\Integration\Main;

use Bitrix\Main\Localization\Loc;

class User
{
	/**
	 * Event handler for onBIConnectorDataSources event.
	 * Adds a key user to the second event parameter.
	 * Fills it with data to retrieve information from b_user table.
	 *
	 * @param \Bitrix\Main\Event $event Event data.
	 *
	 * @return void
	 */
	public static function onBIConnectorDataSources(\Bitrix\Main\Event $event)
	{
		$params = $event->getParameters();
		$manager = $params[0];
		$result = &$params[1];
		$languageId = $params[2];

		$eventTableName = $params[3];
		if (!empty($eventTableName) && $eventTableName !== 'user')
		{
			return;
		}

		$result['user'] = [
			'TABLE_NAME' => 'b_user',
			'TABLE_ALIAS' => 'U',
			'FILTER' => [
				'!=EXTERNAL_AUTH_ID' => \Bitrix\Main\UserTable::getExternalUserTypes(),
			],
			'FILTER_FIELDS' => [
				'EXTERNAL_AUTH_ID' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'U.EXTERNAL_AUTH_ID',
					'FIELD_TYPE' => 'string',
				],
			],
			'FIELDS' => [
				'ID' => [
					'IS_PRIMARY' => 'Y',
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'U.ID',
					'FIELD_TYPE' => 'int',
				],
				'ACTIVE' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'U.ACTIVE',
					'FIELD_TYPE' => 'string',
				],
				'NAME' => [
					'IS_METRIC' => 'N', // 'Y'
					'FIELD_NAME' => 'concat_ws(\' \', nullif(U.NAME, \'\'), nullif(U.LAST_NAME, \'\'))',
					'FIELD_TYPE' => 'string',
				],
				'DEPARTMENT' => [
					'IS_METRIC' => 'N', // 'Y'
					'FIELD_NAME' => 'D.VALUE_STR',
					'FIELD_TYPE' => 'string',
					'TABLE_ALIAS' => 'D',
					'JOIN' => 'INNER JOIN b_biconnector_dictionary_data D ON D.DICTIONARY_ID = ' . \Bitrix\BIConnector\Dictionary::USER_DEPARTMENT . ' AND D.VALUE_ID = U.ID',
					'LEFT_JOIN' => 'LEFT JOIN b_biconnector_dictionary_data D ON D.DICTIONARY_ID = ' . \Bitrix\BIConnector\Dictionary::USER_DEPARTMENT . ' AND D.VALUE_ID = U.ID',
				],

			],
		];

		$result['user']['DICTIONARY'] = [];
		if (\Bitrix\BIConnector\DictionaryManager::isAvailable(\Bitrix\BIConnector\Dictionary::USER_DEPARTMENT))
		{
			$result['user']['DICTIONARY'][] = \Bitrix\BIConnector\Dictionary::USER_DEPARTMENT;
		}
		else
		{
			unset($result['user']['FIELDS']['DEPARTMENT']);
		}

		if (
			\Bitrix\BIConnector\DictionaryManager::isAvailable(\Bitrix\BIConnector\Dictionary::USER_STRUCTURE_DEPARTMENT)
			&& \Bitrix\BIConnector\DictionaryManager::isAvailable(\Bitrix\BIConnector\Dictionary::DEPARTMENT_PARENT_AGGREGATION)
		)
		{
			$result['user']['DICTIONARY'][] = \Bitrix\BIConnector\Dictionary::USER_STRUCTURE_DEPARTMENT;
			$result['user']['DICTIONARY'][] = \Bitrix\BIConnector\Dictionary::DEPARTMENT_PARENT_AGGREGATION;

			/** @var \Bitrix\Main\DB\SqlHelper&\Bitrix\BIConnector\DB\BiSqlHelperInterface $helper */
			$helper = $manager->getDatabaseConnection()->getSqlHelper();

			$dshrJoin = 'INNER JOIN b_biconnector_dictionary_data DSHR ON DSHR.DICTIONARY_ID = ' . \Bitrix\BIConnector\Dictionary::USER_STRUCTURE_DEPARTMENT . ' AND DSHR.VALUE_ID = U.ID';
			$dshrLeftJoin = 'LEFT JOIN b_biconnector_dictionary_data DSHR ON DSHR.DICTIONARY_ID = ' . \Bitrix\BIConnector\Dictionary::USER_STRUCTURE_DEPARTMENT . ' AND DSHR.VALUE_ID = U.ID';

			$seg1OfValueStr = $helper->getSegmentByDelimiter('DSHR.VALUE_STR', ',', 1);
			$departmentJoin = 'INNER JOIN b_biconnector_dict_structure_agg AS SN ON SN.DEP_ID = ' . $helper->castToInt($seg1OfValueStr);
			$departmentLeftJoin = 'LEFT JOIN b_biconnector_dict_structure_agg AS SN ON SN.DEP_ID = ' . $helper->castToInt($seg1OfValueStr);

			$dep1IdSeg = $helper->getSegmentByDelimiter('SN.DEP_IDS', ',', 1);
			$dep2IdSeg = $helper->getSegmentByDelimiter('SN.DEP_IDS', ',', 2);
			$dep3IdSeg = $helper->getSegmentByDelimiter('SN.DEP_IDS', ',', 3);

			$dep1NameSeg = $helper->getSegmentByDelimiter('SN.DEP_NAMES', ',', 1);
			$dep2NameSeg = $helper->getSegmentByDelimiter('SN.DEP_NAMES', ',', 2);
			$dep3NameSeg = $helper->getSegmentByDelimiter('SN.DEP_NAMES', ',', 3);

			$dep1NameIdSeg = $helper->getSegmentByDelimiter('SN.DEP_NAME_IDS', ',', 1);
			$dep2NameIdSeg = $helper->getSegmentByDelimiter('SN.DEP_NAME_IDS', ',', 2);
			$dep3NameIdSeg = $helper->getSegmentByDelimiter('SN.DEP_NAME_IDS', ',', 3);

			// Guard levels 2/3: an empty DEP_IDS segment means that level is absent -> NULL.
			// Safe and equivalent to the old "seg N-1 = seg N" form because DEP_IDS is a
			// string_agg/GROUP_CONCAT of DISTINCT integer ancestor IDs from the org-structure
			// closure table b_hr_structure_node_path (no empty or duplicate segments).
			$dep2Guard = "CASE WHEN {$dep2IdSeg} = '' THEN NULL ELSE";
			$dep3Guard = "CASE WHEN {$dep3IdSeg} = '' THEN NULL ELSE";
			$guardEnd = 'END';

			$result['user']['FIELDS'] = array_merge($result['user']['FIELDS'], [
				'DEPARTMENT_IDS' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'DSHR.VALUE_STR',
					'FIELD_TYPE' => 'string',
					'JOIN' => $dshrJoin,
					'LEFT_JOIN' => $dshrLeftJoin,
				],
				'DEPARTMENT_ID' => [
					'FIELD_NAME' => 'SN.DEP_ID',
					'FIELD_TYPE' => 'string',
					'JOIN' => [
						$dshrJoin,
						$departmentJoin,
					],
					'LEFT_JOIN' => [
						$dshrLeftJoin,
						$departmentLeftJoin,
					],
				],
				'DEPARTMENT_NAME' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'SN.DEP_NAME',
					'FIELD_TYPE' => 'string',
					'JOIN' => [
						$dshrJoin,
						$departmentJoin,
					],
					'LEFT_JOIN' => [
						$dshrLeftJoin,
						$departmentLeftJoin,
					],
				],
				'DEPARTMENT_ID_NAME' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => $helper->getConcatFunction("'['", 'SN.DEP_ID', "'] '", 'SN.DEP_NAME'),
					'FIELD_TYPE' => 'string',
					'JOIN' => [
						$dshrJoin,
						$departmentJoin,
					],
					'LEFT_JOIN' => [
						$dshrLeftJoin,
						$departmentLeftJoin,
					],
				],
				'DEP1' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => $dep1NameSeg,
					'FIELD_TYPE' => 'string',
					'JOIN' => [
						$dshrJoin,
						$departmentJoin,
					],
					'LEFT_JOIN' => [
						$dshrLeftJoin,
						$departmentLeftJoin,
					],
				],
				'DEP2' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => "{$dep2Guard} {$dep2NameSeg} {$guardEnd}",
					'FIELD_TYPE' => 'string',
					'JOIN' => [
						$dshrJoin,
						$departmentJoin,
					],
					'LEFT_JOIN' => [
						$dshrLeftJoin,
						$departmentLeftJoin,
					],
				],
				'DEP3' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => "{$dep3Guard} {$dep3NameSeg} {$guardEnd}",
					'FIELD_TYPE' => 'string',
					'JOIN' => [
						$dshrJoin,
						$departmentJoin,
					],
					'LEFT_JOIN' => [
						$dshrLeftJoin,
						$departmentLeftJoin,
					],
				],
				'DEP1_ID' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => $dep1IdSeg,
					'FIELD_TYPE' => 'string',
					'JOIN' => [
						$dshrJoin,
						$departmentJoin,
					],
					'LEFT_JOIN' => [
						$dshrLeftJoin,
						$departmentLeftJoin,
					],
				],
				'DEP2_ID' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => "{$dep2Guard} {$dep2IdSeg} {$guardEnd}",
					'FIELD_TYPE' => 'string',
					'JOIN' => [
						$dshrJoin,
						$departmentJoin,
					],
					'LEFT_JOIN' => [
						$dshrLeftJoin,
						$departmentLeftJoin,
					],
				],
				'DEP3_ID' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => "{$dep3Guard} {$dep3IdSeg} {$guardEnd}",
					'FIELD_TYPE' => 'string',
					'JOIN' => [
						$dshrJoin,
						$departmentJoin,
					],
					'LEFT_JOIN' => [
						$dshrLeftJoin,
						$departmentLeftJoin,
					],
				],
				'DEP1_N' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => $dep1NameIdSeg,
					'FIELD_TYPE' => 'string',
					'JOIN' => [
						$dshrJoin,
						$departmentJoin,
					],
					'LEFT_JOIN' => [
						$dshrLeftJoin,
						$departmentLeftJoin,
					],
				],
				'DEP2_N' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => "{$dep2Guard} {$dep2NameIdSeg} {$guardEnd}",
					'FIELD_TYPE' => 'string',
					'JOIN' => [
						$dshrJoin,
						$departmentJoin,
					],
					'LEFT_JOIN' => [
						$dshrLeftJoin,
						$departmentLeftJoin,
					],
				],
				'DEP3_N' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => "{$dep3Guard} {$dep3NameIdSeg} {$guardEnd}",
					'FIELD_TYPE' => 'string',
					'JOIN' => [
						$dshrJoin,
						$departmentJoin,
					],
					'LEFT_JOIN' => [
						$dshrLeftJoin,
						$departmentLeftJoin,
					],
				],
			]);
		}

		$messages = Loc::loadLanguageFile(__FILE__, $languageId);
		$result['user']['TABLE_DESCRIPTION'] = $messages['MAIN_BIC_USER_TABLE'] ?: 'user';
		$result['user']['TABLE_DESCRIPTION_FULL'] = $messages['MAIN_BIC_USER_TABLE_DESCRIPTION_FULL'] ?? '';
		foreach ($result['user']['FIELDS'] as $fieldCode => &$fieldInfo)
		{
			$fieldInfo['FIELD_DESCRIPTION'] = !empty($messages['MAIN_BIC_USER_FIELD_' . $fieldCode]) ? $messages['MAIN_BIC_USER_FIELD_' . $fieldCode] : $fieldCode;
			if (!$fieldInfo['FIELD_DESCRIPTION'])
			{
				$fieldInfo['FIELD_DESCRIPTION'] = $fieldCode;
			}

			$fieldInfo['FIELD_DESCRIPTION_FULL'] = !empty($messages['MAIN_BIC_USER_FIELD_' . $fieldCode . '_FULL']) ? $messages['MAIN_BIC_USER_FIELD_' . $fieldCode . '_FULL'] : '';
		}
		unset($fieldInfo);
	}
}
