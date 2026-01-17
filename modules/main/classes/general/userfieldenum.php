<?php

use Bitrix\Main\Security\Random;

IncludeModuleLangFile(__FILE__);

class CUserFieldEnum
{
	public function SetEnumValues($FIELD_ID, $values)
	{
		global $DB, $CACHE_MANAGER, $APPLICATION;
		$aMsg = [];
		$originalValues = $values;

		foreach ($values as $i => $row)
		{
			foreach ($row as $key => $val)
			{
				if (str_starts_with($key, "~"))
				{
					unset($values[$i][$key]);
				}
			}
		}

		// check unique XML_ID
		$arAdded = [];
		$salt = Random::getString(8);
		foreach ($values as $key => $value)
		{
			if (str_starts_with($key, "n") && (!isset($value["DEL"]) || $value["DEL"] != "Y") && (string)$value["VALUE"] <> '')
			{
				if (empty($value["XML_ID"]))
				{
					$values[$key]["XML_ID"] = $value["XML_ID"] = md5($salt . $value["VALUE"]);
				}

				if (isset($arAdded[$value["XML_ID"]]))
				{
					$aMsg[] = ["text" => GetMessage("USER_TYPE_XML_ID_UNIQ", ["#XML_ID#" => $value["XML_ID"]])];
				}
				else
				{
					$rsEnum = static::GetList([], ["USER_FIELD_ID" => $FIELD_ID, "XML_ID" => $value["XML_ID"]], false);
					if ($rsEnum->Fetch())
					{
						$aMsg[] = ["text" => GetMessage("USER_TYPE_XML_ID_UNIQ", ["#XML_ID#" => $value["XML_ID"]])];
					}
					else
					{
						$arAdded[$value["XML_ID"]] = 1;
					}
				}
			}
		}

		$previousValues = [];

		$rsEnum = static::GetList([], ["USER_FIELD_ID" => $FIELD_ID]);
		while ($arEnum = $rsEnum->Fetch())
		{
			$previousValues[$arEnum["ID"]] = $arEnum;

			if (array_key_exists($arEnum["ID"], $values))
			{
				$value = $values[$arEnum["ID"]];
				if ((string)($value['VALUE'] ?? '') === '' || (($value['DEL'] ?? 'N') === 'Y'))
				{
					continue;
				}
				if (
					$arEnum["VALUE"] != $value["VALUE"] ||
					$arEnum["DEF"] != $value["DEF"] ||
					$arEnum["SORT"] != $value["SORT"] ||
					$arEnum["XML_ID"] !== $value["XML_ID"]
				)
				{
					if (empty($value["XML_ID"]))
					{
						$value["XML_ID"] = md5($value["VALUE"]);
					}

					$bUnique = true;
					if ($arEnum["XML_ID"] !== $value["XML_ID"])
					{
						if (isset($arAdded[$value["XML_ID"]]))
						{
							$aMsg[] = ["text" => GetMessage("USER_TYPE_XML_ID_UNIQ", ["#XML_ID#" => $value["XML_ID"]])];
							$bUnique = false;
						}
						else
						{
							$rsEnumXmlId = static::GetList([], ["USER_FIELD_ID" => $FIELD_ID, "XML_ID" => $value["XML_ID"]], false);
							if ($rsEnumXmlId->Fetch())
							{
								$aMsg[] = ["text" => GetMessage("USER_TYPE_XML_ID_UNIQ", ["#XML_ID#" => $value["XML_ID"]])];
								$bUnique = false;
							}
						}
					}

					if ($bUnique)
					{
						$arAdded[$value['XML_ID']] = 1;
					}
				}
			}
		}

		if (!empty($aMsg))
		{
			$e = new CAdminException($aMsg);
			$APPLICATION->ThrowException($e);
			return false;
		}

		foreach ($values as $key => $value)
		{
			if (str_starts_with($key, "n") && (!isset($value["DEL"]) || $value["DEL"] != "Y") && (string)$value["VALUE"] <> '')
			{
				if (!isset($value["DEF"]) || $value['DEF'] !== 'Y')
				{
					$value['DEF'] = 'N';
				}

				$value["USER_FIELD_ID"] = $FIELD_ID;

				$id = $DB->Add("b_user_field_enum", $value, [], '', true);

				$originalValues[$id] = $originalValues[$key];
				unset($originalValues[$key], $values[$key]);
			}
		}

		$rsEnum = static::GetList([], ["USER_FIELD_ID" => $FIELD_ID]);
		while ($arEnum = $rsEnum->Fetch())
		{
			if (array_key_exists($arEnum["ID"], $values))
			{
				$value = $values[$arEnum["ID"]];
				if ((string)($value['VALUE'] ?? '') === '' || (($value['DEL'] ?? 'N') === 'Y'))
				{
					$DB->Query("DELETE FROM b_user_field_enum WHERE ID = " . $arEnum["ID"]);
				}
				elseif ($arEnum["VALUE"] != $value["VALUE"] ||
					$arEnum["DEF"] != $value["DEF"] ||
					$arEnum["SORT"] != $value["SORT"] ||
					$arEnum["XML_ID"] !== $value["XML_ID"])
				{
					if (empty($value["XML_ID"]))
					{
						$value["XML_ID"] = md5($value["VALUE"]);
					}

					unset($value["ID"]);
					$strUpdate = $DB->PrepareUpdate("b_user_field_enum", $value);
					if ($strUpdate <> '')
					{
						$DB->Query("UPDATE b_user_field_enum SET " . $strUpdate . " WHERE ID = " . $arEnum["ID"]);
					}
				}
			}
		}

		if (CACHED_b_user_field_enum !== false)
		{
			$CACHE_MANAGER->CleanDir("b_user_field_enum");
		}

		$event = new \Bitrix\Main\Event('main', 'onAfterSetEnumValues', [$FIELD_ID, $originalValues, $previousValues]);
		$event->send();

		return true;
	}

