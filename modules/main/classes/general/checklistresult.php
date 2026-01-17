<?php

/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage main
 * @copyright 2001-2025 Bitrix
 */

IncludeModuleLangFile(__FILE__);

class CCheckListResult
{
	public static function Save($arFields = [])
	{
		global $DB;

		$arResult = [];
		if ($arFields["STATE"] && is_array($arFields["STATE"]))
		{
			$arFields["STATE"] = serialize($arFields["STATE"]);
		}
		else
		{
			$arResult["ERRORS"][] = GetMessage("ERROR_DATA_RECEIVED");
		}

		$currentState = false;
		if (!isset($arFields["REPORT"]) || !$arFields["REPORT"])
		{
			$arFields["REPORT"] = "N";
			$db_result = $DB->Query("SELECT ID FROM b_checklist WHERE REPORT <> 'Y'");
			$currentState = $db_result->Fetch();
		}
		else
		{
			$arFields["REPORT"] = "Y";
		}

		if (!empty($arResult["ERRORS"]))
		{
			return $arResult;
		}

		if ($currentState)
		{
			$strUpdate = $DB->PrepareUpdate("b_checklist", $arFields);
			$strSql = "UPDATE b_checklist SET " . $strUpdate . " WHERE ID=" . $currentState["ID"];
		}
		else
		{
			$arInsert = $DB->PrepareInsert("b_checklist", $arFields);
			$strSql = "INSERT INTO b_checklist(" . $arInsert[0] . ", DATE_CREATE) " .
				"VALUES(" . $arInsert[1] . ", '" . ConvertTimeStamp(time(), "FULL") . "')";
		}

		$arBinds = [
			"STATE" => $arFields["STATE"],
		];

		return $DB->QueryBind($strSql, $arBinds);
	}

	public static function GetList($arOrder = [], $arFilter = [])
	{
		global $DB;

		$arSqlWhereStr = '';
		if (is_array($arFilter) && !empty($arFilter))
		{
			$arSqlWhere = [];
			$arSqlFields = ["ID", "REPORT", "HIDDEN", "SENDED_TO_BITRIX"];
			foreach ($arFilter as $key => $value)
			{
				if (in_array($key, $arSqlFields))
				{
					$arSqlWhere[] = $key . "='" . $DB->ForSql($value) . "'";
				}
			}
			$arSqlWhereStr = GetFilterSqlSearch($arSqlWhere);
		}

		$strSql = "SELECT * FROM b_checklist";
		if ($arSqlWhereStr <> '')
		{
			$strSql .= " WHERE " . $arSqlWhereStr;
		}
		$strSql .= " ORDER BY ID desc";

		return $DB->Query($strSql);
	}

	public static function Update($ID, $arFields)
	{
		global $DB;
		$ID = intval($ID);

		$strUpdate = $DB->PrepareUpdate("b_checklist", $arFields);

		$strSql =
			"UPDATE b_checklist SET " . $strUpdate . " WHERE ID = " . $ID . " ";
		$DB->Query($strSql);
		return $ID;
	}

	public static function Delete($ID)
	{
		global $DB;
		$ID = intval($ID);
		if (!$ID > 0)
		{
			return false;
		}
		$strSql = "DELETE FROM b_checklist where ID=" . $ID;
		if ($DB->Query($strSql))
		{
			return true;
		}
		return false;
	}
}
