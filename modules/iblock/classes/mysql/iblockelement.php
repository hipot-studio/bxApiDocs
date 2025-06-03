<?php

use Bitrix\Main;
use Bitrix\Main\Loader;
use Bitrix\Iblock;
use Bitrix\Iblock\IblockTable;

class CIBlockElement extends CAllIBlockElement
{
	public function prepareSql($arSelectFields=array(), $arFilter=array(), $arGroupBy=false, $arOrder=array("SORT"=>"ASC"))
	{
		global $DB;
		$connection = Main\Application::getConnection();
		$helper = $connection->getSqlHelper();
		$MAX_LOCK = (int)COption::GetOptionString("workflow","MAX_LOCK_TIME","60");
		$uid = $this->userId;

		$formatActiveDates = CPageOption::GetOptionString("iblock", "FORMAT_ACTIVE_DATES", "-") != "-";
		$shortFormatActiveDates = CPageOption::GetOptionString("iblock", "FORMAT_ACTIVE_DATES", "SHORT");

		$arIblockElementFields = array(
				"ID"=>"BE.ID",
				"TIMESTAMP_X"=>$DB->DateToCharFunction("BE.TIMESTAMP_X"),
				"TIMESTAMP_X_UNIX"=>'UNIX_TIMESTAMP(BE.TIMESTAMP_X)',
				"MODIFIED_BY"=>"BE.MODIFIED_BY",
				"DATE_CREATE"=>$DB->DateToCharFunction("BE.DATE_CREATE"),
				"DATE_CREATE_UNIX"=>'UNIX_TIMESTAMP(BE.DATE_CREATE)',
				"CREATED_BY"=>"BE.CREATED_BY",
				"IBLOCK_ID"=>"BE.IBLOCK_ID",
				"IBLOCK_SECTION_ID"=>"BE.IBLOCK_SECTION_ID",
				"ACTIVE"=>"BE.ACTIVE",
				"ACTIVE_FROM"=>(
						$formatActiveDates
						?
							$DB->DateToCharFunction("BE.ACTIVE_FROM", $shortFormatActiveDates)
						:
							"BE.ACTIVE_FROM as ACTIVE_FROM_X, case when EXTRACT(HOUR FROM BE.ACTIVE_FROM) > 0 OR EXTRACT(MINUTE FROM BE.ACTIVE_FROM) > 0 OR EXTRACT(SECOND FROM BE.ACTIVE_FROM) > 0 then ".$DB->DateToCharFunction("BE.ACTIVE_FROM", "FULL")." else ".$DB->DateToCharFunction("BE.ACTIVE_FROM", "SHORT")." end"
						),
				"ACTIVE_TO"=>(
						$formatActiveDates
						?
							$DB->DateToCharFunction("BE.ACTIVE_TO", $shortFormatActiveDates)
						:
							"case when EXTRACT(HOUR FROM BE.ACTIVE_TO) > 0 OR EXTRACT(MINUTE FROM BE.ACTIVE_TO) > 0 OR EXTRACT(SECOND FROM BE.ACTIVE_TO) > 0 then ".$DB->DateToCharFunction("BE.ACTIVE_TO", "FULL")." else ".$DB->DateToCharFunction("BE.ACTIVE_TO", "SHORT")." end"
						),
				"DATE_ACTIVE_FROM"=>(
						$formatActiveDates
						?
							$DB->DateToCharFunction("BE.ACTIVE_FROM", $shortFormatActiveDates)
						:
							"case when EXTRACT(HOUR FROM BE.ACTIVE_FROM) > 0 OR EXTRACT(MINUTE FROM BE.ACTIVE_FROM) > 0 OR EXTRACT(SECOND FROM BE.ACTIVE_FROM) > 0 then ".$DB->DateToCharFunction("BE.ACTIVE_FROM", "FULL")." else ".$DB->DateToCharFunction("BE.ACTIVE_FROM", "SHORT")." end"
						),
				"DATE_ACTIVE_TO"=>(
						$formatActiveDates
						?
							$DB->DateToCharFunction("BE.ACTIVE_TO", $shortFormatActiveDates)
						:
							"case when EXTRACT(HOUR FROM BE.ACTIVE_TO) > 0 OR EXTRACT(MINUTE FROM BE.ACTIVE_TO) > 0 OR EXTRACT(SECOND FROM BE.ACTIVE_TO) > 0 then ".$DB->DateToCharFunction("BE.ACTIVE_TO", "FULL")." else ".$DB->DateToCharFunction("BE.ACTIVE_TO", "SHORT")." end"
						),
				"SORT"=>"BE.SORT",
				"NAME"=>"BE.NAME",
				"PREVIEW_PICTURE"=>"BE.PREVIEW_PICTURE",
				"PREVIEW_TEXT"=>"BE.PREVIEW_TEXT",
				"PREVIEW_TEXT_TYPE"=>"BE.PREVIEW_TEXT_TYPE",
				"DETAIL_PICTURE"=>"BE.DETAIL_PICTURE",
				"DETAIL_TEXT"=>"BE.DETAIL_TEXT",
				"DETAIL_TEXT_TYPE"=>"BE.DETAIL_TEXT_TYPE",
				"SEARCHABLE_CONTENT"=>"BE.SEARCHABLE_CONTENT",
				"WF_STATUS_ID"=>"BE.WF_STATUS_ID",
				"WF_PARENT_ELEMENT_ID"=>"BE.WF_PARENT_ELEMENT_ID",
				"WF_LAST_HISTORY_ID"=>"BE.WF_LAST_HISTORY_ID",
				"WF_NEW"=>"BE.WF_NEW",
				"LOCK_STATUS"=>"case when BE.WF_DATE_LOCK is null then 'green' when " . $helper->addSecondsToDateTime($MAX_LOCK * 60, 'BE.WF_DATE_LOCK') . " < " . $helper->getCurrentDateTimeFunction() . " then 'green' when BE.WF_LOCKED_BY = " . $uid . " then 'yellow' else 'red' end",
				"WF_LOCKED_BY"=>"BE.WF_LOCKED_BY",
				"WF_DATE_LOCK"=>$DB->DateToCharFunction("BE.WF_DATE_LOCK"),
				"WF_COMMENTS"=>"BE.WF_COMMENTS",
				"IN_SECTIONS"=>"BE.IN_SECTIONS",
				"SHOW_COUNTER"=>"BE.SHOW_COUNTER",
				"SHOW_COUNTER_START"=>$DB->DateToCharFunction("BE.SHOW_COUNTER_START"),
				"SHOW_COUNTER_START_X"=>"BE.SHOW_COUNTER_START",
				"CODE"=>"BE.CODE",
				"TAGS"=>"BE.TAGS",
				"XML_ID"=>"BE.XML_ID",
				"EXTERNAL_ID"=>"BE.XML_ID",
				"TMP_ID"=>"BE.TMP_ID",
				'USER_NAME' => self::getUserNameSql('U'),
				'LOCKED_USER_NAME' => self::getUserNameSql('UL'),
				'CREATED_USER_NAME' => self::getUserNameSql('UC'),
				"LANG_DIR"=>"L.DIR",
				"LID"=>"B.LID",
				"IBLOCK_TYPE_ID"=>"B.IBLOCK_TYPE_ID",
				"IBLOCK_CODE"=>"B.CODE",
				"IBLOCK_NAME"=>"B.NAME",
				"IBLOCK_EXTERNAL_ID"=>"B.XML_ID",
				"DETAIL_PAGE_URL"=>"B.DETAIL_PAGE_URL",
				"LIST_PAGE_URL"=>"B.LIST_PAGE_URL",
				"CANONICAL_PAGE_URL"=>"B.CANONICAL_PAGE_URL",
				"CREATED_DATE"=>$DB->DateFormatToDB("YYYY.MM.DD", "BE.DATE_CREATE"),
				"BP_PUBLISHED"=>"case when BE.WF_STATUS_ID = 1 then 'Y' else 'N' end",
			);
		unset($shortFormatActiveDates);
		unset($formatActiveDates);

		$this->bDistinct = false;

		$this->PrepareGetList(
				$arIblockElementFields,
				$arJoinProps,

				$arSelectFields,
				$sSelect,
				$arAddSelectFields,

				$arFilter,
				$sWhere,
				$sSectionWhere,
				$arAddWhereFields,

				$arGroupBy,
				$sGroupBy,

				$arOrder,
				$arSqlOrder,
				$arAddOrderByFields
			);

		$this->arFilterIBlocks = isset($arFilter["IBLOCK_ID"])? array($arFilter["IBLOCK_ID"]): array();
		//******************FROM PART********************************************
		$sFrom = "";
		$countFrom = '';
		foreach ($arJoinProps["FPS"] as $iblock_id => $iPropCnt)
		{
			/*
			 * 123 - Iblock Identifier
			 * INNER JOIN b_iblock_element_prop_s123 FPS123 ON FPS123.IBLOCK_ELEMENT_ID = BE.ID
			 */
			$tableAlias = 'FPS' . $iPropCnt;
			$tableJoin = "\t\t\tINNER JOIN b_iblock_element_prop_s" . $iblock_id . " " . $tableAlias
				. " ON " . $tableAlias . ".IBLOCK_ELEMENT_ID = BE.ID\n"
			;
			$sFrom .= $tableJoin;
			$countFrom .= $tableJoin;

			unset($tableJoin);
			unset($tableAlias);
			$this->arFilterIBlocks[$iblock_id] = $iblock_id;
		}

		foreach ($arJoinProps["FP"] as $propID => $db_prop)
		{
			/*
			 * 123 - $db_prop['CNT']
			 *
			 * $db_prop['bFullJoin'] === true and
			 * 		$propID is int (property id)
			 * 			INNER JOIN b_iblock_property FP123 ON FP123.IBLOCK_ID = D.ID AND FP123.ID = $propID
			 * 		$propID is string (property code)
			 * 			INNER JOIN b_iblock_property FP123 ON FP123.IBLOCK_ID = D.ID AND FP123.CODE = '$propID'
			 *
			 * $db_prop['bFullJoin'] === false and
			 * 		$propID is int (property id)
			 * 			LEFT JOIN b_iblock_property FP123 ON FP123.IBLOCK_ID = D.ID AND FP123.ID = $propID
			 * 		$propID is string (property code)
			 * 			LEFT JOIN b_iblock_property FP123 ON FP123.IBLOCK_ID = D.ID AND FP123.CODE = '$propID'
			 */
			$tableAlias = 'FP' . $db_prop['CNT'];
			$joinType = $db_prop['bFullJoin'] ? 'INNER JOIN' : 'LEFT JOIN';
			$tableJoin = "\t\t\t" . $joinType . " b_iblock_property " . $tableAlias
				. " ON " . $tableAlias . ".IBLOCK_ID = B.ID AND "
				. (
					(int)$propID > 0
						? $tableAlias . ".ID=" . (int)$propID . "\n"
						: $tableAlias . ".CODE='" . $DB->ForSQL($propID, 200) . "'\n"
				)
			;
			$sFrom .= $tableJoin;
			if (self::useCountJoin($db_prop))
			{
				$countFrom .= $tableJoin;
			}

			unset($tableJoin);
			unset($joinType);
			unset($tableAlias);

			if (isset($db_prop["IBLOCK_ID"]) && $db_prop["IBLOCK_ID"])
			{
				$this->arFilterIBlocks[$db_prop["IBLOCK_ID"]] = $db_prop["IBLOCK_ID"];
			}
		}

		foreach ($arJoinProps['FPV'] as $db_prop)
		{
			if ($db_prop['MULTIPLE'] === 'Y')
			{
				$this->bDistinct = true;
			}

			if ($db_prop['VERSION'] == IblockTable::PROPERTY_STORAGE_SEPARATE) // 'VESRION' is string
			{
				$tableName = 'b_iblock_element_prop_m' . $db_prop['IBLOCK_ID'];
			}
			else
			{
				$tableName = 'b_iblock_element_property';
			}

			/*
			 * 123 - $db_prop['CNT']
			 * $strTable - b_iblock_element_property or b_iblock_element_prop_m{IBLOCK_ID}
			 *
			 * $db_prop['bFullJoin'] === true
			 * 		INNER JOIN {$strTable} FPV123 ON FPV123.IBLOCK_PROPERTY_ID = FP{$db_prop['JOIN']}.ID
						AND FPV123.IBLOCK_ELEMENT_ID = BE.ID
			 * $db_prop['bFullJoin'] === false
			 * 		LEFT JOIN {$strTable} FPV123 ON FPV123.IBLOCK_PROPERTY_ID = FP{$db_prop['JOIN']}.ID
						AND FPV123.IBLOCK_ELEMENT_ID = BE.ID
			 */
			$tableAlias = 'FPV' . $db_prop['CNT'];
			$joinType = $db_prop['bFullJoin'] ? 'INNER JOIN' : 'LEFT JOIN';
			$tableJoin = "\t\t\t" . $joinType . " " . $tableName . " " . $tableAlias
				. " ON " . $tableAlias . ".IBLOCK_PROPERTY_ID = FP" . $db_prop["JOIN"] . ".ID"
				. " AND " . $tableAlias .".IBLOCK_ELEMENT_ID = BE.ID\n"
			;
			$sFrom .= $tableJoin;
			if (self::useCountJoin($db_prop))
			{
				$countFrom .= $tableJoin;
			}

			unset($tableJoin);
			unset($joinType);
			unset($tableAlias);
			unset($tableName);

			if (isset($db_prop["IBLOCK_ID"]) && $db_prop["IBLOCK_ID"])
			{
				$this->arFilterIBlocks[$db_prop["IBLOCK_ID"]] = $db_prop["IBLOCK_ID"];
			}
		}

		foreach ($arJoinProps["FPEN"] as $db_prop)
		{
			/*
			 * 123 - $db_prop['CNT']
			 * if commont storage and single property
			 * 		$db_prop['bFullJoin'] === true
			 * 			INNER JOIN b_iblock_property_enum FPEN123 ON FPEN123.PROPERTY_ID = {$db_prop['ORIG_ID']}
			 * 				AND FPS{$db_prop['JOIN']}.PROPERTY_{$db_prop['ORIG_ID']} = FPEN123.ID
			 * 		$db_prop['bFullJoin'] === false
			 * 			LEFT JOIN b_iblock_property_enum FPEN123 ON FPEN123.PROPERTY_ID = {$db_prop['ORIG_ID']}
			 * 				AND FPS{$db_prop['JOIN']}.PROPERTY_{$db_prop['ORIG_ID']} = FPEN123.ID
			 * else
			 * 		$db_prop['bFullJoin'] === true
			 * 			INNER JOIN b_iblock_property_enum FPEN123 ON FPEN123.PROPERTY_ID = FPV{$db_prop['JOIN']}.IBLOCK_PROPERTY_ID
			 * 				AND FPV{$db_prop['JOIN']}.VALUE_ENUM = FPEN123.ID
			 * 		$db_prop['bFullJoin'] === false
			 * 			LEFT JOIN b_iblock_property_enum FPEN123 ON FPEN123.PROPERTY_ID = FPV{$db_prop['JOIN']}.IBLOCK_PROPERTY_ID
			 * 				AND FPV{$db_prop['JOIN']}.VALUE_ENUM = FPEN123.ID
			 */
			$tableName = Iblock\PropertyEnumerationTable::getTableName();
			$tableAlias = 'FPEN' . $db_prop['CNT'];
			$joinType = $db_prop['bFullJoin'] ? 'INNER JOIN' : 'LEFT JOIN';
			if ($db_prop['VERSION'] == IblockTable::PROPERTY_STORAGE_SEPARATE && $db_prop['MULTIPLE'] === 'N') // 'VESRION' is string
			{
				$tableJoin = "\t\t\t" . $joinType . " " . $tableName . " " . $tableAlias
					. " ON " . $tableAlias . ".PROPERTY_ID = " . $db_prop["ORIG_ID"]
					. " AND FPS" . $db_prop["JOIN"] . ".PROPERTY_" . $db_prop["ORIG_ID"] . " = " . $tableAlias . ".ID\n"
				;
			}
			else
			{
				$tableJoin = "\t\t\t" . $joinType . " " . $tableName . " " . $tableAlias
					." ON " . $tableAlias . ".PROPERTY_ID = FPV" . $db_prop["JOIN"] . ".IBLOCK_PROPERTY_ID"
					. " AND FPV".$db_prop["JOIN"].".VALUE_ENUM = " . $tableAlias . ".ID\n"
				;
			}
			$sFrom .= $tableJoin;
			if (self::useCountJoin($db_prop))
			{
				$countFrom .= $tableJoin;
			}

			unset($tableJoin);
			unset($joinType);
			unset($tableAlias);

			if (isset($db_prop["IBLOCK_ID"]) && $db_prop["IBLOCK_ID"])
			{
				$this->arFilterIBlocks[$db_prop["IBLOCK_ID"]] = $db_prop["IBLOCK_ID"];
			}
		}

		$showHistory = ($arFilter['SHOW_HISTORY'] ?? null) === 'Y';
		$showNew = ($arFilter['SHOW_NEW'] ?? null) === 'Y';
		foreach($arJoinProps["BE"] as $db_prop)
		{
			$i = $db_prop["CNT"];

			$tableJoin = "\t\t\tLEFT JOIN b_iblock_element BE".$i." ON BE".$i.".ID = ".
				(
					$db_prop["VERSION"]==2 && $db_prop["MULTIPLE"]=="N"?
					"FPS".$db_prop["JOIN"].".PROPERTY_".$db_prop["ORIG_ID"]
					:"FPV".$db_prop["JOIN"].".VALUE_NUM"
				).
				(
					!$showHistory ?
					" AND ((BE.WF_STATUS_ID=1 AND BE.WF_PARENT_ELEMENT_ID IS NULL)".($showNew ? " OR BE.WF_NEW='Y'": "").")":
					""
				)."\n";

			if ($db_prop["bJoinIBlock"])
			{
				$tableJoin .= "\t\t\tLEFT JOIN b_iblock B".$i." ON B".$i.".ID = BE".$i.".IBLOCK_ID\n";
			}

			if ($db_prop["bJoinSection"])
			{
				$tableJoin .= "\t\t\tLEFT JOIN b_iblock_section BS".$i." ON BS".$i.".ID = BE".$i.".IBLOCK_SECTION_ID\n";
			}

			$sFrom .= $tableJoin;
			if (self::useCountJoin($db_prop))
			{
				$countFrom .= $tableJoin;
			}

			unset($tableJoin);
			unset($joinType);
			unset($tableAlias);

			if (isset($db_prop["IBLOCK_ID"]) && $db_prop["IBLOCK_ID"])
			{
				$this->arFilterIBlocks[$db_prop["IBLOCK_ID"]] = $db_prop["IBLOCK_ID"];
			}
		}

		foreach($arJoinProps["BE_FPS"] as $iblock_id => $db_prop)
		{
			if (str_contains($iblock_id, '~'))
			{
				[$iblock_id, ] = explode("~", $iblock_id, 2);
			}
			$tableJoin = "\t\t\tLEFT JOIN b_iblock_element_prop_s" . $iblock_id . " JFPS" . $db_prop["CNT"]
				. " ON JFPS" . $db_prop["CNT"] . ".IBLOCK_ELEMENT_ID = BE" . $db_prop["JOIN"] . ".ID\n"
			;

			$sFrom .= $tableJoin;
			if (self::useCountJoin($db_prop))
			{
				$countFrom .= $tableJoin;
			}

			unset($tableJoin);

			if (isset($db_prop["IBLOCK_ID"]) && $db_prop["IBLOCK_ID"])
			{
				$this->arFilterIBlocks[$db_prop["IBLOCK_ID"]] = $db_prop["IBLOCK_ID"];
			}
		}

		foreach($arJoinProps["BE_FP"] as $propID => $db_prop)
		{
			$tableName = Iblock\PropertyTable::getTableName();
			$tableAlias = 'JFP' . $db_prop['CNT'];
			$joinType = $db_prop['bFullJoin'] ? 'INNER JOIN' : 'LEFT JOIN';

			if (str_contains($propID, '~'))
			{
				[$propID, ] = explode("~", $propID, 2);
			}

			$tableJoin = "\t\t\t" . $joinType . " " . $tableName . " " . $tableAlias
				. " ON " . $tableAlias . ".IBLOCK_ID = BE". $db_prop["JOIN"] . ".IBLOCK_ID AND "
				. (
					(int)$propID > 0
						? $tableAlias . ".ID=" . (int)$propID . "\n"
						: $tableAlias . ".CODE='" . $DB->ForSQL($propID, 200) . "'\n"
				)
			;

			$sFrom .= $tableJoin;
			if (self::useCountJoin($db_prop))
			{
				$countFrom .= $tableJoin;
			}

			unset($tableJoin);

			if (isset($db_prop["IBLOCK_ID"]) && $db_prop["IBLOCK_ID"])
			{
				$this->arFilterIBlocks[$db_prop["IBLOCK_ID"]] = $db_prop["IBLOCK_ID"];
			}
		}

		foreach($arJoinProps["BE_FPV"] as $propID => $db_prop)
		{
			if (str_contains($propID, '~'))
			{
				[$propID, ] = explode("~", $propID, 2);
			}

			if($db_prop["MULTIPLE"]=="Y")
				$this->bDistinct = true;

			if ($db_prop["VERSION"] == IblockTable::PROPERTY_STORAGE_SEPARATE)
			{
				$tableName = 'b_iblock_element_prop_m' . $db_prop['IBLOCK_ID'];
			}
			else
			{
				$tableName = 'b_iblock_element_property';
			}

			$tableAlias = 'JFPV' . $db_prop['CNT'];
			$joinType = $db_prop['bFullJoin'] ? 'INNER JOIN' : 'LEFT JOIN';

			$tableJoin = "\t\t\t" . $joinType . " " . $tableName . " " . $tableAlias
				. " ON " . $tableAlias .".IBLOCK_PROPERTY_ID = JFP"  .$db_prop["JOIN"] . ".ID"
				. " AND " . $tableAlias . ".IBLOCK_ELEMENT_ID = BE" . $db_prop["BE_JOIN"] . ".ID\n"
			;

			$sFrom .= $tableJoin;
			if (self::useCountJoin($db_prop))
			{
				$countFrom .= $tableJoin;
			}

			unset($tableJoin);
			unset($joinType);
			unset($tableAlias);
			unset($tableName);

			if (isset($db_prop["IBLOCK_ID"]) && $db_prop["IBLOCK_ID"])
			{
				$this->arFilterIBlocks[$db_prop["IBLOCK_ID"]] = $db_prop["IBLOCK_ID"];
			}
		}

		foreach($arJoinProps["BE_FPEN"] as $propID => $db_prop)
		{
			if (str_contains($propID, '~'))
			{
				[$propID, ] = explode("~", $propID, 2);
			}

			$tableName = Iblock\PropertyEnumerationTable::getTableName();
			$tableAlias = 'JFPEN' . $db_prop['CNT'];
			$joinType = $db_prop['bFullJoin'] ? 'INNER JOIN' : 'LEFT JOIN';
			if ($db_prop['VERSION'] == IblockTable::PROPERTY_STORAGE_SEPARATE && $db_prop['MULTIPLE'] === 'N') // VERSION is string
			{
				$tableJoin = "\t\t\t" . $joinType . " " . $tableName . " " .$tableAlias
					. " ON " . $tableAlias . ".PROPERTY_ID = " . $db_prop["ORIG_ID"]
					. " AND JFPS" . $db_prop["JOIN"] . ".PROPERTY_" . $db_prop["ORIG_ID"] ." =  " . $tableAlias.".ID\n"
				;
			}
			else
			{
				$tableJoin = "\t\t\t" . $joinType . " " . $tableName ." " . $tableAlias
					. " ON " . $tableAlias . ".PROPERTY_ID = JFPV" . $db_prop["JOIN"] . ".IBLOCK_PROPERTY_ID"
					. " AND JFPV" . $db_prop["JOIN"].".VALUE_ENUM = " . $tableAlias . ".ID\n"
				;
			}

			$sFrom .= $tableJoin;
			if (self::useCountJoin($db_prop))
			{
				$countFrom .= $tableJoin;
			}

			unset($tableJoin);
			unset($joinType);
			unset($tableAlias);
			unset($tableName);

			if (isset($db_prop["IBLOCK_ID"]) && $db_prop["IBLOCK_ID"])
			{
				$this->arFilterIBlocks[$db_prop["IBLOCK_ID"]] = $db_prop["IBLOCK_ID"];
			}
		}

		if($arJoinProps["BES"] !== '')
		{
			$sFrom .= "\t\t\t".$arJoinProps["BES"]."\n";
			$countFrom .= "\t\t\t".$arJoinProps["BES"]."\n";
		}

		if(!empty($arJoinProps["BESI"]))
		{
			$sFrom .= "\t\t\t".$arJoinProps["BESI"]."\n";
			$countFrom .= "\t\t\t".$arJoinProps["BESI"]."\n";
		}

		if($arJoinProps["FC"] !== '')
		{
			$sFrom .= "\t\t\t".$arJoinProps["FC"]."\n";
			$countFrom .= "\t\t\t".$arJoinProps["FC"]."\n";
			$this->bDistinct = $this->bDistinct || (isset($arJoinProps["FC_DISTINCT"]) && $arJoinProps["FC_DISTINCT"] == "Y");
		}

		if($arJoinProps["RV"])
		{
			$sFrom .= "\t\t\tLEFT JOIN b_rating_voting RV ON RV.ENTITY_TYPE_ID = 'IBLOCK_ELEMENT' AND RV.ENTITY_ID = BE.ID\n";
		}
		if($arJoinProps["RVU"])
		{
			$sFrom .= "\t\t\tLEFT JOIN b_rating_vote RVU ON RVU.ENTITY_TYPE_ID = 'IBLOCK_ELEMENT' AND RVU.ENTITY_ID = BE.ID AND RVU.USER_ID = ".$uid."\n";
		}
		if (is_array($arJoinProps["RVV"]))
		{
			$joinType = $arJoinProps['RVV']['bFullJoin'] ? 'INNER JOIN' : 'LEFT JOIN';
			$tableJoin = "\t\t\t" . $joinType . " b_rating_vote RVV ON RVV.ENTITY_TYPE_ID = 'IBLOCK_ELEMENT' AND RVV.ENTITY_ID = BE.ID\n";
			$sFrom .= $tableJoin;
			if (self::useCountJoin($arJoinProps['RVV']))
			{
				$countFrom .= $tableJoin;
			}
			unset($tableJoin);
			unset($joinType);
		}

		//******************END OF FROM PART********************************************

		$this->bCatalogSort = false;
		if(!empty($arAddSelectFields) || !empty($arAddWhereFields) || !empty($arAddOrderByFields))
		{
			if (Loader::includeModule("catalog"))
			{
				$catalogQueryResult = \CProductQueryBuilder::makeQuery(array(
					'select' => $arAddSelectFields,
					'filter' => $arAddWhereFields,
					'order' => $arAddOrderByFields
				));
				if (!empty($catalogQueryResult))
				{
					if (
						!empty($catalogQueryResult['select'])
						&& $sGroupBy==""
						&& !$this->bOnlyCount
						&& !isset($this->strField)
					)
					{
						$sSelect .= ', '.implode(', ', $catalogQueryResult['select']).' ';
					}
					// filter set in CIBlockElement::MkFilter
					if (!empty($catalogQueryResult['order']))
					{
						$this->bCatalogSort = true;
						foreach ($catalogQueryResult['order'] as $index => $field)
							$arSqlOrder[$index] = $field;
						unset($field);
						unset($index);
					}
					if (!empty($catalogQueryResult['join']))
					{
						$sFrom .= "\n\t\t\t".implode("\n\t\t\t", $catalogQueryResult['join'])."\n";
					}
				}
				unset($catalogQueryResult);
				if (!empty($arAddWhereFields))
				{
					// join for count with product filter
					$catalogQueryResult = \CProductQueryBuilder::makeFilter($arAddWhereFields);
					if (
						!empty($catalogQueryResult['filter'])
						&& !empty($catalogQueryResult['join'])
					)
					{
						$countFrom .= "\n\t\t\t".implode("\n\t\t\t", $catalogQueryResult['join'])."\n";
					}
					unset($catalogQueryResult);
				}
			}
		}

		$i = array_search("CREATED_BY_FORMATTED", $arSelectFields);
		if ($i !== false)
		{
			if (
				$sSelect
				&& $sGroupBy==""
				&& !$this->bOnlyCount
				&& !isset($this->strField)
			)
			{
				$sSelect .= ",UC.NAME UC_NAME, UC.LAST_NAME UC_LAST_NAME, UC.SECOND_NAME UC_SECOND_NAME, UC.EMAIL UC_EMAIL, UC.ID UC_ID, UC.LOGIN UC_LOGIN";
			}
			else
			{
				unset($arSelectFields[$i]);
			}
		}

		$sOrderBy = "";
		foreach($arSqlOrder as $i=>$val)
		{
			if($val <> '')
			{
				if($sOrderBy == "")
				{
					$sOrderBy = " ORDER BY ";
				}
				else
				{
					$sOrderBy .= ",";
				}

				$sOrderBy .= $val." ";
			}
		}

		$sSelect = trim($sSelect, ", \t\n\r");
		if($sSelect == '')
			$sSelect = "0 as NOP ";

		$this->bDistinct = $this->bDistinct || (isset($arFilter["INCLUDE_SUBSECTIONS"]) && $arFilter["INCLUDE_SUBSECTIONS"] == "Y");

		if($this->bDistinct)
			$sSelect = str_replace("%%_DISTINCT_%%", "DISTINCT", $sSelect);
		else
			$sSelect = str_replace("%%_DISTINCT_%%", "", $sSelect);

		$sFrom = "
			b_iblock B
			INNER JOIN b_lang L ON B.LID=L.LID
			INNER JOIN b_iblock_element BE ON BE.IBLOCK_ID = B.ID
			".ltrim($sFrom, "\t\n")
			.(in_array("USER_NAME", $arSelectFields)? "\t\t\tLEFT JOIN b_user U ON U.ID=BE.MODIFIED_BY\n": "")
			.(in_array("LOCKED_USER_NAME", $arSelectFields)? "\t\t\tLEFT JOIN b_user UL ON UL.ID=BE.WF_LOCKED_BY\n": "")
			.(in_array("CREATED_USER_NAME", $arSelectFields) || in_array("CREATED_BY_FORMATTED", $arSelectFields)? "\t\t\tLEFT JOIN b_user UC ON UC.ID=BE.CREATED_BY\n": "")."
		";

		$countFrom = "
			b_iblock B
			INNER JOIN b_lang L ON B.LID=L.LID
			INNER JOIN b_iblock_element BE ON BE.IBLOCK_ID = B.ID
			".ltrim($countFrom, "\t\n")
		;

		$this->sSelect = $sSelect;
		$this->sFrom = $sFrom;
		$this->sWhere = $sWhere;
		$this->sGroupBy = $sGroupBy;
		$this->sOrderBy = $sOrderBy;
		$this->countFrom = $countFrom;
	}