	public static function GetList($aSort = [], $aFilter = [], bool $useCache = true)
	{
		global $DB, $CACHE_MANAGER;

		if (CACHED_b_user_field_enum !== false && $useCache)
		{
			$cacheId = "b_user_field_enum" . md5(serialize($aSort) . "." . serialize($aFilter));
			if ($CACHE_MANAGER->Read(CACHED_b_user_field_enum, $cacheId, "b_user_field_enum"))
			{
				$arResult = $CACHE_MANAGER->Get($cacheId);
				$res = new CDBResult;
				$res->InitFromArray($arResult);
				return $res;
			}
		}
		else
		{
			$cacheId = '';
		}

		$bJoinUFTable = false;
		$arFilter = [];
		foreach ($aFilter as $key => $val)
		{
			if (is_array($val))
			{
				if (empty($val))
				{
					continue;
				}
				$val = array_map([$DB, "ForSQL"], $val);
				$val = "('" . implode("', '", $val) . "')";
			}
			else
			{
				if ((string)$val == '')
				{
					continue;
				}
				$val = "('" . $DB->ForSql($val) . "')";
			}

			$key = strtoupper($key);
			switch ($key)
			{
				case "ID":
				case "USER_FIELD_ID":
				case "VALUE":
				case "DEF":
				case "SORT":
				case "XML_ID":
					$arFilter[] = "UFE." . $key . " in " . $val;
					break;
				case "USER_FIELD_NAME":
					$bJoinUFTable = true;
					$arFilter[] = "UF.FIELD_NAME in " . $val;
					break;
			}
		}

		$arOrder = [];
		foreach ($aSort as $key => $val)
		{
			$key = strtoupper($key);
			$ord = (strtoupper($val) <> "ASC" ? "DESC" : "ASC");
			switch ($key)
			{
				case "ID":
				case "USER_FIELD_ID":
				case "VALUE":
				case "DEF":
				case "SORT":
				case "XML_ID":
					$arOrder[] = "UFE." . $key . " " . $ord;
					break;
			}
		}
		if (empty($arOrder))
		{
			$arOrder[] = "UFE.SORT asc";
			$arOrder[] = "UFE.ID asc";
		}
		DelDuplicateSort($arOrder);
		$sOrder = "\nORDER BY " . implode(", ", $arOrder);

		if (empty($arFilter))
		{
			$sFilter = "";
		}
		else
		{
			$sFilter = "\nWHERE " . implode("\nAND ", $arFilter);
		}

		$strSql = "
			SELECT
				UFE.ID
				,UFE.USER_FIELD_ID
				,UFE.VALUE
				,UFE.DEF
				,UFE.SORT
				,UFE.XML_ID
			FROM
				b_user_field_enum UFE
				" . ($bJoinUFTable ? "INNER JOIN b_user_field UF ON UF.ID = UFE.USER_FIELD_ID" : "") . "
			" . $sFilter . $sOrder;

		if ($cacheId == '')
		{
			$res = $DB->Query($strSql);
		}
		else
		{
			$arResult = [];
			$res = $DB->Query($strSql);
			while ($ar = $res->Fetch())
			{
				$arResult[] = $ar;
			}

			$CACHE_MANAGER->Set($cacheId, $arResult);

			$res = new CDBResult;
			$res->InitFromArray($arResult);
		}

		return $res;
	}

	public function DeleteFieldEnum($FIELD_ID)
	{
		global $DB, $CACHE_MANAGER;

		$DB->Query("DELETE FROM b_user_field_enum WHERE USER_FIELD_ID = " . intval($FIELD_ID));

		if (CACHED_b_user_field_enum !== false)
		{
			$CACHE_MANAGER->CleanDir("b_user_field_enum");
		}
	}
}
