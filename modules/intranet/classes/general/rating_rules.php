<?php

IncludeModuleLangFile($_SERVER["DOCUMENT_ROOT"].BX_ROOT."/modules/intranet/classes/general/rating_rules.php");

class CRatingRulesIntranet
{
	// return configs
	public static function OnGetRatingRuleConfigs()
	{
		$arConfigs["USER"]["CONDITION_CONFIG"][] = array(
		   "ID"	=> 'SUBORDINATE',
			"NAME" => GetMessage('PP_USER_CONDITION_SUBORDINATE_NAME'),
			"DESC" => '',
			"REFRESH_TIME"	=> '86400',
			"MODULE"	=> 'intranet',
			"CLASS"	=> 'CRatingRulesIntranet',
			"METHOD"	=> 'subordinateCheck',
		   "FIELDS" => array(
				array(
					"TYPE" => 'TEXT',
					"NAME" => GetMessage('PP_USER_CONDITION_SUBORDINATE_TEXT')
				),
				/*array(
					"TYPE" => 'INPUT',
					"ID" => 'MAX_VOTES',
					"NAME" => GetMessage('PP_USER_CONDITION_SUBORDINATE_T0'),
					"DEFAULT" => '1000',
					"SIZE" => '3'
				),
				array(
					"TYPE" => 'SELECT_ARRAY',
					"ID" => 'TYPE',
					"NAME" => GetMessage('PP_USER_CONDITION_SUBORDINATE_T1'),
					"DEFAULT" => '50',
					"PARAMS" => array('50' => GetMessage('PP_USER_CONDITION_SUBORDINATE_T2'),
											'75' => GetMessage('PP_USER_CONDITION_SUBORDINATE_T3'),
											'100' => GetMessage('PP_USER_CONDITION_SUBORDINATE_T4')),
				),*/
			),
			'HIDE_ACTION' => true
		);
		return $arConfigs;
	}

	public static function subordinateCheck($arConfigs)
	{
		global $DB, $USER_FIELD_MANAGER;

		$ratingId = CRatings::GetAuthorityRating();
		if ($ratingId == 0)
			return true;

		$maxVotes = $arConfigs['CONDITION_CONFIG']['SUBORDINATE']['MAX_VOTES'];
		$type = $arConfigs['CONDITION_CONFIG']['SUBORDINATE']['TYPE'];

		$iblockId = COption::GetOptionInt('intranet', 'iblock_structure', 0);
		$fieldId = 0;
		$arUserFields = $USER_FIELD_MANAGER->GetUserFields("USER");
		if (isset($arUserFields["UF_DEPARTMENT"]["ID"]))
			$fieldId = intval($arUserFields["UF_DEPARTMENT"]["ID"]);

		if ($iblockId > 0 && $fieldId > 0)
		{
			// truncate table first
			$DB->Query("TRUNCATE TABLE b_rating_subordinate");

			$squery = "
				INSERT INTO b_rating_subordinate (RATING_ID, ENTITY_ID, VOTES)
				SELECT '".intval($ratingId)."' RATING_ID, U2U.USER_ID ENTITY_ID, (case when U2U.ID > 0 then SUM(".$DB->IsNull("RU.BONUS", "RUS.BONUS").") else RUS.BONUS end) VOTES
				FROM
				(
					SELECT DISTINCT U.ID USER_ID, UP.VALUE_ID SUBORDINATE_ID, UD.ID
					FROM b_user U
						LEFT JOIN b_utm_user UD ON UD.VALUE_ID = U.ID AND UD.FIELD_ID = ".$fieldId."
						LEFT JOIN b_uts_iblock_".$iblockId."_section BSSV on BSSV.UF_HEAD = U.ID
						LEFT JOIN b_iblock_section BS ON BS.ID = BSSV.VALUE_ID
						LEFT JOIN b_iblock_section BsubS on BsubS.IBLOCK_ID = BS.IBLOCK_ID AND BsubS.LEFT_MARGIN >= BS.LEFT_MARGIN AND BsubS.RIGHT_MARGIN <= BS.RIGHT_MARGIN
						LEFT JOIN b_uts_iblock_".$iblockId."_section NACH_PODOTD on NACH_PODOTD.VALUE_ID = BsubS.ID
						LEFT JOIN b_utm_user UP on (UP.VALUE_INT = BsubS.ID) OR (UP.VALUE_ID = NACH_PODOTD.UF_HEAD) AND UP.FIELD_ID = ".$fieldId."
						LEFT JOIN b_user U2 on U2.ID = UP.VALUE_ID
					WHERE 
						U.ACTIVE = 'Y'
						AND (U.EXTERNAL_AUTH_ID NOT IN ('".implode("','", \Bitrix\Main\UserTable::getExternalUserTypes())."') OR U.EXTERNAL_AUTH_ID IS NULL)
						AND (U2.ACTIVE = 'Y' OR U2.ID IS NULL) 
						AND UD.ID > 0
				) U2U
				LEFT JOIN b_rating_user RU on RU.RATING_ID = ".intval($ratingId)." and RU.ENTITY_ID = U2U.SUBORDINATE_ID
				LEFT JOIN b_rating_user RUS on RUS.RATING_ID = ".intval($ratingId)." and RUS.ENTITY_ID = U2U.USER_ID
				GROUP BY U2U.USER_ID, U2U.ID, RUS.BONUS";
			$DB->Query($squery);
		}

		return true;
	}