	/**
	 * List of elements.
	 *
	 * @param array $arOrder
	 * @param array $arFilter
	 * @param bool|array $arGroupBy
	 * @param bool|array $arNavStartParams
	 * @param array $arSelectFields
	 * @return integer|CIBlockResult
	 */
	
	/**
	 * <p>Возвращает список элементов по фильтру <i>arFilter</i>. Метод статический.</p><br>
	 *
	 *
	 *
	 *
	 * @param array $arOrder = Array("SORT"=>"ASC") Массив вида Array(<i>by1</i>=&gt;<i>order1</i>[, <i>by2</i>=&gt;<i>order2</i> [, ..]]), где <i>by</i> - поле для сортировки, может принимать значения: <ul> <li> <b>id</b> - ID элемента. Может принимать значения: <ul> <li> <b>asc</b> - по возрастанию;</li> <li> <b>desc</b> - по убыванию;</li> <li> <b>массив ID</b> - в этом случае элементы будут выводиться в том порядке, в котором они перечислены в массиве. (С версии 18.6.700) <p></p> <div class="note"> <b>Важно!</b> Этот же массив должен быть передан в фильтр (параметр <b>arFilter</b>).</div> </li> </ul> </li> <li> <b>sort</b> - индекс сортировки; </li> <li> <b>timestamp_x</b> - дата изменения; </li> <li> <b>name</b> - название; </li> <li> <span style="font-weight: bold;">date_active_from</span> - начало периода действия элемента; </li> <li> <span style="font-weight: bold;">date_active_to</span> - окончание периода действия элемента; </li> <li> <b>status</b> - код статуса элемента в документообороте; </li> <li> <b>code</b> - символьный код элемента; </li> <li> <b>iblock_id</b> - числовой код информационного блока; </li> <li> <b>modified_by</b> - код последнего изменившего пользователя; </li> <li> <b>active</b> - признак активности элемента; </li> <li> <i>show_counter </i>- количество показов элемента (учитывается методом <a class="link" href="http://dev.1c-bitrix.ru/api_help/iblock/classes/ciblockelement/index.php">CIBlockElement</a>::<a class="link" href="http://dev.1c-bitrix.ru/api_help/iblock/classes/ciblockelement/counterinc.php">CounterInc</a>); </li> <li> <b>show_counter_start</b> - время первого показа элемента (учитывается методом <a class="link" href="http://dev.1c-bitrix.ru/api_help/iblock/classes/ciblockelement/index.php">CIBlockElement</a>::<a class="link" href="http://dev.1c-bitrix.ru/api_help/iblock/classes/ciblockelement/counterinc.php">CounterInc</a>); </li> <li> <b>shows</b> - усредненное количество показов (количество показов / продолжительность показа); </li> <li> <b>rand</b> - случайный порядок;</li> <li> <span style="font-weight: bold;">xml_id</span> или <span style="font-weight: bold;">external_id</span> - внешний код;</li> <li> <span style="font-weight: bold;">tags</span> - теги;</li> <li> <span style="font-weight: bold;">created</span> - время создания;</li> <li> <span style="font-weight: bold;">created_date</span> - дата создания без учета времени;</li> <li> <span style="font-weight: bold;">cnt</span> - количество элементов (только при заданной группировке); <br> </li> <li> <b>property_&lt;PROPERTY_CODE&gt;</b> - по значению свойства с числовым или символьным кодом <i>PROPERTY_CODE</i> (например, PROPERTY_123 или PROPERTY_NEWS_SOURCE); </li> <li> <b>propertysort_&lt;PROPERTY_CODE&gt;</b> - по индексу сортировки варианта значения свойства. Только для свойств типа "Список" ; </li> <li> <b>catalog_&lt;CATALOG_FIELD&gt;_&lt;PRICE_TYPE&gt;</b> - по полю CATALOG_FIELD (может быть PRICE - цена, CURRENCY - валюта или PRICE_SCALE - цена с учетом валюты) из цены с типом <i>PRICE_TYPE</i> (например, catalog_PRICE_1 или CATALOG_CURRENCY_3). С версии 16.0.3 модуля <b>Торговый каталог</b> сортировка по цене также идет с учетом валюты.</li> <li> <b>IBLOCK_SECTION_ID</b> - ID раздела;</li> <li>*<b>CATALOG_QUANTITY</b> - общее количество товара;</li> <li>*<b>CATALOG_WEIGHT</b> - вес товара;</li> <li>*<b>CATALOG_AVAILABLE</b> - признак доступности товара (Y|N). Товар считается недоступным, если его количество меньше либо равно нулю, включен количественный учет и запрещена покупка при нулевом количестве.</li> <li>*<b>CATALOG_STORE_AMOUNT_<i>&lt;идентификатор_склада&gt;</i></b> - сортировка по количеству товара на конкретном складе (доступно с версии 15.5.5 модуля <b>Торговый каталог</b>).</li> <!--<li>*<b>CATALOG_PRICE_SCALE_<i>&lt;тип_цены&gt;</i></b> - сортировка по цене с учетом валюты (доступно с версии 16.0.3 модуля <b>Торговый каталог</b>).</li>--> <li>*<b>CATALOG_BUNDLE</b> - сортировка по наличию набора у товара (доступно с версии 16.0.3 модуля <b>Торговый каталог</b>).</li> <li> <span style="font-weight: bold;">PROPERTY_&lt;PROPERTY_CODE&gt;.&lt;FIELD&gt;</span> - по значению поля элемента указанного в качестве привязки. <b>PROPERTY_CODE</b> - символьный код свойства типа привязка к элементам. FIELD может принимать значения: <ul> <li>ID</li> <li>TIMESTAMP_X </li> <li>MODIFIED_BY </li> <li>CREATED</li> <li>CREATED_DATE </li> <li>CREATED_BY </li> <li>IBLOCK_ID </li> <li>ACTIVE </li> <li>SORT </li> <li>NAME </li> <li>SHOW_COUNTER </li> <li>SHOW_COUNTER_START </li> <li>CODE </li> <li>TAGS </li> <li>XML_ID </li> <li>STATUS </li> </ul> </li> <li> <span style="font-weight: bold;">PROPERTY_&lt;PROPERTY_CODE&gt;.PROPERTY_&lt;</span><span style="font-weight: bold;">PROPERTY_CODE2</span><span style="font-weight: bold;">&gt;</span> - по значению свойства элемента указанного в качестве привязки. PROPERTY_CODE - символьный код свойства типа привязки к элементам. PROPERTY_CODE2- код свойства связанных элементов. </li> <li> <b>HAS_PREVIEW_PICTURE</b> и <b>HAS_DETAIL_PICTURE</b> - сортировка по наличию и отсутствию картинок.</li> <li> <b>order</b> - порядок сортировки, пишется без пробелов, может принимать значения: <ul> <li> <b>asc</b> - по возрастанию;</li> <li> <span style="font-weight: bold;">nulls,asc</span> - по возрастанию с пустыми значениями в начале выборки;</li> <li> <span
	 * style="font-weight: bold;">asc,nulls</span> - по возрастанию с пустыми значениями в конце выборки;</li> <li> <b>desc</b> - по убыванию;</li> <li> <span style="font-weight: bold;">nulls,desc</span> - по убыванию с пустыми значениями в начале выборки;</li> <li> <span style="font-weight: bold;">desc,nulls</span> - по убыванию с пустыми значениями в конце выборки; <br> </li> </ul> Необязательный. По умолчанию равен <i>Array("sort"=&gt;"asc")</i> </li> </ul> <!-- <li>*<b>CATALOG_PRICE_<i>&lt;ID типа цен&gt;</i></b> - по ценам торговых предложений.</li>--> <br> <div class="note"> <b>Примечание 1:</b> если задать разным свойствам одинаковый символьный код, но в разном регистре, то при работе сортировки по одному из свойств (например, PROPERTY_rating) будет возникать ошибочная ситуация (элементы в списке задублируются, сортировки не будет). </div> <br> <div class="note"> <b>Примечание 2:</b> указанные поля сортировки автоматически добавляются в arGroupBy (если он задан) и arSelectFields.</div><br /><br /><hr /><br /><br />
	 *
	 *
	 *
	 * @param array $arFilter = Array() Массив вида array("фильтруемое поле"=&gt;"значения фильтра" [, ...]). "фильтруемое поле" может принимать значения: <ul> <li> <b>ID</b> - по числовому коду (фильтр <a class="link" href="http://dev.1c-bitrix.ru/api_help/iblock/filters/number.php">Число</a>); </li> <li> <b>ACTIVE</b> - фильтр по активности (Y|N); передача пустого значения (<i>"ACTIVE"=&gt;""</i>) выводит все элементы без учета их состояния (фильтр <a class="link" href="http://dev.1c-bitrix.ru/api_help/iblock/filters/string_equal.php">Строка</a>);</li> <li> <b>NAME</b> - по названию (фильтр <a class="link" href="http://dev.1c-bitrix.ru/api_help/iblock/filters/string.php">Маска</a>); </li> <li> <b>CODE</b> - по символьному идентификатору (фильтр <a class="link" href="http://dev.1c-bitrix.ru/api_help/iblock/filters/string.php">Маска</a>); </li> <li> <b>IBLOCK_SECTION_ID</b> - используйте этот ключ только в режиме выбора <a class="link" href="http://dev.1c-bitrix.ruhttps://dev.1c-bitrix.ru/user_help/content/iblock/iblock_edit_property.php#link_to_section" title="" target="_blank">основного раздела для элемента</a> либо когда все товары привязаны только к одному разделу. Во всех остальных случаях используйте фильтр по SECTION_ID.</li> <li> <b>TAGS</b> - по тегам (фильтр <a class="link" href="http://dev.1c-bitrix.ru/api_help/iblock/filters/string.php">Маска</a>); </li> <li> <b>XML_ID</b> или<b> EXTERNAL_ID</b> - по внешнему коду (фильтр <a class="link" href="http://dev.1c-bitrix.ru/api_help/iblock/filters/string.php">Маска</a>); </li> <li> <b>PREVIEW_TEXT</b> - по анонсу (фильтр <a class="link" href="http://dev.1c-bitrix.ru/api_help/iblock/filters/string.php">Маска</a>); </li> <li> <b>PREVIEW_TEXT_TYPE</b> - по типу анонса (html|text, фильтр <a class="link" href="http://dev.1c-bitrix.ru/api_help/iblock/filters/string_equal.php">Строка</a>); </li> <li> <b>PREVIEW_PICTURE</b> - коду картинки для анонса (фильтр <a class="link" href="http://dev.1c-bitrix.ru/api_help/iblock/filters/number.php">Число</a>); </li> <li> <b>DETAIL_TEXT</b> - по детальному описанию (фильтр <a class="link" href="http://dev.1c-bitrix.ru/api_help/iblock/filters/string.php">Маска</a>); </li> <li> <b>DETAIL_TEXT_TYPE</b> - по типу детальному описания (html|text, фильтр <a class="link" href="http://dev.1c-bitrix.ru/api_help/iblock/filters/string_equal.php">Строка</a>); </li> <li> <b>DETAIL_PICTURE</b> - по коду детальной картинки (фильтр <a class="link" href="http://dev.1c-bitrix.ru/api_help/iblock/filters/number.php">Число</a>); </li> <li> <b>CHECK_PERMISSIONS</b> - если установлен в "Y", то в выборке будет осуществляться проверка прав доступа к информационным блокам. По умолчанию права доступа не проверяются. </li> <li> <b>PERMISSIONS_BY</b> - фильтрация по правам произвольного пользователя. Значение - ID пользователя или 0 (неавторизованный).</li> <li>*<b>CATALOG_TYPE</b> - фильтрация по типу товара; </li> <li> <b>MIN_PERMISSION</b> - минимальный уровень доступа, будет обработан только если <b>CHECK_PERMISSIONS</b> установлен в "Y". По умолчанию "R". Список прав доступа см. в <a class="link" href="http://dev.1c-bitrix.ru/api_help/iblock/classes/ciblock/index.php">CIBlock</a>::<a class="link" href="http://dev.1c-bitrix.ru/api_help/iblock/classes/ciblock/setpermission.php">SetPermission</a>(). </li> <li> <b>SEARCHABLE_CONTENT</b> - по содержимому для поиска. Включает в себя название, описание для анонса и детальное описание (фильтр <a class="link" href="http://dev.1c-bitrix.ru/api_help/iblock/filters/string.php">Маска</a>); </li> <li> <b>SORT</b> - по сортировке (фильтр <a class="link" href="http://dev.1c-bitrix.ru/api_help/iblock/filters/number.php">Число</a>); </li> <li> <b>TIMESTAMP_X</b> - по времени изменения (фильтр <a class="link" href="http://dev.1c-bitrix.ru/api_help/iblock/filters/date.php">Дата</a>);</li> <li> <b>DATE_MODIFY_FROM</b> - по времени изменения. Будут выбраны элементы измененные после времени указанного в фильтре. Время указывается в формате сайта. Возможно использовать операцию отрицания "!DATE_MODIFY_FROM"; </li> <li> <b>DATE_MODIFY_TO</b> - по времени изменения. Будут выбраны элементы измененные ранее времени указанного в фильтре. Время указывается в формате сайта. Возможно использовать операцию отрицания "!DATE_MODIFY_TO";</li> <li> <b>MODIFIED_USER_ID </b>или<b> MODIFIED_BY</b> - по коду пользователя, изменившего элемент (фильтр <a class="link" href="http://dev.1c-bitrix.ru/api_help/iblock/filters/number.php">Число</a>); </li> <li> <b>DATE_CREATE</b> - по времени создания (фильтр <a class="link" href="http://dev.1c-bitrix.ru/api_help/iblock/filters/date.php">Дата</a>); </li> <li> <b>CREATED_USER_ID </b>или<b> CREATED_BY</b> - по коду пользователя, добавившего элемент (фильтр <a class="link" href="http://dev.1c-bitrix.ru/api_help/iblock/filters/number.php">Число</a>); </li> <li> <b>DATE_ACTIVE_FROM</b> - по дате начала активности (фильтр <a class="link" href="http://dev.1c-bitrix.ru/api_help/iblock/filters/date.php">Дата</a>) Формат даты должен соответствовать <a class="link" href="http://dev.1c-bitrix.ruhttps://dev.1c-bitrix.ru/learning/course/index.php?COURSE_ID=35&amp;LESSON_ID=2071">формату даты</a>, установленному на сайте. Чтобы выбрать элементы с пустым полем начала активности, следует передать значение <i>false</i>; </li> <li> <b>DATE_ACTIVE_TO</b> - по дате окончания активности (фильтр <a class="link" href="http://dev.1c-bitrix.ru/api_help/iblock/filters/date.php">Дата</a>)Формат даты должен соответствовать <a class="link" href="http://dev.1c-bitrix.ruhttps://dev.1c-bitrix.ru/learning/course/index.php?COURSE_ID=35&amp;LESSON_ID=2071">формату
	 * даты</a>, установленному на сайте. Чтобы выбрать элементы с пустым полем окончания активности, следует передать значение <i>false</i>; </li> <li> <b>ACTIVE_DATE</b> - непустое значение задействует фильтр по датам активности. Будут выбраны активные по датам элементы.Если значение не установлено (<i>""</i>), фильтрация по датам активности не производится; <br> Чтобы выбрать все не активные по датам элементы, используется такой синтаксис: <pre class="syntax">$el_Filter[ "!ACTIVE_DATE" ]= "Y";</pre> </li> <li> <b>IBLOCK_ID</b> - по коду информационного блока (фильтр <a class="link" href="http://dev.1c-bitrix.ru/api_help/iblock/filters/number.php">Число</a>); <br> <br> При использовании инфоблоков 1.0 можно в IBLOCK_ID передать массив идентификаторов, чтобы сделать выборку из элементов нескольких инфоблоков: <br> <pre class="syntax">$arFilter = array("IBLOCK_ID" =&gt; array(1, 2, 3), ...);</pre> Для инфоблоков 2.0 такая выборка будет работать только в том случае, если в ней не запрашиваются свойства элементов. <p>В некоторых случаях точное указание IBLOCK_ID в фильтре может ускорить выборку элементов. Так как зависимости сложные, каждый конкретный случай надо рассматривать отдельно.</p> </li> <li> <b>IBLOCK_CODE</b> - по символьному коду информационного блока (фильтр <a class="link" href="http://dev.1c-bitrix.ru/api_help/iblock/filters/string.php">Маска</a>); </li> <li> <b>IBLOCK_SITE_ID</b> или <span style="font-weight: bold;">IBLOCK_LID</span> или <span style="font-weight: bold;">SITE_ID</span> или <span style="font-weight: bold;">LID</span> - по сайту (фильтр <a class="link" href="http://dev.1c-bitrix.ru/api_help/iblock/filters/string_equal.php">Строка</a>); </li> <li> <b>IBLOCK_TYPE</b> - по типу информационного блока (фильтр <a class="link" href="http://dev.1c-bitrix.ru/api_help/iblock/filters/string.php">Маска</a>); </li> <li> <b>IBLOCK_ACTIVE</b> - по активности информационного блока (Y|N, фильтр <a class="link" href="http://dev.1c-bitrix.ru/api_help/iblock/filters/string_equal.php">Строка</a>); </li> <li> <b>SECTION_ID</b> - по родительской группе. Если значение фильтра false, "" или 0, то будут выбраны элементы не привязанные ни к каким разделам. Иначе будут выбраны элементы привязанные к заданному разделу. Значением фильтра может быть и массив. В этом случае будут выбраны элементы привязанные хотя бы к одному из разделов указанных в фильтре. Возможно указание отрицания "!". В этом случае условие будет инвертировано;</li> <li> <b>SECTION_CODE</b> - по символьному коду родительской группы. Аналогично SECTION_ID; </li> <li> <b>INCLUDE_SUBSECTIONS</b> - если задан фильтр по родительским группам <b>SECTION_ID</b>, то будут также выбраны элементы находящиеся в подгруппах этих групп (имеет смысле только в том случае, если <b>SECTION_ID &gt; 0</b>);</li> <li> <span style="font-weight: bold;">SUBSECTION</span>  - по принадлежности к подразделам раздела. Значением фильтра может быть массив из двух элементов задающих левую и правую границу дерева разделов. Операция отрицания поддерживается. </li> <li> <span style="font-weight: bold;">SECTION_ACTIVE</span> - если ключ есть в фильтре, то проверяется активность групп к которым привязан элемент. </li> <li> <span style="font-weight: bold;">SECTION_GLOBAL_ACTIVE</span> - аналогично предыдущему, но учитывается также активность родительских групп.</li> <li> <span style="font-weight: bold;">SECTION_SCOPE</span> - задает уточнение для фильтров SECTION_ACTIVE и SECTION_GLOBAL_ACTIVE. Если значение "IBLOCK", то учитываются только привязки к разделам инфоблока. Если значение "PROPERTY", то учитываются только привязки к разделам свойств. "PROPERTY_<id>" - привязки к разделам конкретного свойства.</id> </li> <li>*<b>CATALOG_AVAILABLE</b> - признак доступности товара (Y|N). Товар считается недоступным, если его количество меньше либо равно нулю, включен количественный учет и запрещена покупка при нулевом количестве; </li> <li>*<b>CATALOG_CATALOG_GROUP_ID_N</b> - по типу цен; </li> <li>*<b>CATALOG_SHOP_QUANTITY_N</b> - фильтрация по диапазону количества в цене; </li> <li>*<b>CATALOG_QUANTITY</b> - по общему количеству товара; </li> <li>*<b>CATALOG_WEIGHT</b> - по весу товара; </li> <li>*<b>CATALOG_STORE_AMOUNT_<i>&lt;идентификатор_склада&gt;</i></b> - фильтрация по наличию товара на конкретном складе (доступно с версии 15.0.2 модуля <b>Торговый каталог</b>). В качестве значения фильтр принимает количество товара на складе либо <i>false</i>.</li> <li>*<b>CATALOG_PRICE_SCALE_<i>&lt;тип_цены&gt;</i></b> - фильтрация по цене с учетом валюты (доступно с версии 16.0.3 модуля <b>Торговый каталог</b>).</li> <li>*<b>CATALOG_BUNDLE</b> - фильтрация по наличию набора у товара (доступно с версии 16.0.3 модуля <b>Торговый каталог</b>).</li> <li> <strong>SUBQUERY</strong> - <span class="learning-lesson-detail-block js-detail-info-block"> <span class="learning-lesson-detail-word js-detail-info-word">массив</span> <span class="learning-lesson-detail-body js-detail-info-body"> <span class="learning-lesson-detail-body-inner js-detail-info-body-inner"> <button class="learning-lesson-detail-close-button js-detail-close-button" type="button"></button> <pre class="syntax">$filter = array("SUBQUERY" =&gt; array( "FIELD" =&gt; "имя поля", "FILTER" =&gt; array(фильтр для отбора предложений) ))</pre> </span>
	 * </span> </span> из двух параметров: <ul> <li>FIELD - <a class="link" href="http://dev.1c-bitrix.ru/api_help/iblock/filters/string_equal.php">строка</a>, имя поля</li> <li>FILTER - массив, фильтр для отбора предложений.</li> </ul> Внутри GetList преобразовывается в: <pre class="syntax">ID =&gt; CIBlockElement::SubQuery($filter['SUBQUERY']['FIELD'], $filter['SUBQUERY']['FILTER'])</pre> Доступен с версии 21.300.0 модуля iblock. </li> <li> <b>SHOW_COUNTER</b> - по количеству показов (фильтр <a class="link" href="http://dev.1c-bitrix.ru/api_help/iblock/filters/number.php">Число</a>); </li> <li> <b>SHOW_COUNTER_START</b> - по времени первого показа (фильтр <a class="link" href="http://dev.1c-bitrix.ru/api_help/iblock/filters/date.php">Дата</a>); </li> <li> <b>WF_COMMENTS</b> - по комментарию документооборота (фильтр <a class="link" href="http://dev.1c-bitrix.ru/api_help/iblock/filters/string.php">Маска</a>); </li> <li> <b>WF_STATUS_ID</b> или <span style="font-weight: bold;">WF_STATUS</span> - по коду статуса документооборота (фильтр <a class="link" href="http://dev.1c-bitrix.ru/api_help/iblock/filters/number.php">Число</a>); </li> <li> <b>SHOW_HISTORY</b> - если установлен в значение "Y", то вместе с элементами будут выводится и их архив (история), по умолчанию выводятся только опубликованные элементы. Для фильтрации по WF_STATUS_ID <b>SHOW_HISTORY</b> должен стоять в "Y".</li> <li> <b>SHOW_NEW</b> - если <b>SHOW_HISTORY</b> не установлен или не равен Y и <b>SHOW_NEW</b>=Y, то будут показываться ещё неопубликованные элементы вместе с опубликованными; </li> <li> <b>WF_PARENT_ELEMENT_ID</b> - по коду элемента-родителя в документообороте для выборки истории изменений (фильтр <a class="link" href="http://dev.1c-bitrix.ru/api_help/iblock/filters/number.php">Число</a>); </li> <li> <b>WF_NEW</b> - флаг что элемент ещё ни разу не был опубликован (Y|N); </li> <li> <b>WF_LOCK_STATUS</b> - статус заблокированности элемента в документооборте (red|green|yellow); </li> <li> <b>PROPERTY_&lt;PROPERTY_CODE</b><b>&gt;</b> - фильтр по значениям свойств, где PROPERTY_CODE - код свойства или символьный код. Для свойств типа "Список", "Число", "Привязка к элементам" и "Привязка к разделам"  - фильтр <a class="link" href="http://dev.1c-bitrix.ru/api_help/iblock/filters/number.php">Число</a>. Для прочих - фильтр <a class="link" href="http://dev.1c-bitrix.ru/api_help/iblock/filters/string.php">Маска</a>; </li> <li> <b style="font-weight: bold;">PROPERTY_&lt;</b><b>PROPERTY_CODE<span style="font-weight: bold;">&gt;_VALUE</span></b> - фильтр по значениям списка для свойств типа "список" (фильтр <a class="link" href="http://dev.1c-bitrix.ru/api_help/iblock/filters/string.php">Маска</a>), поиск будет осуществляться по строковому значению списка, а не по идентификатору; </li> <li>*<b>CATALOG_&lt;CATALOG_FIELD&gt;_&lt;PRICE_TYPE&gt;</b> - по полю <i>CATALOG_FIELD</i> из цены типа <i>PRICE_TYPE</i> (ID типа цены), где <i>CATALOG_FIELD</i> может быть: PRICE - цена, CURRENCY - валюта.</li> <li> <span style="font-weight: bold;">PROPERTY_&lt;PROPERTY_CODE&gt;.&lt;FIELD&gt;</span> - фильтр по значениям полей связанных элементов. , где PROPERTY_CODE - ID или символьный код свойства привязки, а FIELD - поле указанного в привязке элемента. FIELD может принимать следующие значения: ACTIVE, DETAIL_TEXT_TYPE, PREVIEW_TEXT_TYPE, EXTERNAL_ID, NAME, XML_ID, TMP_ID, DETAIL_TEXT, SEARCHABLE_CONTENT, PREVIEW_TEXT, CODE, TAGS, WF_COMMENTS, ID, SHOW_COUNTER, WF_PARENT_ELEMENT_ID, WF_STATUS_ID, SORT, CREATED_BY, PREVIEW_PICTURE, DETAIL_PICTURE, IBLOCK_ID, TIMESTAMP_X, DATE_CREATE, SHOW_COUNTER_START, DATE_ACTIVE_FROM, DATE_ACTIVE_TO, ACTIVE_DATE, DATE_MODIFY_FROM, DATE_MODIFY_TO, MODIFIED_USER_ID, MODIFIED_BY, CREATED_USER_ID, CREATED_BY. Правила фильтров идентичны тем, которые описаны выше.</li> <li> <b>PRODUCT_BARCODE</b> - по штрихкоду.</li> <li> <b>PRODUCT_BARCODE_STORE</b> - по привязке к складу штрихкода.</li> <li> <b>PRODUCT_BARCODE_ORDER </b> - по привязке к заказу штрихкода.</li> </ul> Перед названием фильтруемого поля можно указать тип проверки фильтра: <ul> <li>"!" - не равно </li> <li>"&lt;" - меньше </li> <li>"&lt;=" - меньше либо равно </li> <li>"&gt;" - больше </li> <li>"&gt;=" - больше либо равно</li> <li>"&gt;&lt;" - между</li> <li>и т.д. </li> </ul> <i>Значения фильтра</i> - одиночное значение или массив значений. Для исключения пустых значений необходимо использовать <i>false</i>. <p>Необязательное. По умолчанию записи не фильтруются. </p> <div class="note"> <b>Примечание 1:</b> (по настройке фильтра для свойства типа "Дата/Время"): свойство типа Дата/Время хранится как строковое с датой в формате YYYY-MM-DD HH:MI:SS. Соответственно сортировка по значению такого свойства будет работать корректно, а вот значение для фильтрации формируется примерно так: $cat_filter["&gt;"."PROPERTY_available"] = date("Y-m-d"); </div> <br> <div class="note"> <b>Примечание 2:</b> при использовании типа проверки фильтра "&gt;&lt;" для целых чисел, заканчивающихся нулем, необходимо использовать тип поля <i>число</i> или разделительный знак "," для десятичных значений (например, 20000,00). Иначе работает не корректно.</div><br /><br /><hr /><br /><br />
	 *
	 *
	 *
	 * @param mixed $arGroupBy = false Массив полей для группировки элемента. Если поля указаны, то выборка по ним группируется (при этом параметр arSelectFields будет проигнорирован), а в результат добавляется поле CNT - количество сгруппированных элементов. Если указать в качестве arGroupBy пустой массив, то метод вернет количество элементов CNT по фильтру. Группировать можно по полям элемента, а также по значениям его свойств. Для этого в качестве одного из полей группировки необходимо указать <i>PROPERTY_&lt;PROPERTY_CODE&gt;</i>, где PROPERTY_CODE - ID или символьный код свойства. <br> Необязательное. По умолчанию false - записи не группируются. (С версии 3.2.1)<br /><br /><hr /><br /><br />
	 *
	 *
	 *
	 * @param mixed $arNavStartParams = false Параметры для постраничной навигации и ограничения количества выводимых элементов. Массив вида "Название параметра"=&gt;"Значение", где название параметра: <ul> <li> <b>nTopCount</b> - ограничить количество сверху. Если задать nTopCount и хранить свойства в общей таблице, то этот параметр ограничивает именно количество запросов. Если есть множественные свойства, то это будет отдельный запрос. То есть чтобы сделать запрос к 2 элементам со множественным свойством из 2 и 3 значений, то nTopCount должен быть равен 5. Решение проблемы: переместить свойства в отдельную таблицу. ; </li> <li> <b>nOffset</b> - смещение. Ключ работает только при передаче непустого значения в ключе nTopCount. Доступен с версии iblock 21.700.100; </li> <li> <b>bShowAll</b>; - разрешить вывести все элементы при постраничной навигации; </li> <li> <b>iNumPage</b>; - номер страницы при постраничной навигации; </li> <li> <b>nPageSize</b>; - количество элементов на странице при постраничной навигации; </li> <li> <b>nElementID</b>; - ID элемента, который будет выбран вместе со своими соседями. Количество соседей определяется параметром nPageSize. Например: если nPageSize равно 2-м, то будут выбраны максимум 5-ть элементов.  Соседи определяются порядком сортировки, заданным в параметре arOrder (см. выше). <br>При этом действуют следующие ограничения: <ul> <li>Если элемент с таким ID отсутствует в выборке, то результат будет не определен.</li> <li>nElementID не работает, если задана группировка (см. параметр arGroupBy выше).</li> <li>в параметре arSelect обязательно должно присутствовать поле "ID".</li> <li>обязательно должна быть задана сортировка arOrder.</li> <li>поля в сортировке catalog_* не учитываются, и результат выборки становится не определенным.</li> <li>в выборку добавляется поле RANK - порядковый номер элемента в "полной" выборке. </li> </ul> </li> </ul> Необязательное. По умолчанию <i>false</i> - не ограничивать выводимые элементы. <br> Если передать в параметр <i>arNavStartParams</i> пустой массив, то ставится ограничение на 10 выводимых элементов. <br> (С версии 3.2.1)<br /><br /><hr /><br /><br />
	 *
	 *
	 *
	 * @param array $arSelectFields = Array() Массив возвращаемых <a class="link" href="http://dev.1c-bitrix.ru/api_help/iblock/fields.php#felement">полей элемента</a>. <br> <div class="note"> <b>Обратите внимание!</b> В параметре используются поля, но <b>не массивы</b>. Таким образом, попытка выборки по IBLOCK_SECTION (Массив идентификаторов групп, к которым относится элемент) приведет к нарушению работы метода. </div> <p>В списке полей элемента можно сразу выводить значения его свойств. Обязательно должно быть использованы поля IBLOCK_ID и ID, иначе не будет работать корректно. Кроме того, также  в качестве одного из полей необходимо указать <i>PROPERTY_&lt;PROPERTY_CODE&gt;</i>, где PROPERTY_CODE - ID или символьный код (задается в верхнем регистре, даже если в определении свойств инфоблока он указан в нижнем регистре). </p> <p>В результате будет выведены значения свойств элемента в виде полей <i>PROPERTY_&lt;PROPERTY_CODE&gt;_VALUE</i> - значение; <i>PROPERTY_&lt;PROPERTY_CODE&gt;_ID</i> - код значения у элемента; <i>PROPERTY_&lt;PROPERTY_CODE&gt;_ENUM_ID</i> - код значения (для свойств типа список). </p> <p>При установленном модуле торгового каталога можно выводить и цены элемента. Для этого в качестве одного из полей необходимо указать *<i>CATALOG_GROUP_&lt;PRICE_CODE&gt;</i>, где PRICE_CODE - ID типа цены. </p> <p> Также есть возможность выбрать поля элементов по значениям свойства типа "Привязка к элементам". Для этого необходимо указать  <i>PROPERTY_&lt;PROPERTY_CODE&gt;.&lt;FIELD&gt;</i>, где PROPERTY_CODE - ID или символьный код свойства привязки, а FIELD - поле указанного в привязке элемента. См. ниже "Поля связанных элементов для сортировки". </p> <p>Можно выбрать и значения свойств элементов по значениям свойства типа "Привязка к элементам". Для этого необходимо указать  <i>PROPERTY_&lt;PROPERTY_CODE&gt;.</i><i>PROPERTY_&lt;PROPERTY_CODE2&gt;</i>, где PROPERTY_CODE - ID или символьный код свойства привязки, а PROPERTY_CODE2 - свойство указанного в привязке элемента. </p> <p>По умолчанию выводить все поля. Значения параметра игнорируются, если используется параметр группировки <i>arGroupBy</i>.</p> <div class="note"> <b>Примечание 1</b>: если в массиве используются свойство, являющееся множественным, то для элементов, где используются несколько значений этого свойства, будет возвращено несколько записей вместо одной. Для решения этой проблемы инфоблоки нужно перевести в <a class="link" href="http://dev.1c-bitrix.ruhttp://dev.1c-bitrix.ru/learning/course/index.php?COURSE_ID=43&amp;LESSON_ID=2723" target="_blank">Режим хранения свойств в отдельных таблицах</a>, в этом случае для свойства будет отдаваться массив значений. Либо можно не указывать свойства в параметрах выборки, а получать их значения на каждом шаге перебора выборки с помощью _CIBElement::GetProperties(). </div> <br> <div class="note"> <b>Примечание 2</b>: Если в массиве указаны поля DETAIL_PAGE_URL или LIST_PAGE_URL, то поля необходимые для правильной подстановки шаблонов URL'ов будут выбраны автоматически. Но только если не была задана группировка. </div> <br> <div class="note"> <b>Примечание 3</b>: если необходимо выбрать данные о рейтингах для выбранных элементов, то для этого в массиве необходимо указать следующие <a class="link" href="http://dev.1c-bitrix.ru/api_help/main/general/ratings/rating_vote.php">поля</a>: RATING_TOTAL_VALUE, RATING_TOTAL_VOTES, RATING_TOTAL_POSITIVE_VOTES, RATING_TOTAL_NEGATIVE_VOTES, RATING_USER_VOTE_VALUE.</div> (С версии 3.2.1)
	 *
	 *
	 *
	 * @return integer|CIBlockResult
	 *
	 * @link https://dev.1c-bitrix.ru/api_help/iblock/classes/ciblockelement/getlist.php
	 * @author phpDoc author - generator by hipot
	 */
	public static function GetList($arOrder=array("SORT"=>"ASC"), $arFilter=array(), $arGroupBy=false, $arNavStartParams=false, $arSelectFields=array())
	{
		global $DB;

		if (
			isset($arFilter['CHECK_PERMISSIONS'])
			&& $arFilter['CHECK_PERMISSIONS'] === 'Y'
		)
		{
			$filterIblockId = static::getSingleIblockIdFromFilter($arFilter);
			if (
				$filterIblockId !== null
				&& CIBlock::GetArrayByID($filterIblockId, 'RIGHTS_MODE') === Iblock\IblockTable::RIGHTS_SIMPLE)
			{
				$minPermission = (string)($arFilter['MIN_PERMISSION'] ?? CIBlockRights::PUBLIC_READ);
				if (strlen($minPermission) !== 1)
				{
					$minPermission = CIBlockRights::PUBLIC_READ;
				}
				$currentPermission = CIBlock::GetPermission($filterIblockId, $arFilter['PERMISSIONS_BY'] ?? false);
				if ($currentPermission < $minPermission)
				{
					return new CIBlockResult();
				}
				if (
					!defined('ADMIN_SECTION')
					&& $currentPermission < CIBlockRights::FULL_ACCESS
					&& CIBlock::GetArrayByID($filterIblockId, 'ACTIVE') !== 'Y'
				)
				{
					return new CIBlockResult();
				}

				unset(
					$arFilter['CHECK_PERMISSIONS'],
					$arFilter['MIN_PERMISSION'],
				);
			}
		}

		$el = new CIBlockElement();
		$el->prepareSql($arSelectFields, $arFilter, $arGroupBy, $arOrder);

		if($el->bOnlyCount)
		{
			$res = $DB->Query("
				SELECT ".$el->sSelect."
				FROM ".$el->sFrom."
				WHERE 1=1 ".$el->sWhere."
				".$el->sGroupBy."
			");
			$res = $res->Fetch();
			return $res["CNT"];
		}

		if (!empty($arNavStartParams) && is_array($arNavStartParams))
		{
			$nTopCount = (int)($arNavStartParams['nTopCount'] ?? 0);
			$nElementID = (int)($arNavStartParams['nElementID'] ?? 0);

			if ($nTopCount > 0)
			{
				$offset = (int)($arNavStartParams['nOffset'] ?? 0);
				$strSql = "
					SELECT ".$el->sSelect."
					FROM ".$el->sFrom."
					WHERE 1=1 ".$el->sWhere."
					".$el->sGroupBy."
					".$el->sOrderBy."
					LIMIT ".$nTopCount.
					($offset > 0
						? ' OFFSET ' . $offset
						: ''
					)."
				";
				$res = $DB->Query($strSql);
			}
			elseif (
				$nElementID > 0
				&& $el->sGroupBy == ""
				&& $el->sOrderBy != ""
				&& mb_strpos($el->sSelect, "BE.ID") !== false
				&& !$el->bCatalogSort
			)
			{
				$nPageSize = (int)($arNavStartParams['nPageSize'] ?? 0);
				if ($nPageSize < 0)
				{
					$nPageSize = 0;
				}

				$connection = Main\Application::getConnection();

				if ($connection instanceof Main\DB\PgsqlConnection)
				{
					$res = $connection->query('
						select * from (
							select
								sum(
									case when be.ID = ' . $nElementID . ' then 1 else 0 end
								) OVER (' . $el->sOrderBy . ' rows between ' . $nPageSize . ' preceding and ' . $nPageSize . ' following) as wndrank,
								row_number() OVER (' . $el->sOrderBy . ') as rank,
								' . $el->sSelect . '
							from ' . $el->sFrom . '
							where 1=1 ' . $el->sWhere . '
							' . $el->sGroupBy . '
							' . $el->sOrderBy . '
						) t where t.wndrank > 0
					');
				}
				else
				{
					$helper = $connection->getSqlHelper();
					if ($nPageSize > 0)
					{
						$DB->Query("SET @ranx=0");
						$DB->Query("
							SELECT /*+ NO_DERIVED_CONDITION_PUSHDOWN() */ @ranx:=el1.ranx
							FROM (
								SELECT @ranx:=@ranx+1 AS ranx, el0.*
								FROM (
									SELECT ".$el->sSelect."
									FROM ".$el->sFrom."
									WHERE 1=1 ".$el->sWhere."
									".$el->sGroupBy."
									".$el->sOrderBy."
									LIMIT 18446744073709551615
								) el0
							) el1
							WHERE el1.ID = ".$nElementID."
						");
						$DB->Query("SET @ranx2=0");

						$res = $DB->Query("
							SELECT *
							FROM (
								SELECT @ranx2:=@ranx2+1 AS ".$helper->quote('RANK').", el0.*
								FROM (
									SELECT ".$el->sSelect."
									FROM ".$el->sFrom."
									WHERE 1=1 ".$el->sWhere."
									".$el->sGroupBy."
									".$el->sOrderBy."
									LIMIT 18446744073709551615
								) el0
							) el1
							WHERE el1.".$helper->quote('RANK')." between @ranx-$nPageSize and @ranx+$nPageSize
						");
					}
					else
					{
						$DB->Query("SET @ranx=0");
						$res = $DB->Query("
							SELECT /*+ NO_DERIVED_CONDITION_PUSHDOWN() */ el1.*
							FROM (
								SELECT @ranx:=@ranx+1 AS ".$helper->quote('RANK').", el0.*
								FROM (
									SELECT ".$el->sSelect."
									FROM ".$el->sFrom."
									WHERE 1=1 ".$el->sWhere."
									".$el->sGroupBy."
									".$el->sOrderBy."
									LIMIT 18446744073709551615
								) el0
							) el1
							WHERE el1.ID = ".$nElementID."
						");
					}
				}
			}
			else
			{
				if ($el->sGroupBy == "")
				{
					$res_cnt = $DB->Query("
						SELECT COUNT(".($el->bDistinct? "DISTINCT BE.ID": "'x'").") as C
						FROM ".$el->countFrom."
						WHERE 1=1 ".$el->sWhere."
						".$el->sGroupBy."
					");
					$res_cnt = $res_cnt->Fetch();
					$cnt = $res_cnt["C"];
				}
				else
				{
					$res_cnt = $DB->Query("
						SELECT 'x'
						FROM ".$el->countFrom."
						WHERE 1=1 ".$el->sWhere."
						".$el->sGroupBy."
					");
					$cnt = $res_cnt->SelectedRowsCount();
				}

				$strSql = "
					SELECT ".$el->sSelect."
					FROM ".$el->sFrom."
					WHERE 1=1 ".$el->sWhere."
					".$el->sGroupBy."
					".$el->sOrderBy."
				";
				$res = new CDBResult();
				$res->NavQuery($strSql, $cnt, $arNavStartParams);
			}
		}
		else//if(is_array($arNavStartParams))
		{
			$strSql = "
				SELECT ".$el->sSelect."
				FROM ".$el->sFrom."
				WHERE 1=1 ".$el->sWhere."
				".$el->sGroupBy."
				".$el->sOrderBy."
			";
			$res = $DB->Query($strSql);
		}

		$res = new CIBlockResult($res);
		$res->SetIBlockTag($el->arFilterIBlocks);
		$res->arIBlockMultProps = $el->arIBlockMultProps;
		$res->arIBlockConvProps = $el->arIBlockConvProps;
		$res->arIBlockAllProps  = $el->arIBlockAllProps;
		$res->arIBlockNumProps = $el->arIBlockNumProps;
		$res->arIBlockLongProps = $el->arIBlockLongProps;

		return $res;
	}

	///////////////////////////////////////////////////////////////////
	// Update element function
	///////////////////////////////////////////////////////////////////
	public function Update($ID, $arFields, $bWorkFlow=false, $bUpdateSearch=true, $bResizePictures=false, $bCheckDiskQuota=true)
	{
		global $DB;
		$ID = (int)$ID;

		$db_element = CIBlockElement::GetList(array(), array("ID"=>$ID, "SHOW_HISTORY"=>"Y"), false, false,
			array(
				"ID",
				"TIMESTAMP_X",
				"MODIFIED_BY",
				"DATE_CREATE",
				"CREATED_BY",
				"IBLOCK_ID",
				"IBLOCK_SECTION_ID",
				"ACTIVE",
				"ACTIVE_FROM",
				"ACTIVE_TO",
				"SORT",
				"NAME",
				"PREVIEW_PICTURE",
				"PREVIEW_TEXT",
				"PREVIEW_TEXT_TYPE",
				"DETAIL_PICTURE",
				"DETAIL_TEXT",
				"DETAIL_TEXT_TYPE",
				"WF_STATUS_ID",
				"WF_PARENT_ELEMENT_ID",
				"WF_NEW",
				"WF_COMMENTS",
				"IN_SECTIONS",
				"CODE",
				"TAGS",
				"XML_ID",
				"TMP_ID",
			)
		);
		if(!($ar_element = $db_element->Fetch()))
			return false;

		if ($this->iblock !== null && $this->iblock['ID'] === (int)$ar_element["IBLOCK_ID"])
		{
			$arIBlock = $this->iblock;
		}
		else
		{
			$arIBlock = CIBlock::GetArrayByID($ar_element["IBLOCK_ID"]);
		}

		$bWorkFlow = $bWorkFlow && is_array($arIBlock) && ($arIBlock["WORKFLOW"] != "N") && $this->workflowIncluded;

		$ar_wf_element = $ar_element;

		self::$elementIblock[$ID] = $arIBlock["ID"];

		$LAST_ID = 0;
		if($bWorkFlow)
		{
			$LAST_ID = CIBlockElement::WF_GetLast($ID);
			if($LAST_ID!=$ID)
			{
				$db_element = CIBlockElement::GetByID($LAST_ID);
				if(!($ar_wf_element = $db_element->Fetch()))
					return false;
			}

			$arFields["WF_PARENT_ELEMENT_ID"] = $ID;

			if(!isset($arFields["PROPERTY_VALUES"]) || !is_array($arFields["PROPERTY_VALUES"]))
				$arFields["PROPERTY_VALUES"] = array();

			$bFieldProps = array();
			foreach($arFields["PROPERTY_VALUES"] as $k=>$v)
				$bFieldProps[$k]=true;

			$arFieldProps = &$arFields['PROPERTY_VALUES'];
			$props = CIBlockElement::GetProperty($ar_element["IBLOCK_ID"], $ar_wf_element["ID"]);
			while($arProp = $props->Fetch())
			{
				$pr_val_id = $arProp['PROPERTY_VALUE_ID'];
				if($arProp['PROPERTY_TYPE']=='F' && $pr_val_id <> '')
				{
					if($arProp["CODE"] <> '' && is_set($arFieldProps, $arProp["CODE"]))
						$pr_id = $arProp["CODE"];
					else
						$pr_id = $arProp['ID'];

					if (
						isset($arFieldProps[$pr_id][$pr_val_id])
						&& is_array($arFieldProps[$pr_id][$pr_val_id])
					)
					{
						$new_value = $arFieldProps[$pr_id][$pr_val_id];
						if(
							$new_value['name'] == ''
							&& $new_value['del'] != "Y"
							&& $new_value['VALUE']['name'] == ''
							&& $new_value['VALUE']['del'] != "Y"
						)
						{
							if(
								array_key_exists('DESCRIPTION', $new_value)
								&& ($new_value['DESCRIPTION'] != $arProp['DESCRIPTION'])
							)
							{
								$p = Array("VALUE"=>CFile::MakeFileArray($arProp['VALUE']));
								$p["DESCRIPTION"] = $new_value["DESCRIPTION"];
								$p["MODULE_ID"] = "iblock";
								$arFieldProps[$pr_id][$pr_val_id] = $p;
							}
							elseif($arProp['VALUE'] > 0)
							{
								$arFieldProps[$pr_id][$pr_val_id] = array("VALUE"=>$arProp['VALUE'],"DESCRIPTION"=>$arProp["DESCRIPTION"]);
							}
						}
					}
					else
					{
						$arFieldProps[$pr_id][$pr_val_id] = array("VALUE"=>$arProp['VALUE'],"DESCRIPTION"=>$arProp["DESCRIPTION"]);
					}

					continue;
				}

				if (
					$pr_val_id == ''
					|| array_key_exists($arProp["ID"], $bFieldProps)
					|| (
						$arProp["CODE"] <> ''
						&& array_key_exists($arProp["CODE"], $bFieldProps)
					)
				)
					continue;

				$arFieldProps[$arProp["ID"]][$pr_val_id] = array("VALUE"=>$arProp['VALUE'],"DESCRIPTION"=>$arProp["DESCRIPTION"]);
			}

			if($ar_wf_element["IN_SECTIONS"] == "Y")
			{
				$ar_wf_element["IBLOCK_SECTION"] = array();
				$rsSections = CIBlockElement::GetElementGroups($ar_element["ID"], true, array('ID', 'IBLOCK_ELEMENT_ID'));
				while($arSection = $rsSections->Fetch())
					$ar_wf_element["IBLOCK_SECTION"][] = $arSection["ID"];
			}

			unset($ar_wf_element["DATE_ACTIVE_FROM"],
				$ar_wf_element["DATE_ACTIVE_TO"],
				$ar_wf_element["EXTERNAL_ID"],
				$ar_wf_element["TIMESTAMP_X"],
				$ar_wf_element["IBLOCK_SECTION_ID"],
				$ar_wf_element["ID"]
			);

			$arFields = $arFields + $ar_wf_element;
		}

		$arFields["WF"] = ($bWorkFlow?"Y":"N");

		$bBizProc = is_array($arIBlock) && ($arIBlock["BIZPROC"] == "Y") && $this->bizprocInstalled;
		if(array_key_exists("BP_PUBLISHED", $arFields))
		{
			if($bBizProc)
			{
				if($arFields["BP_PUBLISHED"] == "Y")
				{
					$arFields["WF_STATUS_ID"] = 1;
					$arFields["WF_NEW"] = false;
				}
				else
				{
					$arFields["WF_STATUS_ID"] = 2;
					$arFields["WF_NEW"] = "Y";
					$arFields["BP_PUBLISHED"] = "N";
				}
			}
			else
			{
				$arFields["WF_NEW"] = false;
				unset($arFields["BP_PUBLISHED"]);
			}
		}
		else
		{
			$arFields["WF_NEW"] = false;
		}

		if (array_key_exists('NAME', $arFields) && $arFields['NAME'] === null)
		{
			unset($arFields['NAME']);
		}

		if (isset($arFields["ACTIVE"]) && $arFields["ACTIVE"] != "Y")
		{
			$arFields["ACTIVE"] = "N";
		}

		if (isset($arFields["PREVIEW_TEXT_TYPE"]) && $arFields["PREVIEW_TEXT_TYPE"] != "html")
		{
			$arFields["PREVIEW_TEXT_TYPE"] = "text";
		}

		if (isset($arFields["DETAIL_TEXT_TYPE"]) && $arFields["DETAIL_TEXT_TYPE"] != "html")
		{
			$arFields["DETAIL_TEXT_TYPE"] = "text";
		}

		$strWarning = "";
		if($bResizePictures)
		{
			$arDef = $arIBlock["FIELDS"]["PREVIEW_PICTURE"]["DEFAULT_VALUE"];

			if(
				$arDef["DELETE_WITH_DETAIL"] === "Y"
				&& isset($arFields["DETAIL_PICTURE"])
				&& is_array($arFields["DETAIL_PICTURE"])
				&& $arFields["DETAIL_PICTURE"]["del"] === "Y"
			)
			{
				$arFields["PREVIEW_PICTURE"]["del"] = "Y";
			}

			if(
				$arDef["FROM_DETAIL"] === "Y"
				&& (
					!isset($arFields["PREVIEW_PICTURE"])
					|| (is_array($arFields["PREVIEW_PICTURE"]) && $arFields["PREVIEW_PICTURE"]["size"] <= 0)
					|| $arDef["UPDATE_WITH_DETAIL"] === "Y"
				)
				&& isset($arFields["DETAIL_PICTURE"])
				&& is_array($arFields["DETAIL_PICTURE"])
				&& $arFields["DETAIL_PICTURE"]["size"] > 0
			)
			{
				if(
					$arFields["PREVIEW_PICTURE"]["del"] !== "Y"
					&& $arDef["UPDATE_WITH_DETAIL"] !== "Y"
				)
				{
					$rsElement = CIBlockElement::GetList(Array("ID" => "DESC"), Array("ID" => $ar_wf_element["ID"], "IBLOCK_ID" => $ar_wf_element["IBLOCK_ID"], "SHOW_HISTORY"=>"Y"), false, false, Array("ID", "PREVIEW_PICTURE"));
					$arOldElement = $rsElement->Fetch();
				}
				else
				{
					$arOldElement = false;
				}

				if(!$arOldElement || !$arOldElement["PREVIEW_PICTURE"])
				{
					$arNewPreview = $arFields["DETAIL_PICTURE"];
					$arNewPreview["COPY_FILE"] = "Y";
					if (
						isset($arFields["PREVIEW_PICTURE"])
						&& is_array($arFields["PREVIEW_PICTURE"])
						&& isset($arFields["PREVIEW_PICTURE"]["description"])
					)
					{
						$arNewPreview["description"] = $arFields["PREVIEW_PICTURE"]["description"];
					}

					$arFields["PREVIEW_PICTURE"] = $arNewPreview;
				}
			}

			if(
				isset($arFields["PREVIEW_PICTURE"])
				&& is_array($arFields["PREVIEW_PICTURE"])
				&& $arFields["PREVIEW_PICTURE"]["size"] > 0
				&& $arDef["SCALE"] === "Y"
			)
			{
				$arNewPicture = CIBlock::ResizePicture($arFields["PREVIEW_PICTURE"], $arDef);
				if(is_array($arNewPicture))
				{
					$arNewPicture["description"] = $arFields["PREVIEW_PICTURE"]["description"];
					$arFields["PREVIEW_PICTURE"] = $arNewPicture;
				}
				elseif($arDef["IGNORE_ERRORS"] !== "Y")
				{
					unset($arFields["PREVIEW_PICTURE"]);
					$strWarning .= GetMessage("IBLOCK_FIELD_PREVIEW_PICTURE").": ".$arNewPicture."<br>";
				}
			}

			if(
				isset($arFields["PREVIEW_PICTURE"])
				&& is_array($arFields["PREVIEW_PICTURE"])
				&& $arDef["USE_WATERMARK_FILE"] === "Y"
			)
			{
				$arFields["PREVIEW_PICTURE"]["copy"] ??= null;
				if(
					$arFields["PREVIEW_PICTURE"]["tmp_name"] <> ''
					&& (
						$arFields["PREVIEW_PICTURE"]["tmp_name"] === $arFields["DETAIL_PICTURE"]["tmp_name"]
						|| ($arFields["PREVIEW_PICTURE"]["COPY_FILE"] == "Y" && !$arFields["PREVIEW_PICTURE"]["copy"])
					)
				)
				{
					$tmp_name = CTempFile::GetFileName(basename($arFields["PREVIEW_PICTURE"]["tmp_name"]));
					CheckDirPath($tmp_name);
					copy($arFields["PREVIEW_PICTURE"]["tmp_name"], $tmp_name);
					$arFields["PREVIEW_PICTURE"]["copy"] = true;
					$arFields["PREVIEW_PICTURE"]["tmp_name"] = $tmp_name;
				}

				CIBlock::FilterPicture($arFields["PREVIEW_PICTURE"]["tmp_name"], array(
					"name" => "watermark",
					"position" => $arDef["WATERMARK_FILE_POSITION"],
					"type" => "file",
					"size" => "real",
					"alpha_level" => 100 - min(max($arDef["WATERMARK_FILE_ALPHA"], 0), 100),
					"file" => $_SERVER["DOCUMENT_ROOT"].Rel2Abs("/", $arDef["WATERMARK_FILE"]),
				));
			}

			if(
				isset($arFields["PREVIEW_PICTURE"])
				&& is_array($arFields["PREVIEW_PICTURE"])
				&& $arDef["USE_WATERMARK_TEXT"] === "Y"
			)
			{
				$arFields["PREVIEW_PICTURE"]["copy"] ??= null;
				if(
					$arFields["PREVIEW_PICTURE"]["tmp_name"] <> ''
					&& (
						$arFields["PREVIEW_PICTURE"]["tmp_name"] === $arFields["DETAIL_PICTURE"]["tmp_name"]
						|| ($arFields["PREVIEW_PICTURE"]["COPY_FILE"] == "Y" && !$arFields["PREVIEW_PICTURE"]["copy"])
					)
				)
				{
					$tmp_name = CTempFile::GetFileName(basename($arFields["PREVIEW_PICTURE"]["tmp_name"]));
					CheckDirPath($tmp_name);
					copy($arFields["PREVIEW_PICTURE"]["tmp_name"], $tmp_name);
					$arFields["PREVIEW_PICTURE"]["copy"] = true;
					$arFields["PREVIEW_PICTURE"]["tmp_name"] = $tmp_name;
				}

				CIBlock::FilterPicture($arFields["PREVIEW_PICTURE"]["tmp_name"], array(
					"name" => "watermark",
					"position" => $arDef["WATERMARK_TEXT_POSITION"],
					"type" => "text",
					"coefficient" => $arDef["WATERMARK_TEXT_SIZE"],
					"text" => $arDef["WATERMARK_TEXT"],
					"font" => $_SERVER["DOCUMENT_ROOT"].Rel2Abs("/", $arDef["WATERMARK_TEXT_FONT"]),
					"color" => $arDef["WATERMARK_TEXT_COLOR"],
				));
			}

			$arDef = $arIBlock["FIELDS"]["DETAIL_PICTURE"]["DEFAULT_VALUE"];

			if(
				isset($arFields["DETAIL_PICTURE"])
				&& is_array($arFields["DETAIL_PICTURE"])
				&& $arDef["SCALE"] === "Y"
			)
			{
				$arNewPicture = CIBlock::ResizePicture($arFields["DETAIL_PICTURE"], $arDef);
				if(is_array($arNewPicture))
				{
					$arNewPicture["description"] = $arFields["DETAIL_PICTURE"]["description"];
					$arFields["DETAIL_PICTURE"] = $arNewPicture;
				}
				elseif($arDef["IGNORE_ERRORS"] !== "Y")
				{
					unset($arFields["DETAIL_PICTURE"]);
					$strWarning .= GetMessage("IBLOCK_FIELD_DETAIL_PICTURE").": ".$arNewPicture."<br>";
				}
			}

			if(
				isset($arFields["DETAIL_PICTURE"])
				&& is_array($arFields["DETAIL_PICTURE"])
				&& $arDef["USE_WATERMARK_FILE"] === "Y"
			)
			{
				$arFields["DETAIL_PICTURE"]["copy"] ??= null;
				if(
					$arFields["DETAIL_PICTURE"]["tmp_name"] <> ''
					&& (
						$arFields["DETAIL_PICTURE"]["tmp_name"] === $arFields["PREVIEW_PICTURE"]["tmp_name"]
						|| ($arFields["DETAIL_PICTURE"]["COPY_FILE"] == "Y" && !$arFields["DETAIL_PICTURE"]["copy"])
					)
				)
				{
					$tmp_name = CTempFile::GetFileName(basename($arFields["DETAIL_PICTURE"]["tmp_name"]));
					CheckDirPath($tmp_name);
					copy($arFields["DETAIL_PICTURE"]["tmp_name"], $tmp_name);
					$arFields["DETAIL_PICTURE"]["copy"] = true;
					$arFields["DETAIL_PICTURE"]["tmp_name"] = $tmp_name;
				}

				CIBlock::FilterPicture($arFields["DETAIL_PICTURE"]["tmp_name"], array(
					"name" => "watermark",
					"position" => $arDef["WATERMARK_FILE_POSITION"],
					"type" => "file",
					"size" => "real",
					"alpha_level" => 100 - min(max($arDef["WATERMARK_FILE_ALPHA"], 0), 100),
					"file" => $_SERVER["DOCUMENT_ROOT"].Rel2Abs("/", $arDef["WATERMARK_FILE"]),
				));
			}

			if(
				isset($arFields["DETAIL_PICTURE"])
				&& is_array($arFields["DETAIL_PICTURE"])
				&& $arDef["USE_WATERMARK_TEXT"] === "Y"
			)
			{
				$arFields["DETAIL_PICTURE"]["copy"] ??= null;
				if(
					$arFields["DETAIL_PICTURE"]["tmp_name"] <> ''
					&& (
						$arFields["DETAIL_PICTURE"]["tmp_name"] === $arFields["PREVIEW_PICTURE"]["tmp_name"]
						|| ($arFields["DETAIL_PICTURE"]["COPY_FILE"] == "Y" && !$arFields["DETAIL_PICTURE"]["copy"])
					)
				)
				{
					$tmp_name = CTempFile::GetFileName(basename($arFields["DETAIL_PICTURE"]["tmp_name"]));
					CheckDirPath($tmp_name);
					copy($arFields["DETAIL_PICTURE"]["tmp_name"], $tmp_name);
					$arFields["DETAIL_PICTURE"]["copy"] = true;
					$arFields["DETAIL_PICTURE"]["tmp_name"] = $tmp_name;
				}

				CIBlock::FilterPicture($arFields["DETAIL_PICTURE"]["tmp_name"], array(
					"name" => "watermark",
					"position" => $arDef["WATERMARK_TEXT_POSITION"],
					"type" => "text",
					"coefficient" => $arDef["WATERMARK_TEXT_SIZE"],
					"text" => $arDef["WATERMARK_TEXT"],
					"font" => $_SERVER["DOCUMENT_ROOT"].Rel2Abs("/", $arDef["WATERMARK_TEXT_FONT"]),
					"color" => $arDef["WATERMARK_TEXT_COLOR"],
				));
			}
		}

		$ipropTemplates = new \Bitrix\Iblock\InheritedProperty\ElementTemplates($ar_element["IBLOCK_ID"], $ar_element["ID"]);
		if (isset($arFields["PREVIEW_PICTURE"]) && is_array($arFields["PREVIEW_PICTURE"]))
		{
			if(
				($arFields["PREVIEW_PICTURE"]["name"] ?? '') === ''
				&& ($arFields["PREVIEW_PICTURE"]["del"] ?? '') === ''
				&& !is_set($arFields["PREVIEW_PICTURE"], "description")
			)
			{
				unset($arFields["PREVIEW_PICTURE"]);
			}
			else
			{
				$arFields["PREVIEW_PICTURE"]["MODULE_ID"] = "iblock";
				$arFields["PREVIEW_PICTURE"]["old_file"] = $ar_wf_element["PREVIEW_PICTURE"];
				$arFields["PREVIEW_PICTURE"]["name"] = \Bitrix\Iblock\Template\Helper::makeFileName(
					$ipropTemplates
					,"ELEMENT_PREVIEW_PICTURE_FILE_NAME"
					,array_merge($ar_element, $arFields)
					,$arFields["PREVIEW_PICTURE"]
				);
			}
		}

		if(isset($arFields["DETAIL_PICTURE"]) && is_array($arFields["DETAIL_PICTURE"]))
		{
			if(
				($arFields["DETAIL_PICTURE"]["name"] ?? '') === ''
				&& ($arFields["DETAIL_PICTURE"]["del"] ?? '') === ''
				&& !is_set($arFields["DETAIL_PICTURE"], "description")
			)
			{
				unset($arFields["DETAIL_PICTURE"]);
			}
			else
			{
				$arFields["DETAIL_PICTURE"]["MODULE_ID"] = "iblock";
				$arFields["DETAIL_PICTURE"]["old_file"] = $ar_wf_element["DETAIL_PICTURE"];
				$arFields["DETAIL_PICTURE"]["name"] = \Bitrix\Iblock\Template\Helper::makeFileName(
					$ipropTemplates
					,"ELEMENT_DETAIL_PICTURE_FILE_NAME"
					,array_merge($ar_element, $arFields)
					,$arFields["DETAIL_PICTURE"]
				);
			}
		}

		if(is_set($arFields, "DATE_ACTIVE_FROM"))
			$arFields["ACTIVE_FROM"] = $arFields["DATE_ACTIVE_FROM"];
		if(is_set($arFields, "DATE_ACTIVE_TO"))
			$arFields["ACTIVE_TO"] = $arFields["DATE_ACTIVE_TO"];
		if(is_set($arFields, "EXTERNAL_ID"))
			$arFields["XML_ID"] = $arFields["EXTERNAL_ID"];

		if (isset($arFields['NAME']) && is_array($arFields['NAME']))
		{
			unset($arFields['NAME']);
		}

		$existFields = array(
			'NAME' => isset($arFields['NAME']),
			'PREVIEW_TEXT' => array_key_exists('PREVIEW_TEXT', $arFields),
			'DETAIL_TEXT' => array_key_exists('DETAIL_TEXT', $arFields)
		);
		$searchableFields = [
			'NAME' => $existFields['NAME'] ? $arFields["NAME"] : $ar_wf_element["NAME"],
			'PREVIEW_TEXT' => $existFields['PREVIEW_TEXT'] ? $arFields["PREVIEW_TEXT"]: $ar_wf_element["PREVIEW_TEXT"],
			'PREVIEW_TEXT_TYPE' => $arFields["PREVIEW_TEXT_TYPE"] ?? $ar_wf_element["PREVIEW_TEXT_TYPE"],
			'DETAIL_TEXT' => $existFields['DETAIL_TEXT'] ? $arFields["DETAIL_TEXT"]: $ar_wf_element["DETAIL_TEXT"],
			'DETAIL_TEXT_TYPE' => $arFields["DETAIL_TEXT_TYPE"] ?? $ar_wf_element["DETAIL_TEXT_TYPE"],
		];

		if ($this->searchIncluded)
		{
			$arFields["SEARCHABLE_CONTENT"] = mb_strtoupper($searchableFields['NAME']."\r\n".
				($searchableFields['PREVIEW_TEXT_TYPE'] == "html" ? HTMLToTxt($searchableFields['PREVIEW_TEXT']) : $searchableFields['PREVIEW_TEXT'])."\r\n".
				($searchableFields['DETAIL_TEXT_TYPE'] == "html" ? HTMLToTxt($searchableFields['DETAIL_TEXT']) : $searchableFields['DETAIL_TEXT']));
		}

		if(array_key_exists("IBLOCK_SECTION_ID", $arFields))
		{
			if (!array_key_exists("IBLOCK_SECTION", $arFields))
			{
				$arFields["IBLOCK_SECTION"] = array($arFields["IBLOCK_SECTION_ID"]);
			}
			elseif (is_array($arFields["IBLOCK_SECTION"]) && !in_array($arFields["IBLOCK_SECTION_ID"], $arFields["IBLOCK_SECTION"]))
			{
				unset($arFields["IBLOCK_SECTION_ID"]);

			}
		}

		$arFields["IBLOCK_ID"] = $ar_element["IBLOCK_ID"];

		if(!$this->CheckFields($arFields, $ID, $bCheckDiskQuota) || $strWarning != '')
		{
			$this->LAST_ERROR .= $strWarning;
			$Result = false;
			$arFields["RESULT_MESSAGE"] = &$this->LAST_ERROR;
		}
		else
		{
			unset($arFields["ID"]);

			if(array_key_exists("PREVIEW_PICTURE", $arFields))
			{
				$SAVED_PREVIEW_PICTURE = $arFields["PREVIEW_PICTURE"];
			}
			else
			{
				$SAVED_PREVIEW_PICTURE = false;
			}

			if(array_key_exists("DETAIL_PICTURE", $arFields))
			{
				$SAVED_DETAIL_PICTURE = $arFields["DETAIL_PICTURE"];
			}
			else
			{
				$SAVED_DETAIL_PICTURE = false;
			}

			// edit was done in workflow mode
			if($bWorkFlow)
			{
				$arFields["WF_PARENT_ELEMENT_ID"] = $ID;

				if(array_key_exists("PREVIEW_PICTURE", $arFields))
				{
					if(is_array($arFields["PREVIEW_PICTURE"]))
					{
						if(
							$arFields["PREVIEW_PICTURE"]["name"] == ''
							&& ($arFields["PREVIEW_PICTURE"]["del"] ?? null) == ''
						)
						{
							if(array_key_exists("description", $arFields["PREVIEW_PICTURE"]))
							{
								$arFile = CFile::GetFileArray($ar_wf_element["PREVIEW_PICTURE"]);
								if($arFields["PREVIEW_PICTURE"]["description"] != $arFile["DESCRIPTION"])
								{//Description updated, so it's new file
									$arNewFile = CFile::MakeFileArray($ar_wf_element["PREVIEW_PICTURE"]);
									$arNewFile["description"] = $arFields["PREVIEW_PICTURE"]["description"];
									$arNewFile["MODULE_ID"] = "iblock";
									$arFields["PREVIEW_PICTURE"] = $arNewFile;
								}
								else
								{
									$arFields["PREVIEW_PICTURE"] = $ar_wf_element["PREVIEW_PICTURE"];
								}
							}
							else
							{
								//File was not changed at all
								$arFields["PREVIEW_PICTURE"] = $ar_wf_element["PREVIEW_PICTURE"];
							}
						}
						else
						{
							unset($arFields["PREVIEW_PICTURE"]["old_file"]);
						}
					}
				}
				else
				{
					$arFields["PREVIEW_PICTURE"] = $ar_wf_element["PREVIEW_PICTURE"];
				}

				if(array_key_exists("DETAIL_PICTURE", $arFields))
				{
					if(is_array($arFields["DETAIL_PICTURE"]))
					{
						if(
							$arFields["DETAIL_PICTURE"]["name"] == ''
							&& ($arFields["DETAIL_PICTURE"]["del"] ?? null) == ''
						)
						{
							if(array_key_exists("description", $arFields["DETAIL_PICTURE"]))
							{
								$arFile = CFile::GetFileArray($ar_wf_element["DETAIL_PICTURE"]);
								if($arFields["DETAIL_PICTURE"]["description"] != $arFile["DESCRIPTION"])
								{//Description updated, so it's new file
									$arNewFile = CFile::MakeFileArray($ar_wf_element["DETAIL_PICTURE"]);
									$arNewFile["description"] = $arFields["DETAIL_PICTURE"]["description"];
									$arNewFile["MODULE_ID"] = "iblock";
									$arFields["DETAIL_PICTURE"] = $arNewFile;
								}
								else
								{
									$arFields["DETAIL_PICTURE"] = $ar_wf_element["DETAIL_PICTURE"];
								}
							}
							else
							{
								//File was not changed at all
								$arFields["DETAIL_PICTURE"] = $ar_wf_element["DETAIL_PICTURE"];
							}
						}
						else
						{
							unset($arFields["DETAIL_PICTURE"]["old_file"]);
						}
					}
				}
				else
				{
					$arFields["DETAIL_PICTURE"] = $ar_wf_element["DETAIL_PICTURE"];
				}

				$NID = $this->Add($arFields);
				if($NID>0)
				{
					if($arFields["WF_STATUS_ID"]==1)
					{
						$DB->Query("UPDATE b_iblock_element SET TIMESTAMP_X=TIMESTAMP_X, WF_NEW=null WHERE ID=".$ID);
						$DB->Query("UPDATE b_iblock_element SET TIMESTAMP_X=TIMESTAMP_X, WF_NEW=null WHERE WF_PARENT_ELEMENT_ID=".$ID);
						$ar_wf_element["WF_NEW"] = false;
					}

					if($this->bWF_SetMove)
						CIBlockElement::WF_SetMove($NID, $LAST_ID);

					if($ar_element["WF_STATUS_ID"] != 1
						&& $ar_wf_element["WF_STATUS_ID"] != $arFields["WF_STATUS_ID"]
						&& $arFields["WF_STATUS_ID"] != 1
						)
					{
						$DB->Query("UPDATE b_iblock_element SET TIMESTAMP_X=TIMESTAMP_X, WF_STATUS_ID=".(int)$arFields["WF_STATUS_ID"]." WHERE ID=".$ID);
					}
				}

				//element was not published, so keep original
				if(
					(is_set($arFields, "WF_STATUS_ID") && $arFields["WF_STATUS_ID"]!=1 && $ar_element["WF_STATUS_ID"]==1)
					|| (!is_set($arFields, "WF_STATUS_ID") && $ar_wf_element["WF_STATUS_ID"]!=1)
				)
				{
					CIBlockElement::WF_CleanUpHistoryCopies($ID);
					return true;
				}

				$arFields['WF_PARENT_ELEMENT_ID'] = false;

				$rs = $DB->Query("SELECT PREVIEW_PICTURE, DETAIL_PICTURE from b_iblock_element WHERE ID = ".(int)$NID);
				$ar_new_element = $rs->Fetch();
			}
			else
			{
				$ar_new_element = false;
			}

			if($ar_new_element)
			{
				if((int)$ar_new_element["PREVIEW_PICTURE"] <= 0)
					$arFields["PREVIEW_PICTURE"] = false;
				else
					$arFields["PREVIEW_PICTURE"] = $ar_new_element["PREVIEW_PICTURE"];

				if((int)$ar_new_element["DETAIL_PICTURE"] <= 0)
					$arFields["DETAIL_PICTURE"] = false;
				else
					$arFields["DETAIL_PICTURE"] = $ar_new_element["DETAIL_PICTURE"];

				if(is_array($arFields["PROPERTY_VALUES"]) && !empty($arFields["PROPERTY_VALUES"]))
				{
					$i = 0;
					$db_prop = CIBlockProperty::GetList(array(), array(
						"IBLOCK_ID" => $arFields["IBLOCK_ID"],
						"CHECK_PERMISSIONS" => "N",
						"PROPERTY_TYPE" => "F",
					));
					while($arProp = $db_prop->Fetch())
					{
						$i++;
						unset($arFields["PROPERTY_VALUES"][$arProp["CODE"]]);
						unset($arFields["PROPERTY_VALUES"][$arProp["ID"]]);
						$arFields["PROPERTY_VALUES"][$arProp["ID"]] = array();
					}

					if($i > 0)
					{
						//Delete previous files
						$props = CIBlockElement::GetProperty($arFields["IBLOCK_ID"], $ID, "sort", "asc", array("PROPERTY_TYPE" => "F", "EMPTY" => "N"));
						while($arProp = $props->Fetch())
						{
							$arFields["PROPERTY_VALUES"][$arProp["ID"]][$arProp['PROPERTY_VALUE_ID']] = array(
								"VALUE" => array(
									"del" => "Y",
								),
								"DESCRIPTION" => false,
							);
						}
						//Add copy from history
						$arDup = array();//This is cure for files duplication bug (just save element one more time)
						$props = CIBlockElement::GetProperty($arFields["IBLOCK_ID"], $NID, "sort", "asc", array("PROPERTY_TYPE" => "F", "EMPTY" => "N"));
						while($arProp = $props->Fetch())
						{
							if(!array_key_exists($arProp["VALUE"], $arDup))//This is cure for files duplication bug
							{
								$arFields["PROPERTY_VALUES"][$arProp["ID"]][$arProp['PROPERTY_VALUE_ID']] = array(
									"VALUE" => $arProp["VALUE"],
									"DESCRIPTION" => $arProp["DESCRIPTION"],
								);
								$arDup[$arProp["VALUE"]] = true;//This is cure for files duplication bug
							}
						}
					}
				}
			}
			else
			{
				if(array_key_exists("PREVIEW_PICTURE", $arFields))
					CFile::SaveForDB($arFields, "PREVIEW_PICTURE", "iblock");
				if(array_key_exists("DETAIL_PICTURE", $arFields))
					CFile::SaveForDB($arFields, "DETAIL_PICTURE", "iblock");
			}

			$newFields = $arFields;
			$newFields["ID"] = $ID;
			$IBLOCK_SECTION_ID = $arFields["IBLOCK_SECTION_ID"] ?? null;
			unset($arFields["IBLOCK_ID"], $arFields["WF_NEW"], $arFields["IBLOCK_SECTION_ID"]);

			$bTimeStampNA = false;
			if(is_set($arFields, "TIMESTAMP_X") && ($arFields["TIMESTAMP_X"] === NULL || $arFields["TIMESTAMP_X"]===false))
			{
				$bTimeStampNA = true;
				unset($arFields["TIMESTAMP_X"]);
				unset($newFields["TIMESTAMP_X"]);
			}

			foreach (GetModuleEvents("iblock", "OnIBlockElementUpdate", true) as $arEvent)
				ExecuteModuleEventEx($arEvent, array($newFields, $ar_wf_element));
			unset($newFields);

			$strUpdate = $DB->PrepareUpdate("b_iblock_element", $arFields, "iblock");

			if(!empty($strUpdate))
				$strUpdate .= ", ";

			$strSql = "UPDATE b_iblock_element SET ".$strUpdate.($bTimeStampNA?"TIMESTAMP_X=TIMESTAMP_X":"TIMESTAMP_X=now()")." WHERE ID=".$ID;
			$DB->Query($strSql);

			$existFields['PROPERTY_VALUES'] = (
				!empty($arFields['PROPERTY_VALUES'])
				&& is_array($arFields['PROPERTY_VALUES'])
			);

			if ($existFields['PROPERTY_VALUES'])
			{
				CIBlockElement::SetPropertyValues($ID, $ar_element["IBLOCK_ID"], $arFields["PROPERTY_VALUES"]);
			}

			if (!$this->searchIncluded)
			{
				if (
					!array_key_exists('SEARCHABLE_CONTENT', $arFields)
					&& (
						$existFields['NAME']
						|| $existFields['PREVIEW_TEXT']
						|| $existFields['DETAIL_TEXT']
						|| $existFields['PROPERTY_VALUES']
					)
				)
				{
					$elementFields = $arFields;
					if (!$existFields['NAME'])
					{
						$elementFields['NAME'] = $searchableFields['NAME'];
					}
					if (!$existFields['PREVIEW_TEXT'])
					{
						$elementFields['PREVIEW_TEXT'] = $searchableFields['PREVIEW_TEXT'];
						if (!isset($elementFields['PREVIEW_TEXT_TYPE']))
						{
							$elementFields['PREVIEW_TEXT_TYPE'] = $searchableFields['PREVIEW_TEXT_TYPE'];
						}
					}
					if (!$existFields['DETAIL_TEXT'])
					{
						$elementFields['DETAIL_TEXT'] = $searchableFields['DETAIL_TEXT'];
						if (!isset($elementFields['DETAIL_TEXT_TYPE']))
						{
							$elementFields['DETAIL_TEXT_TYPE'] = $searchableFields['DETAIL_TEXT_TYPE'];
						}
					}
					$arFields['SEARCHABLE_CONTENT'] = $this->getSearchableContent($ID, $elementFields, $arIBlock);
					unset($elementFields);

					if (Iblock\FullIndex\FullText::doesIblockSupportByData($arIBlock))
					{
						$searchIndexParams = [
							"SEARCH_CONTENT" => $arFields['SEARCHABLE_CONTENT'],
						];

						Iblock\FullIndex\FullText::update($arIBlock['ID'], $ID, $searchIndexParams);
					}

					$updateFields = array(
						'SEARCHABLE_CONTENT' => $arFields['SEARCHABLE_CONTENT']
					);
					$updateQuery = $DB->PrepareUpdate("b_iblock_element", $updateFields, "iblock");
					if ($updateQuery != "")
					{
						$updateQuery .= ', TIMESTAMP_X = TIMESTAMP_X';
						$DB->Query("UPDATE b_iblock_element SET ".$updateQuery." WHERE ID = ".$ID);
					}
					unset($updateFields);
				}
			}

			if(is_set($arFields, "IBLOCK_SECTION"))
				CIBlockElement::SetElementSection($ID, $arFields["IBLOCK_SECTION"], false, $arIBlock["RIGHTS_MODE"] === "E"? $arIBlock["ID"]: 0, $IBLOCK_SECTION_ID);

			if ($arIBlock["RIGHTS_MODE"] === Iblock\IblockTable::RIGHTS_EXTENDED)
			{
				$obElementRights = new CIBlockElementRights($arIBlock["ID"], $ID);
				if(array_key_exists("RIGHTS", $arFields) && is_array($arFields["RIGHTS"]))
					$obElementRights->SetRights($arFields["RIGHTS"]);
			}

			if (array_key_exists("IPROPERTY_TEMPLATES", $arFields))
			{
				$ipropTemplates = new \Bitrix\Iblock\InheritedProperty\ElementTemplates($arIBlock["ID"], $ID);
				$ipropTemplates->set($arFields["IPROPERTY_TEMPLATES"]);
			}

			if ($bUpdateSearch)
			{
				if ($this->searchIncluded)
				{
					CIBlockElement::UpdateSearch($ID, true);
				}
			}

			\Bitrix\Iblock\PropertyIndex\Manager::updateElementIndex($arIBlock["ID"], $ID);

			if($bWorkFlow)
			{
				CIBlockElement::WF_CleanUpHistoryCopies($ID);
			}

			//Restore saved values
			if($SAVED_PREVIEW_PICTURE !== false)
			{
				$arFields["PREVIEW_PICTURE_ID"] = ($arFields["PREVIEW_PICTURE"] ?? null);
				$arFields["PREVIEW_PICTURE"] = $SAVED_PREVIEW_PICTURE;
			}
			else
			{
				unset($arFields["PREVIEW_PICTURE"]);
			}

			if($SAVED_DETAIL_PICTURE !== false)
			{
				$arFields["DETAIL_PICTURE_ID"] = ($arFields["DETAIL_PICTURE"] ?? null);
				$arFields["DETAIL_PICTURE"] = $SAVED_DETAIL_PICTURE;
			}
			else
			{
				unset($arFields["DETAIL_PICTURE"]);
			}

			if($arIBlock["FIELDS"]["LOG_ELEMENT_EDIT"]["IS_REQUIRED"] == "Y")
			{
				$arEvents = GetModuleEvents("main", "OnBeforeEventLog", true);
				if(empty($arEvents) || ExecuteModuleEventEx($arEvents[0], array($this->userId))===false)
				{
					$rsElement = CIBlockElement::GetList(
						array(),
						array("=ID" => $ID, "CHECK_PERMISSIONS" => "N", "SHOW_NEW" => "Y"),
						false, false,
						array("ID", "NAME", "LIST_PAGE_URL", "CODE")
					);
					$arElement = $rsElement->GetNext();
					$res = array(
						"ID" => $ID,
						"CODE" => $arElement["CODE"],
						"NAME" => $arElement["NAME"],
						"ELEMENT_NAME" => $arIBlock["ELEMENT_NAME"],
						"USER_ID" => $this->userId,
						"IBLOCK_PAGE_URL" => $arElement["LIST_PAGE_URL"],
					);
					CEventLog::Log(
						"IBLOCK",
						"IBLOCK_ELEMENT_EDIT",
						"iblock",
						$arIBlock["ID"],
						serialize($res)
					);
				}
			}

			Iblock\ElementTable::cleanCache();

			$Result = true;

			/************* QUOTA *************/
			CDiskQuota::recalculateDb();
			/************* QUOTA *************/
		}

		$arFields["ID"] = $ID;
		$arFields["IBLOCK_ID"] = $ar_element["IBLOCK_ID"];
		$arFields["RESULT"] = &$Result;

		if(
			isset($arFields["PREVIEW_PICTURE"])
			&& is_array($arFields["PREVIEW_PICTURE"])
			&& ($arFields["PREVIEW_PICTURE"]["COPY_FILE"] ?? '') === "Y"
			&& ($arFields["PREVIEW_PICTURE"]["copy"] ?? null)
		)
		{
			@unlink($arFields["PREVIEW_PICTURE"]["tmp_name"]);
			@rmdir(dirname($arFields["PREVIEW_PICTURE"]["tmp_name"]));
		}

		if(
			isset($arFields["DETAIL_PICTURE"])
			&& is_array($arFields["DETAIL_PICTURE"])
			&& ($arFields["DETAIL_PICTURE"]["COPY_FILE"] ?? '') === "Y"
			&& ($arFields["DETAIL_PICTURE"]["copy"] ?? null)
		)
		{
			@unlink($arFields["DETAIL_PICTURE"]["tmp_name"]);
			@rmdir(dirname($arFields["DETAIL_PICTURE"]["tmp_name"]));
		}

		foreach (GetModuleEvents("iblock", "OnAfterIBlockElementUpdate", true) as $arEvent)
			ExecuteModuleEventEx($arEvent, array(&$arFields));

		CIBlock::clearIblockTagCache($arIBlock['ID']);

		return $Result;
	}

	public static function SetPropertyValues($ELEMENT_ID, $IBLOCK_ID, $PROPERTY_VALUES, $PROPERTY_CODE = false)
	{
		global $BX_IBLOCK_PROP_CACHE;

		$ELEMENT_ID = (int)$ELEMENT_ID;
		$IBLOCK_ID = (int)$IBLOCK_ID;

		if (!is_array($PROPERTY_VALUES))
			$PROPERTY_VALUES = array($PROPERTY_VALUES);

		$uniq_flt = $IBLOCK_ID;
		$arFilter = array(
			"IBLOCK_ID" => $IBLOCK_ID,
			"CHECK_PERMISSIONS" => "N",
		);

		if ($PROPERTY_CODE === false)
		{
			$arFilter["ACTIVE"] = "Y";
			$uniq_flt .= "|ACTIVE:".$arFilter["ACTIVE"];
		}
		elseif((int)$PROPERTY_CODE > 0)
		{
			$arFilter["ID"] = (int)$PROPERTY_CODE;
			$uniq_flt .= "|ID:".$arFilter["ID"];
		}
		else
		{
			$arFilter["CODE"] = $PROPERTY_CODE;
			$uniq_flt .= "|CODE:".$arFilter["CODE"];
		}

		if (!isset($BX_IBLOCK_PROP_CACHE[$IBLOCK_ID]))
			$BX_IBLOCK_PROP_CACHE[$IBLOCK_ID] = array();

		if (!isset($BX_IBLOCK_PROP_CACHE[$IBLOCK_ID][$uniq_flt]))
		{
			$BX_IBLOCK_PROP_CACHE[$IBLOCK_ID][$uniq_flt] = array();

			$db_prop = CIBlockProperty::GetList(array(), $arFilter);
			while($prop = $db_prop->Fetch())
				$BX_IBLOCK_PROP_CACHE[$IBLOCK_ID][$uniq_flt][$prop["ID"]] = $prop;
			unset($prop);
			unset($db_prop);
		}

		$ar_prop = &$BX_IBLOCK_PROP_CACHE[$IBLOCK_ID][$uniq_flt];
		reset($ar_prop);

		$bRecalcSections = false;

		$connection = Main\Application::getConnection();
		$helper = $connection->getSqlHelper();

		//Read current property values from database
		$tableFields = [];
		$arDBProps = array();
		if ((int)CIBlock::GetArrayByID($IBLOCK_ID, 'VERSION') === IblockTable::PROPERTY_STORAGE_SEPARATE)
		{
			$tableFields = $connection->getTableFields('b_iblock_element_prop_s' . $IBLOCK_ID);

			$rs = $connection->query("
				select *
				from b_iblock_element_prop_m".$IBLOCK_ID."
				where IBLOCK_ELEMENT_ID = ".$ELEMENT_ID."
				order by ID asc
			");
			while ($ar = $rs->fetch())
			{
				$propertyId = (int)$ar["IBLOCK_PROPERTY_ID"];
				if (!isset($arDBProps[$propertyId]))
				{
					$arDBProps[$propertyId] = [];
				}

				$arDBProps[$propertyId][$ar["ID"]] = $ar;
			}
			unset($ar);
			unset($rs);

			$rs = $connection->query("
				select *
				from b_iblock_element_prop_s".$IBLOCK_ID."
				where IBLOCK_ELEMENT_ID = ".$ELEMENT_ID."
			");
			$ar = $rs->fetch();
			if ($ar)
			{
				foreach ($ar_prop as $property)
				{
					$propertyId = (int)$property["ID"];
					$valueKey = 'PROPERTY_' . $propertyId;
					$valueIdKey = $ELEMENT_ID .':' . $propertyId;
					if (
						$property["MULTIPLE"] === "N"
						&& isset($ar[$valueKey])
						&& $ar[$valueKey] !== ''
					)
					{
						if (!isset($arDBProps[$propertyId]))
						{
							$arDBProps[$propertyId] = [];
						}

						$arDBProps[$propertyId][$valueIdKey] = [
							'ID' => $valueIdKey,
							'IBLOCK_PROPERTY_ID' => $propertyId,
							'VALUE' => $ar[$valueKey],
							'DESCRIPTION' => $ar['DESCRIPTION_' . $propertyId] ?? '',
						];
					}
				}
				unset($property);
			}
			else
			{
				$connection->queryExecute("
					insert into b_iblock_element_prop_s".$IBLOCK_ID."
					(IBLOCK_ELEMENT_ID) values (".$ELEMENT_ID.")
				");
			}
			unset($ar);
			unset($rs);
		}
		else
		{
			$rs = $connection->query("
				select *
				from b_iblock_element_property
				where IBLOCK_ELEMENT_ID = ".$ELEMENT_ID."
				order by ID asc
			");
			while ($ar = $rs->fetch())
			{
				$propertyId = (int)$ar["IBLOCK_PROPERTY_ID"];
				if (!isset($arDBProps[$propertyId]))
				{
					$arDBProps[$propertyId] = [];
				}

				$arDBProps[$propertyId][$ar["ID"]] = $ar;
			}
			unset($ar);
			unset($rs);
		}

		foreach (GetModuleEvents("iblock", "OnIBlockElementSetPropertyValues", true) as $arEvent)
			ExecuteModuleEventEx($arEvent, array($ELEMENT_ID, $IBLOCK_ID, $PROPERTY_VALUES, $PROPERTY_CODE, $ar_prop, $arDBProps));
		if (isset($arEvent))
			unset($arEvent);

		$arFilesToDelete = array();
		$arV2ClearCache = array();
		foreach ($ar_prop as $prop)
		{
			$propertyId = (int)$prop['ID'];
			$isMultiple = $prop['MULTIPLE'] === 'Y';
			$isSingle = !$isMultiple;
			$separateStorage = (int)$prop['VERSION'] === IblockTable::PROPERTY_STORAGE_SEPARATE;
			$separateSingle = $separateStorage && !$isMultiple;
			$separateMultiple = $separateStorage && $isMultiple;
			$clearSeparateCache = false;
			if ($PROPERTY_CODE)
			{
				$PROP = $PROPERTY_VALUES;
			}
			else
			{
				if ($prop["CODE"] <> '' && array_key_exists($prop["CODE"], $PROPERTY_VALUES))
					$PROP = $PROPERTY_VALUES[$prop["CODE"]];
				else
					$PROP = $PROPERTY_VALUES[$prop["ID"]] ?? [];
			}

			if (
				!is_array($PROP)
				|| (
					$prop['PROPERTY_TYPE'] === Iblock\PropertyTable::TYPE_FILE
					&& (
						array_key_exists("tmp_name", $PROP)
						|| array_key_exists("del", $PROP)
					)
				)
				|| (
					count($PROP) == 2
					&& array_key_exists("VALUE", $PROP)
					&& array_key_exists("DESCRIPTION", $PROP)
				)
			)
			{
				$PROP = array($PROP);
			}

			if ($prop["USER_TYPE"] != "")
			{
				$arUserType = CIBlockProperty::GetUserType($prop["USER_TYPE"]);
				if (isset($arUserType['ConvertToDB']))
				{
					foreach ($PROP as $key => $value)
					{
						if(
							!is_array($value)
							|| !array_key_exists("VALUE", $value)
						)
						{
							$value = array("VALUE"=>$value);
						}
						$prop["ELEMENT_ID"] = $ELEMENT_ID;
						$PROP[$key] = call_user_func_array($arUserType["ConvertToDB"], array($prop, $value));
					}
				}
			}

			if ($separateStorage)
			{
				$strTable =
					$isMultiple
						? 'b_iblock_element_prop_m' . $prop['IBLOCK_ID']
						: 'b_iblock_element_prop_s' . $prop['IBLOCK_ID']
				;
			}
			else
			{
				$strTable = 'b_iblock_element_property';
			}

			if ($prop['PROPERTY_TYPE'] === Iblock\PropertyTable::TYPE_LIST)
			{
				if (isset($arDBProps[$propertyId]) || $separateSingle)
				{
					$connection->queryExecute(CIBlockElement::DeletePropertySQL($prop, $ELEMENT_ID));
				}
				$clearSeparateCache = true;

				$ids = [];
				foreach ($PROP as $value)
				{
					if (is_array($value))
						$value = (int)$value["VALUE"];
					else
						$value = (int)$value;

					if ($value <= 0)
						continue;

					$ids[] = $value;

					if ($isSingle)
					{
						break;
					}
				}

				if (!empty($ids))
				{
					$flatIds = implode(',', $ids);
					if ($separateSingle)
					{
						$connection->queryExecute($helper->prepareCorrelatedUpdate(
							'b_iblock_element_prop_s' . $prop['IBLOCK_ID'],
							'E',
							[
								'PROPERTY_' . $prop['ID'] => 'PEN.ID',
							],
							'b_iblock_property_enum as PEN',
							"
								E.IBLOCK_ELEMENT_ID = " . $ELEMENT_ID."
								AND PEN.PROPERTY_ID = " . $propertyId . "
								AND PEN.ID IN (" . $flatIds . ")
							"
						));
					}
					else
					{
						$connection->queryExecute("
							INSERT INTO " . $strTable . "
							(IBLOCK_ELEMENT_ID, IBLOCK_PROPERTY_ID, VALUE, VALUE_ENUM)
							SELECT " . $ELEMENT_ID . " as IBLOCK_ELEMENT_ID, " . $propertyId . " as IBLOCK_PROPERTY_ID, PEN.ID as VALUE, PEN.ID as VALUE_ENUM
							FROM
								b_iblock_property_enum PEN
							WHERE
								PEN.PROPERTY_ID = " . $propertyId . "
								AND PEN.ID IN (" . $flatIds . ")
						");
					}
					unset($flatIds);
				}
				unset($ids);
			}
			elseif ($prop['PROPERTY_TYPE'] === Iblock\PropertyTable::TYPE_SECTION)
			{
				$bRecalcSections = true;
				if (isset($arDBProps[$propertyId]) || $separateSingle)
				{
					$connection->queryExecute(CIBlockElement::DeletePropertySQL($prop, $ELEMENT_ID));
				}
				$clearSeparateCache = true;

				$connection->queryExecute("
					DELETE FROM b_iblock_section_element
					WHERE ADDITIONAL_PROPERTY_ID = ".$prop["ID"]."
					AND IBLOCK_ELEMENT_ID = ".$ELEMENT_ID."
				");

				$ids = [];
				foreach ($PROP as $value)
				{
					if (is_array($value))
						$value = (int)$value["VALUE"];
					else
						$value = (int)$value;

					if ($value <= 0)
						continue;

					$ids[] = $value;

					if ($isSingle)
					{
						break;
					}
				}

				if (!empty($ids))
				{
					$linkIblockId = (int)$prop['LINK_IBLOCK_ID'];
					$flatIds = implode(',', $ids);
					if ($separateSingle)
					{
						$fields = [
							'PROPERTY_' . $prop['ID'] => 'S.ID',
						];
						$fieldName = 'DESCRIPTION_' . $prop['ID'];
						if (isset($tableFields[$fieldName]))
						{
							$fields[$fieldName] = 'null';
						}
						unset($fieldName);

						$connection->queryExecute($helper->prepareCorrelatedUpdate(
							'b_iblock_element_prop_s' . $prop['IBLOCK_ID'],
							'E',
							$fields,
							'b_iblock_section as S',
							"
								E.IBLOCK_ELEMENT_ID = " . $ELEMENT_ID . "
								AND S.ID IN (". $flatIds . ")"
								. ($linkIblockId > 0 ? 'AND S.IBLOCK_ID = ' . $linkIblockId : '') . "
							"
						));
						unset($fields);
					}
					else
					{
						$connection->queryExecute("
							INSERT INTO " . $strTable . "
							(IBLOCK_ELEMENT_ID, IBLOCK_PROPERTY_ID, VALUE, VALUE_NUM)
							SELECT " . $ELEMENT_ID  .", " . $propertyId . ", S.ID, S.ID
							FROM
								b_iblock_section S
							WHERE
								S.ID IN (" . $flatIds . ")"
								. ($linkIblockId > 0 ? 'AND S.IBLOCK_ID = ' . $linkIblockId : '') . "
						");
					}

					$connection->queryExecute("
						INSERT INTO b_iblock_section_element
						(IBLOCK_ELEMENT_ID, IBLOCK_SECTION_ID, ADDITIONAL_PROPERTY_ID)
						SELECT " . $ELEMENT_ID . ", S.ID, " . $propertyId . "
						FROM
							b_iblock_section S
						WHERE
							S.ID IN (" . $flatIds . ")"
							. ($linkIblockId > 0 ? 'AND S.IBLOCK_ID = ' . $linkIblockId : '') . "
					");
					unset(
						$flatIds,
						$linkIblockId,
					);
				}
				unset($ids);
			}
			elseif ($prop['PROPERTY_TYPE'] === Iblock\PropertyTable::TYPE_ELEMENT)
			{
				$arWas = array();
				if (isset($arDBProps[$prop["ID"]]))
				{
					foreach($arDBProps[$prop["ID"]] as $res)
					{
						$val = $PROP[$res["ID"]] ?? null;
						if (is_array($val))
						{
							$val_desc = $val["DESCRIPTION"] ?? '';
							$val = $val["VALUE"];
						}
						else
						{
							$val_desc = false;
						}

						if (isset($arWas[$val]))
							$val = "";
						else
							$arWas[$val] = true;

						if ((string)$val === '') //Delete property value
						{
							if ($separateSingle)
							{
								$connection->queryExecute("
									UPDATE b_iblock_element_prop_s".$prop["IBLOCK_ID"]."
									SET PROPERTY_".$prop["ID"]." = null
									".self::__GetDescriptionUpdateSql($prop["IBLOCK_ID"], $prop["ID"])."
									WHERE IBLOCK_ELEMENT_ID = ".$ELEMENT_ID."
								");
							}
							else
							{
								$connection->queryExecute("
									DELETE FROM ".$strTable."
									WHERE ID=".$res["ID"]."
								");
							}

							$clearSeparateCache = true;
						}
						elseif (
							$res["VALUE"] !== $val
							|| $res["DESCRIPTION"].'' !== $val_desc.''
						) //Update property value
						{
							if ($separateSingle)
							{
								$connection->queryExecute("
									UPDATE b_iblock_element_prop_s".$prop["IBLOCK_ID"]."
									SET PROPERTY_".$prop["ID"]." = '".$helper->forSql($val)."'
									".self::__GetDescriptionUpdateSql($prop["IBLOCK_ID"], $prop["ID"], $val_desc)."
									WHERE IBLOCK_ELEMENT_ID = ".$ELEMENT_ID."
								");
							}
							else
							{
								$connection->queryExecute("
									UPDATE ".$strTable."
									SET VALUE = '".$helper->forSql($val)."'
										,VALUE_NUM = ".CIBlock::roundDB($val)."
										".($val_desc!==false ? ",DESCRIPTION = '".$helper->forSql($val_desc, 255)."'" : "")."
									WHERE ID=".$res["ID"]."
								");
							}

							$clearSeparateCache = true;
						}

						unset($PROP[$res["ID"]]);
					} //foreach($arDBProps[$prop["ID"]] as $res)
				}

				$insertValues = [];
				foreach ($PROP as $val)
				{
					if (is_array($val))
					{
						$val_desc = $val["DESCRIPTION"] ?? '';
						$val = $val["VALUE"];
					}
					else
					{
						$val_desc = false;
					}

					if (isset($arWas[$val]))
						$val = "";
					else
						$arWas[$val] = true;

					if ((string)$val === '')
					{
						continue;
					}

					if ($separateSingle)
					{
						$connection->queryExecute("
							UPDATE b_iblock_element_prop_s".$prop["IBLOCK_ID"]."
							SET
								PROPERTY_".$prop["ID"]." = '".$helper->forSql($val)."'
								".self::__GetDescriptionUpdateSql($prop["IBLOCK_ID"], $prop["ID"], $val_desc)."
							WHERE IBLOCK_ELEMENT_ID=".$ELEMENT_ID."
						");
					}
					else
					{
						$insertValues[] =
							'('
							. $ELEMENT_ID . ', '
							. $propertyId . ', '
							. "'" . $helper->forSql((string)$val) . "', "
							. CIBlock::roundDB($val) . ', '
							. ($val_desc !== false ? "'" . $helper->forSql((string)$val_desc, 255) . "'" : 'NULL')
							. ')'
						;
					}

					$clearSeparateCache = true;

					if ($isSingle)
					{
						break;
					}
				} //foreach($PROP as $value)
				if (!empty($insertValues))
				{
					$connection->queryExecute("
						INSERT INTO " . $strTable . "
						(IBLOCK_ELEMENT_ID, IBLOCK_PROPERTY_ID, VALUE, VALUE_NUM, DESCRIPTION)
						VALUES
						" . implode(', ', $insertValues)
					);
				}
				unset(
					$insertValues,
				);
			}
			elseif ($prop['PROPERTY_TYPE'] === Iblock\PropertyTable::TYPE_FILE)
			{
				//We'll be adding values from the database into the head
				//for multiple values and into tje tail for single
				//these values were not passed into API call.
				$orderedPROP =
					$isMultiple
						? array_reverse($PROP, true)
						: $PROP
				;

				if (isset($arDBProps[$prop["ID"]]))
				{
					//Go from high ID to low
					foreach (array_reverse($arDBProps[$prop["ID"]], true) as $res)
					{
						//Preserve description from database
						if($res["DESCRIPTION"] <> '')
						{
							$description = $res["DESCRIPTION"];
						}
						else
						{
							$description = false;
						}

						if (!array_key_exists($res["ID"], $orderedPROP))
						{
							$orderedPROP[$res["ID"]] = array(
								"VALUE" => $res["VALUE"],
								"DESCRIPTION" => $description,
							);
						}
						else
						{
							$val = $orderedPROP[$res["ID"]];
							if (
								is_array($val)
								&& !array_key_exists("tmp_name", $val)
								&& !array_key_exists("del", $val)
							)
								$val = $val["VALUE"];

							//Check if no new file and no delete command
							if (
								(!isset($val["tmp_name"]) || $val["tmp_name"] === '')
								&& (!isset($val["del"]) || $val["del"] === '')
							) //Overwrite with database value
							{
								//But save description from incoming value
								if (is_array($val) && array_key_exists("description", $val))
								{
									$description = trim((string)$val["description"]);
								}
								elseif (
									is_array($orderedPROP[$res["ID"]])
									&& array_key_exists("DESCRIPTION", $orderedPROP[$res["ID"]])
								)
								{
									$description = trim((string)$orderedPROP[$res["ID"]]["DESCRIPTION"]);
								}

								$orderedPROP[$res["ID"]] = array(
									"VALUE" => $res["VALUE"],
									"DESCRIPTION" => $description,
								);
							}
						}
					}
				}

				//Restore original order
				if ($isMultiple)
				{
					$orderedPROP = array_reverse($orderedPROP, true);
				}

				$preserveID = array();
				//Now delete from database all marked for deletion  records
				if (isset($arDBProps[$prop["ID"]]))
				{
					foreach ($arDBProps[$prop["ID"]] as $res)
					{
						$val = $orderedPROP[$res["ID"]] ?? null;
						if (
							is_array($val)
							&& !array_key_exists("tmp_name", $val)
							&& !array_key_exists("del", $val)
						)
						{
							$val = $val["VALUE"];
						}

						if (
							is_array($val)
							&& (string)($val['del'] ?? '') !== ''
						)
						{
							unset($orderedPROP[$res["ID"]]);
							$arFilesToDelete[$res["VALUE"]] = array(
								"FILE_ID" => $res["VALUE"],
								"ELEMENT_ID" => $ELEMENT_ID,
								"IBLOCK_ID" => $prop["IBLOCK_ID"],
							);
						}
						elseif (
							$isSingle
							|| (
								is_array($val) && isset($val["tmp_name"]) && $val["tmp_name"] != ''
							)
						)
						{
							//Delete all stored in database for replacement.
							$arFilesToDelete[$res["VALUE"]] = array(
								"FILE_ID" => $res["VALUE"],
								"ELEMENT_ID" => $ELEMENT_ID,
								"IBLOCK_ID" => $prop["IBLOCK_ID"],
							);
						}

						if ($separateSingle)
						{
							$connection->queryExecute("
								UPDATE b_iblock_element_prop_s".$prop["IBLOCK_ID"]."
								SET PROPERTY_".$prop["ID"]." = null
								".self::__GetDescriptionUpdateSql($prop["IBLOCK_ID"], $prop["ID"])."
								WHERE IBLOCK_ELEMENT_ID = ".$ELEMENT_ID."
							");
						}
						else
						{
							$connection->queryExecute("DELETE FROM ".$strTable." WHERE ID = ".$res["ID"]);
							$preserveID[$res["ID"]] = $res["ID"];
						}

						$clearSeparateCache = true;
					} //foreach($arDBProps[$prop["ID"]] as $res)
				}

				//Write new values into database in specified order
				foreach ($orderedPROP as $propertyValueId => $val)
				{
					if(
						is_array($val)
						&& !array_key_exists("tmp_name", $val)
					)
					{
						$val_desc = $val["DESCRIPTION"] ?? '';
						$val = $val["VALUE"];
					}
					else
					{
						$val_desc = false;
					}

					if (is_array($val))
					{
						$val["MODULE_ID"] = "iblock";
						if ($val_desc !== false)
							$val["description"] = $val_desc;

						$val = CFile::SaveFile($val, "iblock");
					}
					elseif (
						$val > 0
						&& $val_desc !== false
					)
					{
						CFile::UpdateDesc($val, $val_desc);
					}

					if ((int)$val <= 0)
						continue;

					if ($separateSingle)
					{
						$connection->queryExecute("
							UPDATE b_iblock_element_prop_s".$prop["IBLOCK_ID"]."
							SET
								PROPERTY_".$prop["ID"]." = '".$helper->forSql($val)."'
								".self::__GetDescriptionUpdateSql($prop["IBLOCK_ID"], $prop["ID"], $val_desc)."
							WHERE IBLOCK_ELEMENT_ID=".$ELEMENT_ID."
						");
					}
					elseif ($preserveID)
					{
						$connection->queryExecute("
							INSERT INTO " . $strTable . "
							(ID, IBLOCK_ELEMENT_ID, IBLOCK_PROPERTY_ID, VALUE, VALUE_NUM" . ($val_desc !== false ? ", DESCRIPTION" : "") . ")
							VALUES (
								" . array_shift($preserveID) . "
								, " . $ELEMENT_ID . "
								, " . $propertyId . "
								,'" . $helper->forSql((string)$val) . "'
								," . CIBlock::roundDB($val) . "
								" . ($val_desc !== false ? ", '" . $helper->forSql((string)$val_desc, 255) . "'" : "") . "
							)
						");
					}
					else
					{
						$connection->queryExecute("
							INSERT INTO " . $strTable . "
							(IBLOCK_ELEMENT_ID, IBLOCK_PROPERTY_ID, VALUE, VALUE_NUM" . ($val_desc !== false ? ", DESCRIPTION" : "") . ")
							VALUES (
								" . $ELEMENT_ID . "
								, " . $propertyId . "
								, '" . $helper->forSql((string)$val) . "'
								, " . CIBlock::roundDB($val) ."
								" . ($val_desc !== false ? ", '" . $helper->forSql((string)$val_desc, 255) . "'" : "") . "
							)
						");
					}

					$clearSeparateCache = true;

					if ($isSingle)
					{
						break;
					}
				} //foreach($PROP as $value)
			}
			else //if($prop["PROPERTY_TYPE"] == "S" || $prop["PROPERTY_TYPE"] == "N")
			{
				$isNumberProperty = $prop['PROPERTY_TYPE'] === Iblock\PropertyTable::TYPE_NUMBER;
				if (isset($arDBProps[$prop["ID"]]))
				{
					foreach ($arDBProps[$prop["ID"]] as $res)
					{
						$val = $PROP[$res["ID"]] ?? null;
						if (is_array($val))
						{
							$val_desc = $val["DESCRIPTION"] ?? '';
							$val = $val["VALUE"];
						}
						else
						{
							$val_desc = false;
						}

						if ((string)$val === '')
						{
							if ($separateSingle)
							{
								$connection->queryExecute("
									UPDATE b_iblock_element_prop_s".$prop["IBLOCK_ID"]."
									SET
										PROPERTY_".$prop["ID"]." = null
										".self::__GetDescriptionUpdateSql($prop["IBLOCK_ID"], $prop["ID"])."
									WHERE IBLOCK_ELEMENT_ID=".$ELEMENT_ID."
								");
							}
							else
							{
								$connection->queryExecute("DELETE FROM ".$strTable." WHERE ID=".$res["ID"]);
							}

							$clearSeparateCache = true;
						}
						else
						{
							if ($separateSingle)
							{
								if ($isNumberProperty)
								{
									$val = CIBlock::roundDB($val);
								}

								$connection->queryExecute("
									UPDATE b_iblock_element_prop_s".$prop["IBLOCK_ID"]."
									SET PROPERTY_".$prop["ID"]."='".$helper->forSql($val)."'
									".self::__GetDescriptionUpdateSql($prop["IBLOCK_ID"], $prop["ID"], $val_desc)."
									WHERE IBLOCK_ELEMENT_ID=".$ELEMENT_ID."
								");
							}
							else
							{
								$connection->queryExecute("
									UPDATE ".$strTable."
									SET
										VALUE='".$helper->forSql($val)."'
										,VALUE_NUM=".CIBlock::roundDB($val)."
										".($val_desc!==false ? ",DESCRIPTION='".$helper->forSql($val_desc, 255)."'" : "")."
									WHERE ID=".$res["ID"]."
								");
							}

							$clearSeparateCache = true;
						}
						unset($PROP[$res["ID"]]);
					} //foreach ($arDBProps[$prop["ID"]] as $res)
				}

				$insertValues = [];
				foreach($PROP as $val)
				{
					if (is_array($val) && !array_key_exists('tmp_name', $val))
					{
						$val_desc = $val["DESCRIPTION"] ?? '';
						$val = $val["VALUE"];
					}
					else
					{
						$val_desc = false;
					}

					if (!is_scalar($val) || (string)$val === '')
					{
						continue;
					}

					if ($separateSingle)
					{
						if ($isNumberProperty)
						{
							$val = CIBlock::roundDB($val);
						}

						$connection->queryExecute("
							UPDATE b_iblock_element_prop_s".$prop["IBLOCK_ID"]."
							SET
								PROPERTY_".$prop["ID"]." = '".$helper->forSql($val)."'
								".self::__GetDescriptionUpdateSql($prop["IBLOCK_ID"], $prop["ID"], $val_desc)."
							WHERE IBLOCK_ELEMENT_ID=".$ELEMENT_ID."
						");
					}
					else
					{
						$insertValues[] =
							'('
							. $ELEMENT_ID . ', '
							. $propertyId . ', '
							. "'" . $helper->forSql((string)$val) . "', "
							. CIBlock::roundDB($val) . ', '
							. ($val_desc !== false ? "'" . $helper->forSql((string)$val_desc, 255) . "'" : 'NULL')
							. ')'
						;
					}

					$clearSeparateCache = true;

					if ($isSingle)
					{
						break;
					}
				} //foreach($PROP as $value)
				if (!empty($insertValues))
				{
					$connection->queryExecute("
						INSERT INTO " . $strTable . "
						(IBLOCK_ELEMENT_ID, IBLOCK_PROPERTY_ID, VALUE, VALUE_NUM, DESCRIPTION)
						VALUES
						" . implode(', ', $insertValues)
					);
				}
				unset(
					$insertValues,
				);
			} //if($prop["PROPERTY_TYPE"] == "S" || $prop["PROPERTY_TYPE"] == "N")

			if ($clearSeparateCache && $separateMultiple)
			{
				$arV2ClearCache[$propertyId] =
					'PROPERTY_' . $propertyId . ' = NULL'
					. self::__GetDescriptionUpdateSql($prop["IBLOCK_ID"], $propertyId)
				;
			}
			unset(
				$separateMultiple,
				$separateSingle,
				$separateStorage,
				$isMultiple,
				$propertyId,
			);
		}

		unset($tableFields);

		if ($arV2ClearCache)
		{
			$connection->queryExecute("
				UPDATE b_iblock_element_prop_s".$IBLOCK_ID."
				SET ".implode(",", $arV2ClearCache)."
				WHERE IBLOCK_ELEMENT_ID = ".$ELEMENT_ID."
			");
		}

		unset(
			$helper,
			$connection,
		);

		foreach ($arFilesToDelete as $deleteTask)
		{
			CIBlockElement::DeleteFile(
				$deleteTask["FILE_ID"],
				false,
				"PROPERTY", $deleteTask["ELEMENT_ID"],
				$deleteTask["IBLOCK_ID"]
			);
		}

		if($bRecalcSections)
			CIBlockElement::RecalcSections($ELEMENT_ID);

		/****************************** QUOTA ******************************/
		CDiskQuota::recalculateDb();
		/****************************** QUOTA ******************************/

		foreach (GetModuleEvents("iblock", "OnAfterIBlockElementSetPropertyValues", true) as $arEvent)
			ExecuteModuleEventEx($arEvent, array($ELEMENT_ID, $IBLOCK_ID, $PROPERTY_VALUES, $PROPERTY_CODE));
	}

	public static function GetRandFunction()
	{
		$connection = Main\Application::getConnection();
		$helper = $connection->getSqlHelper();

		return ' ' . $helper->getRandomFunction() . ' ';
	}

	public static function GetShowedFunction()
	{
		return " coalesce(BE.SHOW_COUNTER/((UNIX_TIMESTAMP(now())-UNIX_TIMESTAMP(BE.SHOW_COUNTER_START)+0.1)/60/60),0) ";
	}

	///////////////////////////////////////////////////////////////////
	// Update list of elements w/o any events
	///////////////////////////////////////////////////////////////////
	protected function UpdateList($arFields, $arFilter = array())
	{
		global $DB;

		$strUpdate = $DB->PrepareUpdate("b_iblock_element", $arFields, "iblock", false, "BE");
		if ($strUpdate == "")
			return false;

		$element = new CIBlockElement;
		$element->strField = "ID";
		$element->GetList(array(), $arFilter, false, false, array("ID"));

		$strSql = "
			UPDATE ".$element->sFrom." SET ".$strUpdate."
			WHERE 1=1 ".$element->sWhere."
		";

		return $DB->Query($strSql);
	}

	private static function getUserNameSql(string $tableAlias): string
	{
		$connection = Main\Application::getConnection();
		$helper = $connection->getSqlHelper();

		return $helper->getConcatFunction(
			"'('",
			$tableAlias . '.LOGIN',
			"') '",
			$helper->getIsNullFunction($tableAlias . '.NAME', "''"),
			"' '",
			$helper->getIsNullFunction($tableAlias . '.LAST_NAME', "''")
		);
	}
}