	public static function RecalcSubordinateRuleAgent()
	{
		$ratingId = (int)CRatings::GetAuthorityRating();
		if ($ratingId)
		{
			global $DB;
			$DB->Query("DELETE FROM b_rating_results WHERE RATING_ID = $ratingId");

			$res = $DB->Query("
				SELECT ID
				FROM b_rating_rule
				WHERE
					ENTITY_TYPE_ID = 'USER'
					AND CONDITION_NAME = 'SUBORDINATE'
					AND CONDITION_MODULE = 'intranet'
			");
			if ($rule = $res->Fetch());
			{
				CRatingRule::Apply($rule['ID'], true);
			}

			CRatings::Calculate($ratingId, true);
		}

		return "";
	}

	// check input values, if value does not validate, set the default value
	public static function __CheckFields($entityId, $arConfigs)
	{
		$arDefaultConfig = CRatingRulesIntranet::__AssembleConfigDefault($entityId);

		if ($entityId == "USER")
		{
		}
		return $arConfigs;
	}

	// assemble config default value
	public static function __AssembleConfigDefault($objectType = null)
	{
		$arConfigs = array();
		$arRatingRuleConfigs = CRatingRulesIntranet::OnGetRatingRuleConfigs();
		if (is_null($objectType))
		{
			foreach ($arRatingRuleConfigs as $OBJ_TYPE => $TYPE_VALUE)
				foreach ($TYPE_VALUE as $RULE_TYPE => $RULE_VALUE)
					foreach ($RULE_VALUE as $VALUE_CONFIG)
				   		foreach ($VALUE_CONFIG['FIELDS'] as $VALUE_FIELDS)
							{
								$arConfigs[$OBJ_TYPE][$RULE_TYPE][$VALUE_CONFIG['ID']][$VALUE_FIELDS['ID']]['DEFAULT'] = $VALUE_FIELDS['DEFAULT'];
								if (isset($arConfigs[$OBJ_TYPE][$RULE_TYPE][$VALUE_CONFIG['ID']][$VALUE_FIELDS['ID']]['DEFAULT_INPUT']))
									$arConfigs[$OBJ_TYPE][$RULE_TYPE][$VALUE_CONFIG['ID']][$VALUE_FIELDS['ID']]['DEFAULT_INPUT'] = $VALUE_FIELDS['DEFAULT_INPUT'];
							 }
		}
		else
		{
			foreach ($arRatingRuleConfigs[$objectType] as $RULE_TYPE => $RULE_VALUE)
				foreach ($RULE_VALUE as $VALUE_CONFIG)
					foreach ($VALUE_CONFIG['FIELDS'] as $VALUE_FIELDS)
					{
				   		$arConfigs[$RULE_TYPE][$VALUE_CONFIG['ID']][$VALUE_FIELDS['ID']]['DEFAULT'] = $VALUE_FIELDS['DEFAULT'];
						if (isset($arConfigs[$RULE_TYPE][$VALUE_CONFIG['ID']][$VALUE_FIELDS['ID']]['DEFAULT_INPUT']))
							$arConfigs[$RULE_TYPE][$VALUE_CONFIG['ID']][$VALUE_FIELDS['ID']]['DEFAULT_INPUT'] = $VALUE_FIELDS['DEFAULT_INPUT'];
					}
		}
		return $arConfigs;
	}

	// return support object
	public static function OnGetRatingRuleObjects()
	{
		$arRatingRulesConfigs = CRatingRulesIntranet::OnGetRatingRuleConfigs();
		foreach ($arRatingRulesConfigs as $SupportType => $value)
			$arSupportType[] = $SupportType;

		return $arSupportType;
	}

	// check the value which relate to the module
	public static function OnAfterAddRatingRule($ID, $arFields)
	{
		$arFields = CRatingRulesIntranet::__CheckFields($arFields['ENTITY_TYPE_ID'], $arFields);

		return $arFields;
	}

	// check the value which relate to the module
	public static function OnAfterUpdateRatingRule($ID, $arFields)
	{
		$arFields = CRatingRulesIntranet::__CheckFields($arFields['ENTITY_TYPE_ID'], $arFields);

		return $arFields;
	}
}
